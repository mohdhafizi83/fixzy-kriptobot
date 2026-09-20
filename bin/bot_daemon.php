<?php
// Fail: bin/bot_daemon.php

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Fixzy\Kriptobot\Database\BotRepository;
use Fixzy\Kriptobot\Trading\ExchangeService;
use Fixzy\Kriptobot\Trading\OrderManager;
use Fixzy\Kriptobot\Trading\DcaConditionEngine;
use Fixzy\Kriptobot\Trading\BaseOrderConditionEngine;
// --- ADDED: CCXT exchange client ---
use Fixzy\Kriptobot\Intelligence\CrossVerifier;
use Fixzy\Kriptobot\Intelligence\AiAnalyzer;
use Fixzy\Kriptobot\Intelligence\Fetchers\CryptoPanicFetcher;
use Fixzy\Kriptobot\Intelligence\Fetchers\BlockonomiFetcher;
use Fixzy\Kriptobot\Trading\MarketScannerService;
use Fixzy\Kriptobot\Trading\TrailingEngine;
use Fixzy\Kriptobot\Trading\Rules\ExecutionGuardService;
use Fixzy\Kriptobot\Trading\Rules\MinimumProfitGuardRule;
use Fixzy\Kriptobot\Notifications\NotificationService;
use Fixzy\Kriptobot\Database\AuditLogger;
use Fixzy\Kriptobot\Database\Database;
use Fixzy\Kriptobot\Security\EncryptionService;
use Fixzy\Kriptobot\Config\Config;

// Load initial config
Config::load();

echo "=========================================================\n";
echo " 🚀 FIXZY KRIPTOBOT MASTER DAEMON (LIVE EXECUTION ENGINE)\n";
echo "=========================================================\n";

/**
 * Decrypt the API secret with backward compatibility.
 * Try AES decryption first. On failure, fall back to base64 decoding (legacy).
 */
$decryptApiSecret = function (string $encoded): string {
    if ($encoded === '') return '';
    $masterKey = Config::get('AES_MASTER_KEY');
    if ($masterKey === '') return trim(base64_decode($encoded));
    try {
        static $encryption = null;
        if ($encryption === null) {
            $encryption = new \Fixzy\Kriptobot\Security\EncryptionService($masterKey);
        }
        return $encryption->decrypt($encoded);
    } catch (\Exception $e) {
        return trim(base64_decode($encoded));
    }
};

$resolveCandles = function (array $candles, array $condition): array {
    $tf = $condition['timeframe'] ?? '1h';
    return isset($candles[$tf]) ? $candles[$tf] : $candles;
};

// Refresh AI market-analysis signals (BUY/SELL/EMPTY) for ai_market conditions.
// Signals are cached per symbol+timeframe in the bot runtime state (TTL 5 minutes)
// to avoid hammering the AI API. EMPTY = no conviction: dependent conditions do
// not pass and the bot waits.
$refreshAiMarketSignals = function (
    array $conditions,
    string $symbol,
    $exchange,
    string $aiApiKey,
    array $aiCfg,
    $auditLogger,
    $botId,
    $userId,
    &$state
): void {
    $timeframes = [];
    foreach ($conditions as $cond) {
        if (($cond['type'] ?? '') === 'ai_market') {
            $timeframes[$cond['timeframe'] ?? '1h'] = true;
        }
    }
    if (empty($timeframes)) {
        return;
    }

    $cache = $state['ai_market_cache'] ?? [];
    $signals = $state['ai_market_signals'] ?? [];

    foreach (array_keys($timeframes) as $tf) {
        $key = $symbol . ':' . $tf;
        $entry = $cache[$key] ?? null;
        if ($entry !== null && (time() - (int) ($entry['ts'] ?? 0)) < 300) {
            $signals[$tf] = $entry['signal'];
            continue; // Cached: reuse
        }

        $signal = 'EMPTY';
        $confidence = 0;
        $reason = '';
        try {
            $candles = $exchange->getHistoricalCandles($symbol, $tf, 100);
            $analyzer = new \Fixzy\Kriptobot\Intelligence\AiAnalyzer($aiApiKey, $aiCfg['base_url'], $aiCfg['model']);
            $result = $analyzer->analyzeMarket($symbol, $tf, $candles);
            $signal = $result['signal'];
            $confidence = $result['confidence'];
            $reason = $result['reason'];
        } catch (\Exception $e) {
            echo "      ⚠️ AI market analysis error ($tf for $symbol): " . $e->getMessage() . "\n";
            $reason = 'error: ' . $e->getMessage();
        }

        $cache[$key] = ['signal' => $signal, 'ts' => time(), 'confidence' => $confidence, 'reason' => $reason];
        $signals[$tf] = $signal;
        echo "      🤖 AI market [$tf] $symbol: [ $signal ] ({$confidence}% confident)" . ($reason !== '' ? " — $reason" : '') . "\n";
        $auditLogger->log($botId, $userId, 'AI_MARKET_ANALYSIS', "AI market [$tf]: $signal ({$confidence}%)", [
            'symbol'     => $symbol,
            'timeframe'  => $tf,
            'signal'     => $signal,
            'confidence' => $confidence,
            'reason'     => $reason,
        ]);
    }

    $state['ai_market_cache'] = $cache;
    $state['ai_market_signals'] = $signals;
};

$lockFile = __DIR__ . '/bot_daemon.lock';
$lockHandle = fopen($lockFile, "w+");
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "⚠️ [CRON] bot_daemon.php is still running from a previous process. Exiting to avoid overlap.\n";
    exit;
}

$conn = Database::getConnection();

$repo = new BotRepository(); 
$auditLogger = new AuditLogger($conn); 
$executionGuard = new ExecutionGuardService($conn);
$notifier = new NotificationService(Config::get('TELEGRAM_BOT_TOKEN'), Config::get('TELEGRAM_CHAT_ID'));

// --- FEATURE GATING: verify all integrations at startup ---------------------
// Policy: exchange API is COMPULSORY; AI / CryptoPanic / Telegram are OPTIONAL
// and stay disabled until configured AND verified with a live call.
$featureService = new \Fixzy\Kriptobot\Service\FeatureStatusService($conn);
try {
    $featureStatuses = $featureService->verifyAll(Database::USER_ID);
} catch (\Throwable $e) {
    $featureStatuses = $featureService->getAll();
}
echo "\n--- FEATURE STATUS ---\n";
foreach ($featureStatuses as $fs) {
    $icon = $fs['status'] === 'enabled' ? '✅' : ($fs['required'] ? '❌' : '⚠️ ');
    printf(
        "%s %-40s %s%s — %s\n",
        $icon,
        $fs['label'],
        strtoupper($fs['status']),
        $fs['required'] ? ' [COMPULSORY]' : ' [optional]',
        $fs['reason']
    );
}
echo "----------------------\n";

// Cooldown so disabled-feature notices don't spam every tick (1 per hour).
$featureNoticeCooldown = [];
$notifyFeatureDisabled = function (string $featureKey, string $context, int $botId = 0, int $userId = 0) use ($featureService, $notifier, $auditLogger): void {
    global $featureNoticeCooldown;
    $last = $featureNoticeCooldown[$featureKey] ?? 0;
    if (time() - $last < 3600) {
        return;
    }
    $featureNoticeCooldown[$featureKey] = time();
    $st  = $featureService->getStatus($featureKey);
    $msg = "🚫 Feature DISABLED: {$st['label']}\n"
         . "Reason: {$st['reason']}\n"
         . "Needed for: {$context}\n"
         . "This feature stays disabled until its credentials are added and verified in Settings → Features.";
    echo $msg . "\n";
    try {
        $auditLogger->log(
            $botId,
            $userId,
            'FEATURE_DISABLED',
            $msg,
            ['feature' => $featureKey, 'context' => $context]
        );
    } catch (\Throwable $e) {
        // audit must never break the loop
    }
    $notifier->sendTelegramAlert($msg);
};
// --------------------------------------------------------------------------

$loopInterval = 10;
$idleInterval = 60;     // when idle, tick every 60s to save resources
$tickCount = 1;

try {
while (true) {
    echo "\n[" . date('H:i:s') . "] --- TICK #$tickCount ---\n";

    $hasActiveDeal = false;

    try {
        // --- CENTRALIZED DATA HUB (BATCH FETCH TICKERS) ---
        // Fetch all coin prices in ONE API call and store them in RAM (APCu)
        if (function_exists('apcu_store')) {
            $uniqueExchanges = $conn->executeQuery("SELECT DISTINCT exchange_name FROM user_api_keys")->fetchAllAssociative();
            if (empty($uniqueExchanges)) $uniqueExchanges = [['exchange_name' => 'binance']];
            
            foreach ($uniqueExchanges as $ex) {
                $exName = strtolower($ex['exchange_name']);
                $hubCacheKey = 'kriptobot_hub_status_' . $exName;
                
                // If the cache has not expired (15s TTL), no need to call the API
                if (apcu_fetch($hubCacheKey) === false) {
                    echo "📡 [DATA HUB] Fetching thousands of live market prices from {$exName}...\n";
                    try {
                        $hubSync = new ExchangeService($exName, '', '', false);
                        $allTickers = $hubSync->getExchange()->fetch_tickers();
                        
                        foreach ($allTickers as $sym => $tick) {
                            if (isset($tick['last'])) {
                                apcu_store('kriptobot_price_' . $exName . '_' . $sym, (float)$tick['last'], 15);
                            }
                        }
                        apcu_store($hubCacheKey, true, 15);
                        echo "✅ [DATA HUB] " . count($allTickers) . " prices refreshed in APCu (RAM).\n";
                    } catch (Exception $e) {
                        echo "⚠️ [DATA HUB] Error fetching centralized prices: " . $e->getMessage() . "\n";
                    }
                }
            }
        }
        // ---------------------------------------------------
                // --- DAILY ROUTINE: REFRESH THE COIN PAIR LIST ---
        $activeDealsCount = (int)$conn->executeQuery("SELECT COUNT(*) FROM bots WHERE status = 1 AND runtime_state NOT LIKE '%\"status\":\"IDLE\"%'")->fetchOne();
        $allBotsIdle = ($activeDealsCount === 0);
        
        if ($allBotsIdle) {
            $pairsCacheFile = dirname(__DIR__) . '/database/cache/available_pairs.json';
            $pairsCacheTTL = 86400; // 24 jam
            
            if (!file_exists($pairsCacheFile) || (time() - filemtime($pairsCacheFile)) > $pairsCacheTTL) {
                echo "\n🔄 [DAILY MAINTENANCE] All bots IDLE. Refreshing the exchange trading-pair list...\n";
                // Find any live API key for general data pulls
                $keyData = $conn->executeQuery("SELECT exchange_name, api_key, api_secret_encrypted FROM user_api_keys LIMIT 1")->fetchAssociative();
                
                if (!$keyData) {
                    // Fall back to public Binance data if the user has not added an API key
                    $keyData = ['exchange_name' => 'binance', 'api_key' => '', 'api_secret_encrypted' => base64_encode('')];
                }
                
                try {
                    $exSync = new ExchangeService($keyData['exchange_name'], $keyData['api_key'], base64_decode($keyData['api_secret_encrypted']), false);
                    $markets = $exSync->getExchange()->fetch_markets();
                    $validPairs = [];
                    foreach ($markets as $m) {
                        // Filter coins with active USDT pairs
                        if (!empty($m['active']) && str_ends_with($m['symbol'], '/USDT')) {
                            $validPairs[] = $m['symbol'];
                        }
                    }
                    if (!empty($validPairs)) {
                        $cacheDir = dirname($pairsCacheFile);
                        if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);
                        file_put_contents($pairsCacheFile, json_encode($validPairs));
                        echo "✅ Saved " . count($validPairs) . " USDT coin pairs to cache.\n\n";
                    }
                } catch (Exception $e) {
                    echo "⚠️ Failed to update the daily coin pair list: " . $e->getMessage() . "\n\n";
                }
            }
        }

        // Build the list of users with an active Recovery Bot
        $recoveryUsers = [];
        $recoveryBotRows = $conn->executeQuery("SELECT user_id FROM bots WHERE status = 1 AND runtime_state LIKE '%\"is_recovery_bot\":true%'")->fetchAllAssociative();
        foreach ($recoveryBotRows as $row) {
            $recoveryUsers[$row['user_id']] = true;
        }

        $activeBots = $repo->getAllEnabledBots(); 

        foreach ($activeBots as $botData) {
            $botId = $botData['id'];
            $userId = $botData['user_id']; 
            $symbol = $botData['coin_pair'];
            $state = $botData['runtime_state'];
            $config = $botData['configuration'];
            
            $userHasRecovery = isset($recoveryUsers[$userId]);
            $isRecoveryBot = !empty($state['is_recovery_bot']);

            echo "Memproses Bot #$botId [$symbol] | Status: {$state['status']}\n";

            // Testnet is enforced by default; set BINANCE_TESTNET=0 in .env only
            // when live keys are configured and you accept real-money risk.
            $isTestnet = \Fixzy\Kriptobot\Config\Config::get('BINANCE_TESTNET', '1') === '1';
            $userData = $conn->executeQuery(
                "SELECT is_demo_mode, testnet_api_key, testnet_api_secret_encrypted, global_filters, smart_recovery_mode, telegram_chat_id FROM users WHERE id = ? LIMIT 1",
                [Database::USER_ID]
            )->fetchAssociative();

            if (!$userData) {
                echo "⚠️ No user record found. Skipping...\n";
                continue;
            }

            // Set the Telegram Chat ID specific to this user
            $notifier->setTelegramChatId($userData['telegram_chat_id'] ?? null);

            // Huraikan Global Filters
            $globalFilters = isset($userData['global_filters']) ? (json_decode($userData['global_filters'], true) ?: []) : [];
            $isGlobalFilterEnabled = !empty($globalFilters['is_enabled']);
            $isSmartRecoveryEnabled = (bool)($userData['smart_recovery_mode'] ?? 1);

            $aiCfg = Config::aiConfig(); // OpenAI-compatible provider: AI_API_KEY / AI_BASE_URL / AI_MODEL
            $aiApiKey = $aiCfg['api_key'];
            $cryptoPanicApiKey = Config::get('CRYPTOPANIC_API_KEY');
            // AI is only enabled when it has been CONFIGURED and VERIFIED working
            // (see FeatureStatusService). A key that fails verification keeps the
            // feature disabled with a visible reason.
            $aiEnabled = $featureService->isEnabled('ai');
            $crossVerifier = new CrossVerifier(7200); // News time window: 2 hours
            $crossVerifier->addFetcher(new CryptoPanicFetcher($cryptoPanicApiKey));
            $crossVerifier->addFetcher(new BlockonomiFetcher());
            // ... add SearloFetcher/Twitter once implemented ...

            try {
                if ($isTestnet) {
                    if (empty($userData['testnet_api_key'])) throw new Exception("Testnet key not configured.");
                    $key = trim($userData['testnet_api_key']);
                    $secret = trim($decryptApiSecret($userData['testnet_api_secret_encrypted']));
                    $exchangeName = 'binance'; // Testnet is always Binance for now
                } else {
                    $configuredExchange = $config['general']['exchange'] ?? '';
                    if (empty($configuredExchange)) throw new Exception("Exchange not set in bot configuration.");
                    
                    // Pull the specific key for the exchange set in Settings
                    $apiKeyData = $conn->executeQuery(
                        "SELECT api_key, api_secret_encrypted FROM user_api_keys WHERE user_id = ? AND exchange_name = ? LIMIT 1",
                        [$userId, $configuredExchange]
                    )->fetchAssociative();
                    
                    if (!$apiKeyData || empty($apiKeyData['api_key'])) {
                        throw new Exception("Live API key for exchange '".strtoupper($configuredExchange)."' not bound/empty.");
                    }
                    
                    $key = trim($apiKeyData['api_key']);
                    $secret = trim($decryptApiSecret($apiKeyData['api_secret_encrypted']));
                    $exchangeName = $configuredExchange;
                }

                $exchange = new ExchangeService($exchangeName, $key, $secret, $isTestnet); 
                $orderManager = new OrderManager($exchange);
                
                                // --- NEW ADDITION: CANDLE DATA PULL (MULTIPLE TIMEFRAMES) ---
                $candles = [];
                $requiredTimeframes = [];
                
                $allConfigConds = array_merge(
                    $config['base_order']['conditions'] ?? [],
                    $config['dca']['conditions'] ?? [],
                    $config['risk_management']['sell_conditions'] ?? [],
                    $globalFilters['base_buy'] ?? [],
                    $globalFilters['dca_buy'] ?? [],
                    $globalFilters['sell'] ?? []
                );

                $taIndicators = ['rsi', 'bollinger', 'qfl', 'rsi_14'];
                foreach ($allConfigConds as $cond) {
                    if (in_array($cond['type'], $taIndicators)) {
                        $tf = $cond['timeframe'] ?? '1h';
                        $requiredTimeframes[$tf] = true;
                    }
                }

                if (!empty($requiredTimeframes)) {
                    foreach (array_keys($requiredTimeframes) as $tf) {
                        try {
                            $candles[$tf] = $exchange->getHistoricalCandles($symbol, $tf, 100);
                        } catch (\Exception $e) {
                            echo "⚠️ Failed to fetch OHLCV data ($tf) for $symbol: " . $e->getMessage() . "\n";
                        }
                    }
                }
                // -------------------------------------------------------

            } catch (Exception $e) {
                echo "⚠️ Failed to connect to the API: " . $e->getMessage() . "\n";
                $notifyFeatureDisabled('exchange', 'Trading engine cannot place or manage orders (COMPULSORY feature)', $botId ?? 0, $userId ?? 0);
                continue; 
            }

            $readyToBuySymbol = null;
            $readyToBuyPrice = null;
            $isFromTrailing = false;

// ================================================================
                        // SITUATION D: TRAILING BUY TRACKER
            // ================================================================
            if (($state['status'] ?? '') === 'WAITING_TRAILING_BUY' && !empty($state['trailing_symbol'])) {
                $trailingSymbol = $state['trailing_symbol'];
                try {
                    $currentPrice = $exchange->getCurrentPrice($trailingSymbol);
                } catch (Exception $e) {
                    continue; // API error
                }
                
                $lowWatermark = $state['trailing_low_watermark'] ?? $currentPrice;
                $deviation = (float)($config['base_order']['trailing_deviation'] ?? 0.5);
                
                $trailingEngine = new \Fixzy\Kriptobot\Trading\TrailingEngine();
                $trailingResult = $trailingEngine->evaluateTrailingBuy($currentPrice, $lowWatermark, $deviation);
                
                if ($trailingResult['action'] === 'WAIT') {
                    if ($trailingResult['new_low'] < $lowWatermark) {
                        echo "      ⏬ Trailing Buy ($trailingSymbol): Price dropped to a new low ($" . $trailingResult['new_low'] . ").\n";
                    } else {
                        echo "      ⏳ Trailing Buy ($trailingSymbol): Waiting for a +$deviation% bounce from $" . $trailingResult['new_low'] . " (Current: $$currentPrice).\n";
                    }
                    $state['trailing_low_watermark'] = $trailingResult['new_low'];
                    $repo->updateRuntimeState($botId, $state);
                    continue; 
                }
                
                echo "      🎯 Trailing Buy triggered! Price bounced $deviation% off the low.\n";
                $readyToBuySymbol = $trailingSymbol;
                $readyToBuyPrice = $currentPrice;
                $isFromTrailing = true;
                
                // Cleanup Trailing States
                unset($state['trailing_symbol']);
                unset($state['trailing_low_watermark']);
            }

// ================================================================
                        // SITUATION C: LIMIT ORDER TRACKER (WAITING FOR ENTRY ORDER FILL)
            // ================================================================
            if (($state['status'] ?? '') === 'WAITING_LIMIT' && !empty($state['limit_order_id'])) {
                echo "      ⏳ Tracking Limit Order (ID: {$state['limit_order_id']}) for {$symbol}...\n";
                
                try {
                    $orderInfo = $exchange->getExchange()->fetch_order($state['limit_order_id'], $symbol);
                    
                    if ($orderInfo['status'] === 'closed') {
                        echo "      ✅ LIMIT ORDER FILLED! Starting ACTIVE mode.\n";
                        
                        $state['status'] = 'ACTIVE';
                        $state['average_entry_price'] = $orderInfo['average'] ?? $orderInfo['price'];
                        $state['base_order_price'] = $state['average_entry_price'];
                        $state['base_order_volume_usdt'] = $state['average_entry_price'] * $orderInfo['filled'];
                        $state['current_holdings'] = $orderInfo['filled'];
                        $state['trade_start_timestamp'] = time();
                        
                        // Buang cache Limit
                        unset($state['limit_order_id']);
                        $repo->updateRuntimeState($botId, $state);
                        
                        $notifier->sendTelegramAlert("🎯 <b>LIMIT BUY FILLED</b>\nPair: $symbol\nPrice: $" . round($state['average_entry_price'], 4) . "\nAmount Received: " . round($state['current_holdings'], 4));
                        
                        // Let the code flow into Situation A below since holdings > 0
                        
                    } elseif ($orderInfo['status'] === 'canceled' || $orderInfo['status'] === 'expired') {
                        echo "      ❌ LIMIT ORDER CANCELED OR EXPIRED. Returning bot to IDLE...\n";
                        $state['status'] = 'IDLE';
                        $state['current_holdings'] = 0.0;
                        $state['average_entry_price'] = 0.0;
                        unset($state['limit_order_id']);
                        
                        if (!empty($botData['parent_id'])) {
                            echo "      🧹 Removing child bot because the Limit Order failed.\n";
                            $conn->executeStatement("DELETE FROM bots WHERE id = ?", [$botId]);
                            continue;
                        } else {
                            $repo->updateRuntimeState($botId, $state);
                            continue;
                        }
                    } else {
                        echo "      ... Limit Order still open (PENDING). Executing Fallback to Market logic!\n";
                        try {
                            // 1. Batalkan pesanan limit
                            $exchange->getExchange()->cancel_order($state['limit_order_id'], $symbol);
                            echo "      ❌ Limit Order (ID: {$state['limit_order_id']}) force-canceled.\n";
                            
                            // 2. Determine the remaining USDT value not yet bought
                            $remainingUsdt = 0;
                            if (isset($orderInfo['remaining']) && $orderInfo['remaining'] > 0 && isset($orderInfo['price'])) {
                                $remainingUsdt = $orderInfo['remaining'] * $orderInfo['price'];
                            } else {
                                $remainingUsdt = $state['base_order_amount'] ?? 5.0;
                            }
                            
                            // 3. Save the coin value already purchased (if partially filled)
                            if (isset($orderInfo['filled']) && $orderInfo['filled'] > 0) {
                                $state['current_holdings'] = $orderInfo['filled'];
                                $state['average_entry_price'] = $orderInfo['average'] ?? $orderInfo['price'];
                            } else {
                                $state['current_holdings'] = 0.0;
                                $state['average_entry_price'] = 0.0;
                            }
                            
                            // 4. Execute a Market Buy for the remainder
                            if ($remainingUsdt > 1.0) {
                                echo "      🚀 Fallback: Executing Market Buy for remaining USDT " . round($remainingUsdt, 2) . "...\n";
                                $marketOrder = $orderManager->executeMarketBuy($symbol, $remainingUsdt);
                                
                                // Estimate the current price if missing from the response
                                $currentPriceFallback = $exchange->getCurrentPrice($symbol);
                                $execPrice = $marketOrder['price'] ?? $currentPriceFallback;
                                $execAmount = $marketOrder['amount'] ?? ($remainingUsdt / $execPrice);
                                
                                // Average with the limit portion (if there was a partial fill)
                                $totalInvested = ($state['current_holdings'] * $state['average_entry_price']) + ($execAmount * $execPrice);
                                $newHoldings = $state['current_holdings'] + $execAmount;

                                if ($newHoldings > 0) {
                                    $state['average_entry_price'] = $totalInvested / $newHoldings;
                                    $state['current_holdings'] = $newHoldings;
                                }
                            }
                            
                                                        // 5. Update status to ACTIVE
                            if ($state['current_holdings'] > 0) {
                                $state['status'] = 'ACTIVE';
                                $state['base_order_price'] = $state['average_entry_price'];
                                $state['base_order_volume_usdt'] = $state['average_entry_price'] * $state['current_holdings'];
                                $state['trade_start_timestamp'] = time();
                            }
                            unset($state['limit_order_id']);
                            
                            $repo->updateRuntimeState($botId, $state);
                            $notifier->sendTelegramAlert("🚀 <b>FALLBACK TO MARKET (BASE)</b>\nPair: $symbol\nLimit Order unfilled, replaced with Market Buy.\nAverage Price: $" . round($state['average_entry_price'], 4));
                            $auditLogger->log($botId, $userId, 'STATE_CHANGE', "Fallback to Market Buy for $symbol", [
                                'symbol'       => $symbol,
                                'entry_price'  => $state['average_entry_price'],
                                'holdings'     => $state['current_holdings'],
                                'reason'       => 'Limit order expired/canceled, filled by market',
                            ]);
                            continue;
                        } catch (Exception $e) {
                            echo "      ⚠️ Error during Fallback to Market: " . $e->getMessage() . "\n";
                            $notifier->sendTelegramAlert("❌ <b>FALLBACK TO MARKET ERROR</b>\nPair: $symbol\nError: " . $e->getMessage());
                            $auditLogger->log($botId, $userId, 'ERROR', "Fallback to Market error for $symbol: " . $e->getMessage(), [
                                'symbol'      => $symbol,
                                'error'       => $e->getMessage(),
                                'limit_order_id' => $state['limit_order_id'] ?? 'N/A',
                                'buy_type'    => 'FALLBACK_MARKET_BUY',
                                'trace'       => $e->getTraceAsString(),
                            ]);
                            continue;
                        }
                    }
                } catch (Exception $e) {
                    echo "      ⚠️ Error while checking Limit Order: " . $e->getMessage() . "\n";
                    $auditLogger->log($botId, $userId, 'ERROR', "Limit Order check error for $symbol: " . $e->getMessage(), [
                        'symbol'         => $symbol,
                        'error'          => $e->getMessage(),
                        'limit_order_id' => $state['limit_order_id'] ?? 'N/A',
                        'check_type'     => 'LIMIT_ORDER_CHECK',
                        'trace'          => $e->getTraceAsString(),
                    ]);
                    continue;
                }
            }

// ================================================================
// STATE MACHINE DISPATCH
// ================================================================
            
// Route IDLE bots to Situation B
if (($state['current_holdings'] ?? 0) <= 0) {
    goto SITUATION_B_IDLE;
}

            // ================================================================
                        // SITUATION A: BOT HOLDS A POSITION (ACTIVE DEAL / CURRENTLY TRADING)
            // ================================================================
            {
                $hasActiveDeal = true;
                
                $currentPrice = $exchange->getCurrentPrice($symbol);
                $entryPrice = $state['average_entry_price'];
                
                $pnlPercentage = (($currentPrice - $entryPrice) / $entryPrice) * 100;
                $state['live_pnl_percent'] = round($pnlPercentage, 2);
                $state['live_current_price'] = $currentPrice;

                // Log tick
                $conn->executeStatement(
                    "INSERT INTO price_ticks (bot_id, price, pnl_percent, holdings) VALUES (?, ?, ?, ?)",
                    [$botId, $currentPrice, round($pnlPercentage, 2), $state['current_holdings'] ?? 0]
                );
                // Keep only last 250 ticks  
                $conn->executeStatement("DELETE FROM price_ticks WHERE id NOT IN (SELECT id FROM price_ticks ORDER BY id DESC LIMIT 250)");
                
                echo " -> Current PNL: {$state['live_pnl_percent']}% | Price: $$currentPrice | Avg Entry: $$entryPrice\n";

                                // --- MINIMUM PROFIT GUARD SETUP (LEVEL 3) ---
                $isMinGuardEnabled = $config['risk_management']['min_guard_enabled'] ?? false;
                $minGuardPercent = $config['risk_management']['min_guard_percent'] ?? 1.0;
                $minGuardTimeoutHours = $config['risk_management']['min_guard_timeout'] ?? 48;
                $tradeStartTime = $state['trade_start_timestamp'] ?? time(); // Use current time if not set yet
                
                $minGuardRule = new MinimumProfitGuardRule(
                    $entryPrice, 
                    $currentPrice, 
                    $minGuardPercent, 
                    $isMinGuardEnabled, 
                    $tradeStartTime, 
                    $minGuardTimeoutHours
                );
                
                $isProfitGuardPassed = $minGuardRule->evaluate();

                // --- AI MARKET SIGNAL REFRESH (for ai_market conditions while holding) ---
                $aiMarketConds = array_merge(
                    $config['risk_management']['sell_conditions'] ?? [],
                    $config['dca']['conditions'] ?? [],
                    $isGlobalFilterEnabled ? ($globalFilters['sell'] ?? []) : [],
                    $isGlobalFilterEnabled ? ($globalFilters['dca_buy'] ?? []) : []
                );
                $hasAiMarketCond = false;
                foreach ($aiMarketConds as $c) {
                    if (($c['type'] ?? '') === 'ai_market') { $hasAiMarketCond = true; break; }
                }
                if ($aiEnabled) {
                    $refreshAiMarketSignals($aiMarketConds, $symbol, $exchange, $aiApiKey, $aiCfg, $auditLogger, $botId, $userId, $state);
                    $repo->updateRuntimeState($botId, $state); // Persist AI market cache/signals
                } elseif ($hasAiMarketCond) {
                    $notifyFeatureDisabled('ai', "AI market condition (ai_market) on $symbol — bot is WAITING, condition cannot pass", $botId, $userId);
                }

                // --- A1. CHECK GLOBAL SELL (MASTER OVERRIDE) ---
                if ($isGlobalFilterEnabled && !empty($globalFilters['sell'])) {
                    // Global filter handled by BaseOrderEngine (AND logic) with $candles injection
                    $globalSellEngine = new BaseOrderConditionEngine(['conditions' => $globalFilters['sell']], $state, $candles);
                    if ($globalSellEngine->evaluate()) {
                        echo "🚨 GLOBAL SELL OVERRIDE TRIGGERED! Executing forced Market Sell...\n";
                        
                        try {
                            $orderManager->executeMarketSell($symbol, $state['current_holdings']);
                            $notifier->sendTelegramAlert("🚨 <b>GLOBAL SELL OVERRIDE</b>\nPair: $symbol\nAll holdings force-sold by Global Sell rules.");

                            $auditLogger->logSell($botId, $userId, 'GLOBAL_SELL_OVERRIDE', [
                                'symbol'      => $symbol,
                                'sell_price'  => $currentPrice,
                                'coin_sold'   => $state['current_holdings'],
                                'pnl_percent' => $pnlPercentage,
                                'usdt_value'  => $currentPrice * $state['current_holdings'],
                                'reason'      => 'Global Sell rules met',
                                'status'      => 'SUCCESS',
                            ]);

                            $state['status'] = 'IDLE';
                            $state['current_holdings'] = 0.0;
                            $state['average_entry_price'] = 0.0;
                            $state['dca_current_step'] = 0;
                            $repo->updateRuntimeState($botId, $state);
                        } catch (Exception $e) {
                            echo "      ⚠️ Global Sell Override failed: " . $e->getMessage() . "\n";
                            $notifier->sendTelegramAlert("❌ <b>GLOBAL SELL OVERRIDE ERROR</b>\nPair: $symbol\nError: " . $e->getMessage());
                            $auditLogger->log($botId, $userId, 'ERROR', "Global Sell Override failed for $symbol: " . $e->getMessage(), [
                                'symbol'      => $symbol,
                                'error'       => $e->getMessage(),
                                'holdings'    => $state['current_holdings'],
                                'sell_type'   => 'GLOBAL_SELL_OVERRIDE',
                                'trace'       => $e->getTraceAsString(),
                            ]);
                        }
                        continue; 
                    }
                }

                                // --- A1.5 CHECK CUSTOM SELL CONDITIONS (DEDICATED BOT & GLOBAL - LOGICAL OR) ---
                $customSellConditions = $config['risk_management']['sell_conditions'] ?? [];
                
                if ($isGlobalFilterEnabled && !empty($globalFilters['sell'])) {
                    $customSellConditions = array_merge($customSellConditions, $globalFilters['sell']);
                }

                if (!empty($customSellConditions)) {
                    $sellTriggered = false;
                    $sellTriggerCondType = '';
                    foreach ($customSellConditions as $cond) {
                        $type = $cond['type'] ?? '';
                        $rule = null;

                        if ($type === 'rsi' || $type === 'rsi_14') {
                            $tfCandles = $resolveCandles($candles, $cond);
                            $period = (int)($cond['period'] ?? 14);
                            $rule = new \Fixzy\Kriptobot\Trading\Rules\RsiRule($cond['value'], $tfCandles, $period);
                        }
                        elseif ($type === 'bollinger') {
                            $tfCandles = $resolveCandles($candles, $cond);
                            $period = (int)($cond['period'] ?? 20);
                            $stddev = (float)($cond['stddev'] ?? 2.0);
                            $rule = new \Fixzy\Kriptobot\Trading\Rules\BollingerRule($cond['value'], $tfCandles, $period, $stddev);
                        }
                        elseif ($type === 'qfl') {
                            $tfCandles = $resolveCandles($candles, $cond);
                            $rule = new \Fixzy\Kriptobot\Trading\Rules\QflRule($cond['value'], $tfCandles);
                        }
                        elseif ($type === 'tv_webhook') {
                            $rule = new \Fixzy\Kriptobot\Trading\Rules\TradingViewRule($cond['value'], $state['latest_tv_signal'] ?? '');
                        }
                        elseif ($type === 'external_signal') {
                            $rule = new \Fixzy\Kriptobot\Trading\Rules\ExternalSignalRule($cond['value'], $state['latest_external_signal'] ?? '');
                        }
                        elseif ($type === 'news_sentiment') {
                            $rule = new \Fixzy\Kriptobot\Trading\Rules\SentimentRule($cond['value'], $state['latest_ai_sentiment'] ?? '');
                        }
                        elseif ($type === 'ai_market') {
                            $tf = $cond['timeframe'] ?? '1h';
                            $aiSig = strtoupper(trim((string) (($state['ai_market_signals'][$tf] ?? ''))));
                            $rule = new \Fixzy\Kriptobot\Trading\Rules\AiMarketRule($cond['value'], in_array($aiSig, ['BUY', 'SELL'], true) ? $aiSig : '');
                        }

                        if ($rule && $rule->evaluate()) {
                            $sellTriggered = true;
                            $sellTriggerCondType = $type;
                            echo " -> Custom Sell Condition ({$type}) met!\n";
                            break; 
                        }
                    }

                    if ($sellTriggered) {
                        if (!$isProfitGuardPassed) {
                            echo "      🛡️ CUSTOM SELL REJECTED: Minimum Profit Guard active. Profit has not reached $minGuardPercent% and the $minGuardTimeoutHours hour timeout has not elapsed.\n";
                        } else {
                            echo "      🚨 CUSTOM SELL TRIGGERED! Executing Market Sell...\n";
                            $orderManager->executeMarketSell($symbol, $state['current_holdings']);
                            $notifier->sendTelegramAlert("🚨 <b>CUSTOM SELL EXECUTED</b>\nPair: $symbol\nHoldings sold because Custom Sell conditions were met.\nCurrent PNL: $pnlPercentage%");
                            $auditLogger->logSell($botId, $userId, 'CUSTOM_SELL', [
                                'symbol'      => $symbol,
                                'sell_price'  => $currentPrice,
                                'coin_sold'   => $state['current_holdings'],
                                'pnl_percent' => $pnlPercentage,
                                'usdt_value'  => $currentPrice * $state['current_holdings'],
                                'reason'      => "Custom Sell condition ($sellTriggerCondType) met",
                                'status'      => 'SUCCESS',
                            ]);
                            $state['status'] = 'IDLE';
                            $state['current_holdings'] = 0.0;
                            $state['average_entry_price'] = 0.0;
                            $state['dca_current_step'] = 0;
                            $state['trade_start_timestamp'] = null; // Reset
                            $repo->updateRuntimeState($botId, $state);
                            continue;
                        }
                    }
                }

                // --- A2. CHECK NORMAL TAKE PROFIT & PARTIAL SELL (STAGE 3) ---
                $targetProfitSetting = $config['risk_management']['target_profit'] ?? 2.0;
                $tpType = $config['risk_management']['tp_type'] ?? 'average_price';
                
                                // --- "3COMMAS" LOGIC FOR TAKE PROFIT TYPE ---
                $totalUsdtVolume = $state['current_holdings'] * $state['average_entry_price'];
                $baseOrderUsdt = $state['base_order_volume_usdt'] ?? $state['allocated_capital_for_deal'] ?? $totalUsdtVolume;
                
                $dynamicTargetProfit = $targetProfitSetting;
                if ($tpType === 'base_order' && $totalUsdtVolume > 0) {
                    // Scale the profit target by the base-order capital to total position ratio
                    $dynamicTargetProfit = $targetProfitSetting * ($baseOrderUsdt / $totalUsdtVolume);
                }

                $isPartialEnabled = $config['risk_management']['partial_sell_enabled'] ?? false;

                if ($isPartialEnabled && !empty($config['risk_management']['partial_targets'])) {
                    $partialTargetsStr = $config['risk_management']['partial_targets'];
                    $targets = array_map('trim', explode(',', $partialTargetsStr));
                    
                    $executedTargets = $state['partial_sell_executed'] ?? [];
                    
                    foreach ($targets as $index => $targetPct) {
                        $targetPct = (float)$targetPct;
                        if ($pnlPercentage >= $targetPct && !in_array($index, $executedTargets)) {
                            // Sell this fraction!
                            $fractionToSell = $state['current_holdings'] / (count($targets) - count($executedTargets));
                            
                            echo "      ✂️ PARTIAL SELL (Target $targetPct%) REACHED! Selling " . round($fractionToSell, 4) . " coins...\n";
                            try {
                                $orderManager->executeMarketSell($symbol, $fractionToSell);
                                
                                $auditLogger->logSell($botId, $userId, 'PARTIAL_SELL', [
                                    'symbol'      => $symbol,
                                    'sell_price'  => $currentPrice,
                                    'coin_sold'   => $fractionToSell,
                                    'pnl_percent' => $targetPct,
                                    'usdt_value'  => $currentPrice * $fractionToSell,
                                    'reason'      => "Partial Sell target {$targetPct}% tercapai",
                                    'status'      => 'SUCCESS',
                                ]);
                                
                                $state['current_holdings'] -= $fractionToSell;
                                $executedTargets[] = $index;
                                $state['partial_sell_executed'] = $executedTargets;
                                
                                if (count($executedTargets) >= count($targets)) {
                                                                        // Everything already sold
                                    $state['status'] = 'IDLE';
                                    $state['average_entry_price'] = 0.0;
                                    $state['dca_current_step'] = 0;
                                    $state['trade_start_timestamp'] = null;
                                    $state['partial_sell_executed'] = [];
                                    echo "      ✅ All fractions sold successfully.\n";
                                }
                                $repo->updateRuntimeState($botId, $state);
                            } catch (Exception $e) {
                                echo "      ⚠️ Partial Sell failed: " . $e->getMessage() . "\n";
                                $notifier->sendTelegramAlert("❌ <b>PARTIAL SELL ERROR</b>\nPair: $symbol\nError: " . $e->getMessage());
                                $auditLogger->log($botId, $userId, 'ERROR', "Partial Sell failed for $symbol: " . $e->getMessage(), [
                                    'symbol'       => $symbol,
                                    'error'        => $e->getMessage(),
                                    'sell_target'  => $targetPct . '%',
                                    'holdings'     => $state['current_holdings'],
                                    'sell_type'    => 'PARTIAL_SELL',
                                    'trace'        => $e->getTraceAsString(),
                                ]);
                            }
                            break; // Execute only one level per cycle
                        }
                    }
                } 
                elseif ($pnlPercentage >= $dynamicTargetProfit) {
                    
                                        // --- TRAILING TAKE PROFIT CHECK ---
                    $isTrailingTpEnabled = $config['risk_management']['trailing_tp_enabled'] ?? false;
                    
                    if ($isTrailingTpEnabled) {
                        $trailingEngine = new TrailingEngine();
                        $highWatermark = $state['trailing_tp_watermark'] ?? $currentPrice;
                        $deviation = $config['risk_management']['trailing_tp_deviation'] ?? 0.2;
                        
                        $trailingResult = $trailingEngine->evaluateTrailingSell($currentPrice, $highWatermark, $deviation);
                        $state['trailing_tp_watermark'] = $trailingResult['new_high'];
                        
                        if ($trailingResult['action'] === 'WAIT') {
                            echo "      📈 Trailing TP active: profit $pnlPercentage%. Waiting for price to drop from peak $" . $trailingResult['new_high'] . "...\n";
                            $repo->updateRuntimeState($botId, $state);
                            continue;
                        }
                        
                        echo "      💰 Trailing TP triggered! Price fell $deviation% from peak $" . $trailingResult['new_high'] . ".\n";
                        $state['trailing_tp_watermark'] = 0.0; // Reset
                    }
                    
                    echo "💰 TAKE PROFIT REACHED ($pnlPercentage%)! Executing Market Sell...\n";
                    
                    try {
                        $soldAmount = $state['current_holdings'];
                        $orderManager->executeMarketSell($symbol, $soldAmount);

                        $auditLogger->logSell($botId, $userId, 'TAKE_PROFIT', [
                            'symbol'      => $symbol,
                            'sell_price'  => $currentPrice,
                            'coin_sold'   => $soldAmount,
                            'pnl_percent' => $pnlPercentage,
                            'usdt_value'  => $currentPrice * $soldAmount,
                            'reason'      => "Take Profit {$dynamicTargetProfit}% tercapai (PNL: {$pnlPercentage}%)",
                            'status'      => 'SUCCESS',
                        ]);

                                                // REST THE BOT (RETURN TO IDLE OR DELETE THE CLONE)
                        $state['status'] = 'IDLE';
                        $state['current_holdings'] = 0.0;
                        $state['average_entry_price'] = 0.0;
                        $state['base_order_price'] = 0.0;
                        $state['dca_current_step'] = 0;
                        $state['partial_sell_executed'] = [];
                        
                        // If this is a child bot (Clone Composite Bot), delete it once its task is done!
                        if (!empty($botData['parent_id'])) {
                            echo "      🧹 Removing child bot (Clone ID: $botId) because the $symbol task is complete.\n";
                            $conn->executeStatement("DELETE FROM bots WHERE id = ?", [$botId]);
                            continue;
                        } else {
                            if ($userHasRecovery && !$isRecoveryBot) {
                                echo "      🛑 Bot finished its Take Profit. Shutting the bot down (Graceful Shutdown) because Recovery Mode is active.\n";
                                $conn->executeStatement("UPDATE bots SET status = 0 WHERE id = ?", [$botId]);
                            }
                        }
                        
                                                // --- UNIVERSAL SMART RECOVERY CHECK ---
                        if (!empty($state['is_recovery_bot'])) {
                            $profitMade = ($currentPrice - $entryPrice) * $soldAmount;
                            $state['recovered_so_far'] = ($state['recovered_so_far'] ?? 0) + $profitMade;
                            $remainingDeficit = $state['deficit_to_recover'] - $state['recovered_so_far'];
                            
                            if ($remainingDeficit <= 0) {
                                echo "      🎉 RECOVERY COMPLETE! The deficit has been fully recovered.\n";
                                
                                // Re-enable all bots that were shut down earlier
                                $conn->executeStatement("UPDATE bots SET status = 1 WHERE user_id = ? AND id != ?", [$userId, $botId]);

                                $notifier->sendTelegramAlert("🎉 <b>RECOVERY COMPLETE</b>\nThe deficit has been fully recovered. This dedicated bot will shut down and the other bots will be re-enabled.");
                                $conn->executeStatement("DELETE FROM bots WHERE id = ?", [$botId]);
                                continue;
                            } else {
                                echo "      🔄 RECOVERY IN PROGRESS. Remaining deficit to recover: $" . round($remainingDeficit, 2) . "\n";
                                $notifier->sendTelegramAlert("🔄 <b>RECOVERY PROGRESS</b>\nBot made a profit. Remaining deficit: $" . round($remainingDeficit, 2));
                            }
                        }
                        $state['latest_ai_sentiment'] = ''; 
                        $state['trade_start_timestamp'] = null; 
                        $repo->updateRuntimeState($botId, $state);
                        
                        $notifier->sendTelegramAlert("💰 <b>TAKE PROFIT COMPLETE</b>\nPair: $symbol\nProfit: $pnlPercentage%");
                        continue; 
                    } catch (Exception $e) {
                        echo "⚠️ Take Profit failed: " . $e->getMessage() . "\n";
                        $notifier->sendTelegramAlert("❌ <b>TAKE PROFIT ERROR</b>\nPair: $symbol\nError: " . $e->getMessage());
                        $auditLogger->log($botId, $userId, 'ERROR', "Take Profit failed for $symbol: " . $e->getMessage(), [
                            'symbol'      => $symbol,
                            'error'       => $e->getMessage(),
                            'pnl_percent' => $pnlPercentage,
                            'holdings'    => $state['current_holdings'],
                            'sell_type'   => 'TAKE_PROFIT',
                            'trace'       => $e->getTraceAsString(),
                        ]);
                    }
                }

                // --- A2.5 CHECK HARD CUT LOSS & TRAILING STOP LOSS ---
                $isCutLossEnabled = $config['risk_management']['cut_loss_enabled'] ?? false;
                $cutLossPercent = $config['risk_management']['cut_loss_percent'] ?? 5.0;
                $isTrailingSlEnabled = $config['risk_management']['trailing_sl_enabled'] ?? false;

                $triggerCutLoss = false;
                $cutLossReason = "";

                if ($isCutLossEnabled) {
                    if ($isTrailingSlEnabled) {
                        $highWatermark = $state['trailing_sl_watermark'] ?? $entryPrice;
                        
                                                // Update the highest peak
                        if ($currentPrice > $highWatermark) {
                            $state['trailing_sl_watermark'] = $currentPrice;
                            $repo->updateRuntimeState($botId, $state);
                            $highWatermark = $currentPrice;
                        }
                        
                        $dynamicSlPrice = $highWatermark * (1 - ($cutLossPercent / 100));
                        if ($currentPrice <= $dynamicSlPrice) {
                            $triggerCutLoss = true;
                            $cutLossReason = "Trailing SL: Fell $cutLossPercent% from peak $" . round($highWatermark, 4);
                        }
                    } else {
                        // Hard Cut Loss biasa
                        if ($pnlPercentage <= -$cutLossPercent) {
                            $triggerCutLoss = true;
                            $cutLossReason = "Hard Cut Loss: PNL $pnlPercentage% <= -$cutLossPercent%";
                        }
                    }
                }

                if ($triggerCutLoss) {
                    echo "🛑 CUT LOSS TRIGGERED! $cutLossReason...\n";
                    
                    try {
                        $lostUsdt = ($state['average_entry_price'] - $currentPrice) * $state['current_holdings'];
                        $remainingUsdt = $currentPrice * $state['current_holdings'];
                        
                        $orderManager->executeMarketSell($symbol, $state['current_holdings']);
                        
                        $auditLogger->logSell($botId, $userId, 'CUT_LOSS', [
                            'symbol'      => $symbol,
                            'sell_price'  => $currentPrice,
                            'coin_sold'   => $state['current_holdings'],
                            'pnl_percent' => $pnlPercentage,
                            'usdt_value'  => $remainingUsdt,
                            'loss_usdt'   => $lostUsdt,
                            'reason'      => $cutLossReason,
                            'status'      => 'SUCCESS',
                        ]);
                        
                        $state['status'] = 'IDLE';
                        $state['current_holdings'] = 0.0;
                        $state['average_entry_price'] = 0.0;
                        $state['base_order_price'] = 0.0;
                        $state['dca_current_step'] = 0;
                        $state['trade_start_timestamp'] = null;
                        unset($state['trailing_sl_watermark']);
                        
                        if (!empty($botData['parent_id'])) {
                            echo "      🧹 Removing child bot due to Cut Loss.\n";
                            $conn->executeStatement("DELETE FROM bots WHERE id = ?", [$botId]);
                        } else {
                            // If this is the original bot, switch to IDLE but don't update state yet — we may want to rest it
                            $repo->updateRuntimeState($botId, $state);
                        }
                        
                        // --- UNIVERSAL SMART RECOVERY INITIATION (OPTION 3) ---
                        if ($lostUsdt > 0 && empty($state['is_recovery_bot']) && $isSmartRecoveryEnabled) {
                            
                            if ($userHasRecovery) {
                                echo "      🔄 Additional Cut Loss detected! Merging the $" . round($lostUsdt, 2) . " deficit into the existing Recovery Bot...\n";
                                
                                $existingRecovery = $conn->executeQuery(
                                    "SELECT id, allocated_capital, configuration, runtime_state FROM bots WHERE user_id = ? AND status = 1 AND runtime_state LIKE '%\"is_recovery_bot\":true%' LIMIT 1",
                                    [$userId]
                                )->fetchAssociative();
                                
                                if ($existingRecovery) {
                                    $recState = json_decode($existingRecovery['runtime_state'], true);
                                    $recConfig = json_decode($existingRecovery['configuration'], true);
                                    if (!$recState || !$recConfig) {
                                        echo "      ⚠️ Failed to parse the existing Recovery Bot JSON. Skipping the merge.\n";
                                        continue;
                                    }
                                    $recState['deficit_to_recover'] += $lostUsdt;
                                    $newCapital = $existingRecovery['allocated_capital'] + $remainingUsdt;
                                    $currentPairs = array_map('trim', explode(',', $recConfig['general']['custom_pairs'] ?? ''));
                                    // Hilangkan elemen kosong
                                    $currentPairs = array_filter($currentPairs);
                                    
                                    if (!in_array($symbol, $currentPairs)) {
                                        $currentPairs[] = $symbol;
                                        $recConfig['general']['custom_pairs'] = implode(',', $currentPairs);
                                    }
                                    
                                    $conn->executeStatement(
                                        "UPDATE bots SET allocated_capital = ?, configuration = ?, runtime_state = ? WHERE id = ?",
                                        [$newCapital, json_encode($recConfig), json_encode($recState), $existingRecovery['id']]
                                    );
                                    
                                    $notifier->sendTelegramAlert("⚠️ <b>RECOVERY DEFICIT ADDED</b>\nPair: $symbol\nAdditional deficit: $" . round($lostUsdt, 2) . "\nTarget coin list: " . implode(', ', $currentPairs) . "\nCurrent total deficit: $" . round($recState['deficit_to_recover'] - $recState['recovered_so_far'], 2));
                                }
                            } else {
                                echo "      🔄 Building a dedicated Recovery Bot to absorb the $" . round($lostUsdt, 2) . " loss...\n";
                                
                                // Shut down only the IDLE bots (Graceful Shutdown). Active bots continue Take Profit/DCA.
                                $conn->executeStatement(
                                    "UPDATE bots SET status = 0 WHERE user_id = ? AND (runtime_state LIKE '%\"status\":\"IDLE\"%' OR runtime_state IS NULL)",
                                    [$userId]
                                );
                                
                                // Refresh cache global recovery
                                $recoveryUsers[$userId] = true;
                                
                                // Konfigurasi Konservatif & Agresif (Pilihan 3)
                                $recoveryConfig = [
                                    'general' => [
                                        'name' => 'RECOVERY MODE',
                                        'exchange' => $config['general']['exchange'] ?? 'binance',
                                        'pair_strategy' => 'custom_list',
                                        'custom_pairs' => $symbol,
                                                                                    'capital' => $remainingUsdt > 10 ? $remainingUsdt : 15, // Minimum $15
                                        'max_active_deals' => 1
                                    ],
                                    'base_order' => [
                                        'order_type' => 'market',
                                        'conditions' => [
                                                                                        ['type' => 'rsi_14', 'value' => '< 30'], // Very conservative
                                            ['type' => 'bollinger', 'value' => 'LOWER']
                                        ],
                                        'trailing_enabled' => true,
                                        'trailing_deviation' => 0.5
                                    ],
                                    'dca' => [
                                        'max_steps' => 5,
                                        'price_drop_trigger' => 2.0, // Ketatkan jarak
                                        'volume_scale' => 1.5, // Agresif volum
                                        'step_scale' => 1.0,
                                        'placed_on_exchange' => false,
                                        'custom_conditions_enabled' => true,
                                        'conditions' => [
                                            ['type' => 'rsi_14', 'value' => '< 30']
                                        ],
                                        'trailing_enabled' => true,
                                        'trailing_deviation' => 0.3
                                    ],
                                    'risk_management' => [
                                        'tp_type' => 'average_price',
                                                                                'target_profit' => 1.0, // Smaller profit per session
                                        'trailing_tp_enabled' => false, // Cut profit immediately
                                                                                'cut_loss_enabled' => false, // Disable Cut Loss during Recovery!
                                        'min_guard_enabled' => true,
                                        'min_guard_percent' => 0.5,
                                        'sell_conditions' => []
                                    ]
                                ];
                                
                                $recoveryState = [
                                    'status' => 'IDLE',
                                    'is_recovery_bot' => true,
                                    'deficit_to_recover' => $lostUsdt,
                                    'recovered_so_far' => 0.0,
                                    'dca_current_step' => 0
                                ];
                                
                                $conn->executeStatement(
                                    "INSERT INTO bots (user_id, coin_pair, allocated_capital, status, configuration, runtime_state) VALUES (?, ?, ?, ?, ?, ?)",
                                    [$userId, $symbol, $recoveryConfig['general']['capital'], 1, json_encode($recoveryConfig), json_encode($recoveryState)]
                                );
                                
                                $notifier->sendTelegramAlert("⚠️ <b>GLOBAL RECOVERY MODE ACTIVE</b>\nPair: $symbol\nAll other bots temporarily shut down.\nThe system is working to recover the $" . round($lostUsdt, 2) . " loss in stages.");
                            }
                        }
                        
                        continue;
                    } catch (Exception $e) {
                        echo "⚠️ Cut Loss failed: " . $e->getMessage() . "\n";
                        $notifier->sendTelegramAlert("❌ <b>CUT LOSS ERROR</b>\nPair: $symbol\nError: " . $e->getMessage());
                        $auditLogger->log($botId, $userId, 'ERROR', "Cut Loss failed for $symbol: " . $e->getMessage(), [
                            'symbol'      => $symbol,
                            'error'       => $e->getMessage(),
                            'pnl_percent' => $pnlPercentage,
                            'holdings'    => $state['current_holdings'],
                            'reason'      => $cutLossReason,
                            'sell_type'   => 'CUT_LOSS',
                            'trace'       => $e->getTraceAsString(),
                        ]);
                    }
                }

                                // --- A2.7 CHECK LIMIT DCA STATUS (IF ANY) ---
                if (!empty($state['dca_limit_order_id'])) {
                    echo "      ⏳ Tracking Limit DCA (ID: {$state['dca_limit_order_id']}) for {$symbol}...\n";
                    try {
                        $orderInfo = $exchange->getExchange()->fetch_order($state['dca_limit_order_id'], $symbol);
                        
                        if ($orderInfo['status'] === 'closed') {
                            echo "      ✅ LIMIT DCA FILLED!\n";
                            
                            $executePrice = $orderInfo['average'] ?? $orderInfo['price'];
                            $executeAmount = $orderInfo['filled'];
                            $capitalToUse = $executePrice * $executeAmount;
                            
                            $totalInvested = ($state['current_holdings'] * $state['average_entry_price']) + ($capitalToUse);
                            $newHoldings = $state['current_holdings'] + $executeAmount;

                            if ($newHoldings > 0) {
                                $state['average_entry_price'] = $totalInvested / $newHoldings;
                                $state['current_holdings'] = $newHoldings;
                                $currentDcaStep = $state['dca_current_step'] ?? 0;
                                $state['dca_current_step'] = $currentDcaStep + 1;
                                unset($state['dca_limit_order_id']);
                                $repo->updateRuntimeState($botId, $state);

                                $auditLogger->logTrade($botId, $userId, [
                                    'bot_id'        => $botId,
                                    'action'        => 'DCA_BUY_LIMIT',
                                    'symbol'        => $symbol,
                                    'execute_price' => $executePrice,
                                    'coin_amount'   => $executeAmount,
                                    'usd_spent'     => $capitalToUse,
                                    'dca_step'      => $currentDcaStep + 1,
                                    'status'        => 'SUCCESS',
                                ]);

                                $notifier->sendTelegramAlert("🎯 <b>LIMIT DCA STEP " . ($currentDcaStep + 1) . " FILLED</b>\nPair: $symbol\nPrice: $" . round($executePrice, 4) . "\nNew Amount: " . round($state['current_holdings'], 4));
                                continue;
                            }
                        } elseif ($orderInfo['status'] === 'canceled' || $orderInfo['status'] === 'expired') {
                            echo "      ❌ LIMIT DCA CANCELLED. Removing the ID from the record so it can be redeployed...\n";
                            unset($state['dca_limit_order_id']);
                            $repo->updateRuntimeState($botId, $state);
                        } else {
                            // Still PENDING. Save rate limit and skip other DCA checks.
                            continue;
                        }
                    } catch (Exception $e) {
                         echo "      ⚠️ Limit DCA check error: " . $e->getMessage() . "\n";
                         $auditLogger->log($botId, $userId, 'ERROR', "Limit DCA check error for $symbol: " . $e->getMessage(), [
                             'symbol'            => $symbol,
                             'error'             => $e->getMessage(),
                             'dca_limit_order_id' => $state['dca_limit_order_id'] ?? 'N/A',
                             'check_type'        => 'DCA_LIMIT_ORDER_CHECK',
                             'trace'             => $e->getTraceAsString(),
                         ]);
                    }
                }

                // --- A3. CHECK GLOBAL DCA & CONDITIONAL DCA ---
                $currentDcaStep = $state['dca_current_step'] ?? 0;
                $maxDcaSteps = $config['dca']['max_steps'] ?? 0;

                if ($currentDcaStep < $maxDcaSteps && empty($state['dca_limit_order_id'])) {
                    
                    $isLimitDca = ($config['dca']['placed_on_exchange'] ?? 'no') === 'yes';
                    
                                        // --- BUILD LIMIT ORDER IN ADVANCE (IF PLACED_ON_EXCHANGE = YES) ---
                    if ($isLimitDca) {
                        $baseDrop = $config['dca']['price_drop_trigger'] ?? 2.0;
                        $stepScale = $config['dca']['step_scale'] ?? 1.0;
                        
                        $requiredDropPercent = 0.0;
                        for ($i = 0; $i <= $currentDcaStep; $i++) {
                            $requiredDropPercent += $baseDrop * pow($stepScale, $i);
                        }
                        
                        $targetPrice = ($state['base_order_price'] ?? $entryPrice) * (1 - ($requiredDropPercent / 100));
                        
                        $baseOrderAmount = $state['base_order_amount'] ?? 5.0;
                        $volumeScale = $config['dca']['volume_scale'] ?? 1.0;
                        $capitalToUse = $baseOrderAmount * pow($volumeScale, $currentDcaStep + 1);
                        
                        echo "      🕸️ Casting Limit DCA net Step " . ($currentDcaStep + 1) . " at price $" . round($targetPrice, 4) . " (in advance)...\n";
                        try {
                            $buyOrder = $orderManager->executeLimitBuy($symbol, $capitalToUse, $targetPrice);
                            if (isset($buyOrder['exchange_res']['id'])) {
                                $state['dca_limit_order_id'] = $buyOrder['exchange_res']['id'];
                                $repo->updateRuntimeState($botId, $state);
                                echo "      ⏳ Limit DCA placed on the exchange. Bot switched to tracking mode.\n";
                                continue;
                            }
                        } catch (Exception $e) {
                            echo "      ⚠️ Failed to place Limit DCA net: " . $e->getMessage() . "\n";
                            $notifier->sendTelegramAlert("❌ <b>LIMIT DCA PLACEMENT ERROR</b>\nPair: $symbol\nStep: " . ($currentDcaStep + 1) . "\nError: " . $e->getMessage());
                            $auditLogger->log($botId, $userId, 'ERROR', "Failed to place Limit DCA Step " . ($currentDcaStep + 1) . " for $symbol: " . $e->getMessage(), [
                                'symbol'      => $symbol,
                                'error'       => $e->getMessage(),
                                'dca_step'    => $currentDcaStep + 1,
                                'target_price' => $targetPrice,
                                'buy_type'    => 'DCA_BUY_LIMIT_PLACEMENT',
                                'trace'       => $e->getTraceAsString(),
                            ]);
                        }
                    } 
                    // --- MARKET DCA LIVE TRACKING (CUSTOM CONDITIONS / TRAILING) ---
                    else {
                                                // CHECK THE GLOBAL DCA FILTER FIRST
                        $isGlobalDcaAllowed = true;
                        if ($isGlobalFilterEnabled && !empty($globalFilters['dca_buy'])) {
                            $globalDcaEngine = new BaseOrderConditionEngine(['conditions' => $globalFilters['dca_buy']], $state, $candles);
                            if (!$globalDcaEngine->evaluate()) {
                                $isGlobalDcaAllowed = false;
                            }
                        }
    
                        if (!$isGlobalDcaAllowed) {
                            echo " -> ⛔ Blocked by Global DCA Filter! Waiting for global conditions to pass...\n";
                        } else {
                                                        // ONLY CHECK THE PERSONAL BOT'S DCA IF THE GLOBAL FILTER PASSED
                            $latestWebhook = $state['latest_tv_signal'] ?? '';
                            $latestAiSentiment = $state['latest_ai_sentiment'] ?? '';
    
                            $dcaEngine = new DcaConditionEngine($config['dca'], $entryPrice, $currentPrice, $currentDcaStep, $latestWebhook, $latestAiSentiment, $candles, $state['ai_market_signals'] ?? []);
    
                            if ($dcaEngine->evaluate()) {
                                
                                $dcaEvalDetails = $dcaEngine->getLastEvaluationDetails();
                                $dcaRuleResults = $dcaEngine->getLastRuleResults();
                                
                                if (!empty($dcaRuleResults)) {
                                    $auditLogger->logRuleEval($botId, $userId, 'DCA Step ' . ($currentDcaStep + 1), $dcaRuleResults);
                                }
                                
                                $auditLogger->log($botId, $userId, 'DCA_EVAL', "DCA Step " . ($currentDcaStep + 1) . " passed", [
                                    'dca_step'      => $currentDcaStep + 1,
                                    'price_drop'    => $dcaEvalDetails,
                                    'rule_results'  => $dcaRuleResults,
                                ]);
                                
                                                                // --- TRAILING DCA CHECK (LEVEL 4) ---
                                $isTrailingDca = !empty($config['dca']['trailing_enabled']);
                                
                                if ($isTrailingDca) {
                                    $trailingEngine = new TrailingEngine();
                                    $lowWatermark = $state['trailing_dca_watermark'] ?? 0.0;
                                    $deviation = $config['dca']['trailing_deviation'] ?? 0.5;
                                    
                                    $trailingResult = $trailingEngine->evaluateTrailingBuy($currentPrice, $lowWatermark, $deviation);
                                    $state['trailing_dca_watermark'] = $trailingResult['new_low'];
                                    
                                    if ($trailingResult['action'] === 'WAIT') {
                                        echo "      📉 Trailing DCA active: waiting for price to bounce... Low Watermark: $" . $trailingResult['new_low'] . "\n";
                                        $repo->updateRuntimeState($botId, $state);
                                        continue; 
                                    }
                                    
                                    echo "      🎯 Trailing DCA triggered! Price bounced $deviation% from $" . $trailingResult['new_low'] . ".\n";
                                    $state['trailing_dca_watermark'] = 0.0; // Reset
                                }
    
                                echo "🔥 DCA FILTER PASSED! Executing DCA Step " . ($currentDcaStep + 1) . " (Live Market)...\n";
                                
                                $baseOrderAmount = $state['base_order_amount'] ?? 5.0;
                                $volumeScale = $config['dca']['volume_scale'] ?? 1.0;
                                
                                $capitalToUse = $baseOrderAmount * pow($volumeScale, $currentDcaStep + 1);
                                echo " -> Buying assets worth $" . round($capitalToUse, 2) . "...\n";
                                
                                try {
                                    $buyOrder = $orderManager->executeMarketBuy($symbol, $capitalToUse);
                                    
                                    $executePrice = $buyOrder['price'] ?? $currentPrice;
                                    $executeAmount = $buyOrder['amount'] ?? ($capitalToUse / $executePrice);
                                    
                                                                        // Average price calculation
                                    $totalInvested = ($state['current_holdings'] * $state['average_entry_price']) + ($executeAmount * $executePrice);
                                    $newHoldings = $state['current_holdings'] + $executeAmount;

                                    if ($newHoldings > 0) {
                                        $state['average_entry_price'] = $totalInvested / $newHoldings;
                                        $state['current_holdings'] = $newHoldings;
                                        $state['dca_current_step'] = $currentDcaStep + 1;
                                        $state['latest_tv_signal'] = '';
                                        $state['latest_ai_sentiment'] = '';

                                        $auditLogger->logTrade($botId, $userId, [
                                            'bot_id'    => $botId,
                                            'action' => 'DCA_BUY_MARKET',
                                            'symbol' => $symbol,
                                            'execute_price' => $executePrice,
                                            'coin_amount' => $executeAmount,
                                            'usd_spent' => $capitalToUse,
                                            'status' => $buyOrder['status'] ?? 'SUCCESS'
                                        ]);

                                        $notifier->sendTelegramAlert("🔥 <b>DCA STEP " . ($currentDcaStep + 1) . " EXECUTED</b>\nPair: $symbol\nPrice: $" . round($executePrice, 4) . "\nNew Amount: $newHoldings");

                                        echo "✅ DCA succeeded. New average price: $" . $state['average_entry_price'] . ".\n";
                                    }
                                } catch (Exception $e) {
                                    echo "⚠️ Failed to execute DCA Market: " . $e->getMessage() . "\n";
                                    $notifier->sendTelegramAlert("❌ <b>DCA MARKET EXECUTION ERROR</b>\nPair: $symbol\nStep: " . ($currentDcaStep + 1) . "\nError: " . $e->getMessage());
                                    $auditLogger->log($botId, $userId, 'ERROR', "DCA Market Step " . ($currentDcaStep + 1) . " failed for $symbol: " . $e->getMessage(), [
                                        'symbol'      => $symbol,
                                        'error'       => $e->getMessage(),
                                        'dca_step'    => $currentDcaStep + 1,
                                        'capital'     => $capitalToUse,
                                        'buy_type'    => 'DCA_BUY_MARKET',
                                        'trace'       => $e->getTraceAsString(),
                                    ]);
                                }
                            }
                        }
                    }
                }
            
            // ================================================================
            // ACTIVE DEAL DONE — save state, skip IDLE section
            // ================================================================
            $repo->updateRuntimeState($botId, $state);
            continue;
            
            // ================================================================
                        // SITUATION B: BOT RESTING (IDLE) - SMART POLLING MANAGEMENT
            // ================================================================
            SITUATION_B_IDLE:
            {
                // Quarantine the Base Order if Recovery is active
                if ($userHasRecovery && !$isRecoveryBot) {
                    echo "      🛑 IDLE bot detected. Force-shutting this bot (Graceful Shutdown) because Recovery Mode is active.\n";
                    $conn->executeStatement("UPDATE bots SET status = 0 WHERE id = ?", [$botId]);
                    continue;
                }
                
                // 1. Determine the coin pair strategy (Market Scanner)
                $pairStrategy = $config['general']['pair_strategy'] ?? 'single';
                $customPairsStr = $config['general']['custom_pairs'] ?? '';
                $targetSymbols = [];

                if ($pairStrategy !== 'single') {
                    $scanner = new MarketScannerService($exchange);
                    $customList = array_map('trim', explode(',', $customPairsStr));
                    try {
                        $targetSymbols = $scanner->scanMarket('USDT', $pairStrategy, 5, $customList);
                        echo " -> 🔍 Market Scanner found " . count($targetSymbols) . " candidate coins.\n";
                    } catch (Exception $e) {
                        echo "⚠️ Market scan failed: " . $e->getMessage() . "\n";
                        $targetSymbols = [$symbol]; // Fallback
                    }
                } else {
                    $targetSymbols = [$symbol];
                }

                // Loop through candidate coins (top 5 — the bot checks them one by one)
                $botBoughtSomething = false;

                foreach ($targetSymbols as $scanSymbol) {
                    if ($botBoughtSomething) break; // Once bought, stop checking other coins

                    echo "   ➤ Checking entry conditions for [$scanSymbol]...\n";
                $requiresLiveData = false;
                $requiresAiAnalysis = false;
                $requiredScanTimeframes = [];
                $baseConditions = $config['base_order']['conditions'] ?? [];
                
                // COMBINED personal bot conditions + global conditions to determine system requirements
                $globalBaseConditions = ($isGlobalFilterEnabled && isset($globalFilters['base_buy'])) ? $globalFilters['base_buy'] : [];
                $allConditionsToCheck = array_merge($baseConditions, $globalBaseConditions);

                $taIndicators = ['rsi', 'bollinger', 'qfl', 'rsi_14'];
                foreach ($allConditionsToCheck as $cond) {
                    if (in_array($cond['type'], $taIndicators)) {
                        $requiresLiveData = true;
                        $tf = $cond['timeframe'] ?? '1h';
                        $requiredScanTimeframes[$tf] = true;
                    }
                    if ($cond['type'] === 'news_sentiment') {
                        $requiresAiAnalysis = true; 
                    }
                }

                // --- AI MARKET SIGNAL REFRESH (for ai_market entry conditions) ---
                $hasAiMarketCond = false;
                foreach ($allConditionsToCheck as $c) {
                    if (($c['type'] ?? '') === 'ai_market') { $hasAiMarketCond = true; break; }
                }
                if ($aiEnabled) {
                    $refreshAiMarketSignals($allConditionsToCheck, $scanSymbol, $exchange, $aiApiKey, $aiCfg, $auditLogger, $botId, $userId, $state);
                    $repo->updateRuntimeState($botId, $state); // Persist AI market cache/signals
                } elseif ($hasAiMarketCond) {
                    $notifyFeatureDisabled('ai', "AI market condition (ai_market) on $scanSymbol — entry WAITING, condition cannot pass", $botId, $userId);
                }

                $candles = []; // Reset for the scanned coin
                if ($requiresLiveData) {
                    $currentPrice = $exchange->getCurrentPrice($scanSymbol);
                    $state['live_current_price'] = $currentPrice;
                    // Log tick (IDLE scan)
                    $conn->executeStatement(
                        "INSERT INTO price_ticks (bot_id, price, pnl_percent, holdings) VALUES (?, ?, 0, 0)",
                        [$botId, $currentPrice]
                    );
                    $conn->executeStatement("DELETE FROM price_ticks WHERE id NOT IN (SELECT id FROM price_ticks ORDER BY id DESC LIMIT 250)");
                    echo "      -> Price fetched for technical calculation: $$currentPrice\n";
                    
                    if (!empty($requiredScanTimeframes)) {
                        foreach (array_keys($requiredScanTimeframes) as $tf) {
                            try {
                                $candles[$tf] = $exchange->getHistoricalCandles($scanSymbol, $tf, 100);
                            } catch (\Exception $e) {
                                echo "      ⚠️ Failed to fetch OHLCV data ($tf) for $scanSymbol.\n";
                            }
                        }
                    }
                }

                // --- ARTIFICIAL INTELLIGENCE PROCESS (AI CROSS-VERIFICATION) ---
                if ($requiresAiAnalysis && $aiEnabled) {
                    // Avoid spamming the AI API. Call the AI only every 5 minutes per coin
                    $lastAiCheck = $state['last_ai_check_timestamp'] ?? 0;
                    if ((time() - $lastAiCheck) > 300) { 
                        echo "      -> 🧠 [POLLING AI] Starting Cross-Verification for $scanSymbol...\n";
                        
                        // 1. Get verified news
                        $verificationResult = $crossVerifier->verifySignals($scanSymbol);
                        
                        if ($verificationResult['status'] === 'VERIFIED') {
                            echo "    ✓ Verified by " . $verificationResult['sources_count'] . " sources. Sending to AI...\n";
                            
                            $analyzer = new AiAnalyzer($aiApiKey, $aiCfg['base_url'], $aiCfg['model']);
                            try {
                                $aiDecision = $analyzer->analyzeSentiment($scanSymbol, $verificationResult['data']);
                                
                                // 3. Save the signal memory into the bot state
                                $state['latest_ai_sentiment'] = $aiDecision['signal'];
                                $state['latest_ai_confidence'] = $aiDecision['confidence'];
                                $state['latest_ai_reason'] = $aiDecision['reason'];
                                
                                $auditLogger->log($botId, $userId, 'AI_DECISION', "AI: {$aiDecision['signal']} ({$aiDecision['confidence']}%)", [
                                    'symbol'     => $scanSymbol,
                                    'signal'     => $aiDecision['signal'],
                                    'confidence' => $aiDecision['confidence'],
                                    'reason'     => $aiDecision['reason'],
                                    'sources'    => $verificationResult['sources_count'],
                                ]);
                                
                                echo "    🤖 AI decision: [ " . $aiDecision['signal'] . " ] (" . $aiDecision['confidence'] . "% confident). Reason: " . $aiDecision['reason'] . "\n";
                                
                            } catch (Exception $e) {
                                echo "    ⚠️ AI error: " . $e->getMessage() . "\n";
                            }
                        } else {
                            echo "    ⛔ AI analysis cancelled: " . $verificationResult['message'] . "\n";
                            // Reset sentiment if there is no news support for this period
                            $state['latest_ai_sentiment'] = 'HOLD';
                        }
                        
                        $state['last_ai_check_timestamp'] = time(); // Update the timestamp
                        $repo->updateRuntimeState($botId, $state); // Save the latest AI state to the database
                    } else {
                         echo " -> 🧠 [POLLING AI] Using cached sentiment: [" . ($state['latest_ai_sentiment'] ?? 'NONE') . "] (Next in " . (300 - (time() - $lastAiCheck)) . " seconds)\n";
                    }
                } elseif ($requiresAiAnalysis) {
                    $notifyFeatureDisabled('ai', "News sentiment condition (news_sentiment) on $scanSymbol — entry WAITING, condition cannot pass", $botId, $userId);
                }
                
                                // GLOBAL BASE BUY FILTER CHECK
                $isGlobalBaseAllowed = true;
                if ($isGlobalFilterEnabled && !empty($globalFilters['base_buy'])) {
                    $globalBaseEngine = new BaseOrderConditionEngine(['conditions' => $globalFilters['base_buy']], $state, $candles);
                    if (!$globalBaseEngine->evaluate()) {
                        $isGlobalBaseAllowed = false;
                    }
                }

                if (!$isGlobalBaseAllowed) {
                    echo "      ⛔ Blocked by Global Base Filter! Ignoring the personal bot signal.\n";
                } else {
                                        // ONLY CHECK THE PERSONAL BOT'S BASE ORDER CONDITIONS IF THE GLOBAL FILTER PASSED
                    $baseOrderEngine = new BaseOrderConditionEngine($config['base_order'], $state, $candles);

                    if ($baseOrderEngine->evaluate()) {
                        
                        $auditLogger->logRuleEval($botId, $userId, 'Base Order Entry', $baseOrderEngine->getLastRuleResults());
                        
                                                // --- EXECUTION GUARD CHECK (LEVEL 1) ---
                        $cooldown = $config['base_order']['cooldown_seconds'] ?? 7200;
                        $maxDeals = (int)($config['general']['max_active_deals'] ?? 1);
                        
                        // Note: botBlacklist is left empty because it is now managed automatically via the Global Blacklist
                        if (!$executionGuard->canBuy($userId, $scanSymbol, $cooldown, $maxDeals, '', $botId)) {
                            $rejectionContext = $executionGuard->getLastRejectionContext();
                            $auditLogger->log($botId, $userId, 'REJECTION', "Buy $scanSymbol blocked by Execution Guard", [
                                'symbol'    => $scanSymbol,
                                'rejection' => $rejectionContext,
                                'conditions_evaluated' => $baseOrderEngine->getLastRuleResults(),
                            ]);
                            echo "      🛡️ Blocked by Execution Guard: cooldown active, deal limit, or blacklist.\n";
                            continue; // Skip this coin
                        }

                                                // --- TRAILING BUY CHECK (LEVEL 2) ---
                        $isTrailingEnabled = !empty($config['base_order']['trailing_enabled']);
                        if ($isTrailingEnabled) {
                            $currentPrice = $currentPrice ?? $exchange->getCurrentPrice($scanSymbol);
                            echo "      📉 Trailing Buy mode enabled for $scanSymbol! Bot starts tracking the low...\n";
                            $state['status'] = 'WAITING_TRAILING_BUY';
                            $state['trailing_symbol'] = $scanSymbol;
                            $state['trailing_low_watermark'] = $currentPrice;
                            
                            // If Max Deals > 1, CLONE this bot immediately so the parent bot can look for other coins!
                            if ($maxDeals > 1) {
                                $conn->executeStatement("INSERT INTO bots (user_id, coin_pair, allocated_capital, status, configuration, runtime_state, parent_id) VALUES (?, ?, ?, ?, ?, ?, ?)", [$userId, $scanSymbol, $botData['allocated_capital'], 1, json_encode($botData['configuration']), json_encode($state), $botId]);
                                echo "      🧬 Composite Bot: Spawning a child bot to track the Trailing Buy for $scanSymbol.\n";

                                // Reset parent bot
                                $state['status'] = 'IDLE';
                                $state['current_holdings'] = 0.0;
                                $state['average_entry_price'] = 0.0;
                                unset($state['trailing_symbol']);
                                unset($state['trailing_low_watermark']);
                            } else {
                                $repo->updateRuntimeState($botId, $state);
                            }
                            break; // Stop scanning other coins
                        } else {
                                                        // IF NO TRAILING BUY, BUY IMMEDIATELY!
                            $currentPrice = $currentPrice ?? $exchange->getCurrentPrice($scanSymbol);
                            $readyToBuySymbol = $scanSymbol;
                            $readyToBuyPrice = $currentPrice;
                            break; // Stop scanning other coins, proceed to execution
                        }
                    }
                }
            }
            
            // ================================================================
                        // BASE ORDER BUY EXECUTION (FROM SITUATION B OR SITUATION D)
            // ================================================================
            if ($readyToBuySymbol !== null) {
                $scanSymbol = $readyToBuySymbol;
                $currentPrice = $readyToBuyPrice;
                $maxDeals = (int)($config['general']['max_active_deals'] ?? 1);
                
                echo "      🚀 Executing the first purchase for $scanSymbol...\n";
                try {
                    // --- STAGE 1.5: REAL-TIME DYNAMIC CAPITAL CALCULATION (AUTO) ---
                    $totalBotCapital = $config['general']['capital'] ?? 15.0;
                    
                    $activeChildrenCount = (int)$conn->executeQuery("SELECT COUNT(*) FROM bots WHERE parent_id = ?", [$botId])->fetchOne();
                    
                    if ($totalBotCapital === 'AUTO') {
                        if ($activeChildrenCount > 0 && isset($state['locked_auto_capital'])) {
                            $totalBotCapital = $state['locked_auto_capital'];
                            echo "      💰 AUTO capital (Locked): Using the locked capital of $" . round($totalBotCapital, 2) . " because the bot still has $activeChildrenCount active deal(s).\n";
                        } else {
                            $liveUsdt = $exchange->getAvailableBalance('USDT');
                            
                            $activeBotsRows = $conn->executeQuery(
                                "SELECT runtime_state FROM bots WHERE status = 1 AND runtime_state NOT LIKE '%\"status\":\"IDLE\"%' AND user_id = ?",
                                [$userId]
                            )->fetchAllAssociative();
                            
                            $reservedDcaFunds = 0.0;
                            foreach ($activeBotsRows as $activeBot) {
                                $botState = json_decode($activeBot['runtime_state'], true);
                                if (!$botState) continue;
                                $dealBudget = $botState['total_deal_budget'] ?? 0.0;
                                $spent = ($botState['current_holdings'] ?? 0.0) * ($botState['average_entry_price'] ?? 0.0);
                                $remainingReserve = max(0, $dealBudget - $spent);
                                $reservedDcaFunds += $remainingReserve;
                            }
                            
                            $trueFreeUsdt = $liveUsdt - $reservedDcaFunds;
                            
                            // Safety protection: if the True Free Balance is exhausted or negative,
                            // freeze IDLE bots from opening new positions to protect active bots.
                            if ($trueFreeUsdt <= 0) {
                                echo "      ⚠️ True Free Balance negative/zero (\$" . round($trueFreeUsdt, 2) . "). Operation paused to protect active bots' DCA funds.\n";
                                continue; 
                            }
                            
                            $idleBotsCount = (int)$conn->executeQuery(
                                "SELECT COUNT(*) FROM bots b WHERE b.status = 1 AND b.runtime_state LIKE '%\"status\":\"IDLE\"%' AND b.configuration LIKE '%\"capital\":\"AUTO\"%' AND b.user_id = ? AND NOT EXISTS (SELECT 1 FROM bots child WHERE child.parent_id = b.id)",
                                [$userId]
                            )->fetchOne();
                            if ($idleBotsCount < 1) $idleBotsCount = 1;
                            
                            $fairCapital = ($trueFreeUsdt * 0.90) / $idleBotsCount;
                            
                            if ($fairCapital < 10.5) {
                                throw new Exception("True USDT balance ($trueFreeUsdt) / $idleBotsCount bots insufficient (Minimum \$10.5).");
                            }
                            
                            $totalBotCapital = $fairCapital;
                            $state['locked_auto_capital'] = $totalBotCapital; 
                            echo "      💰 AUTO capital: $idleBotsCount IDLE bots sharing $" . round($trueFreeUsdt, 2) . " True Free USDT. Allocated: $" . round($totalBotCapital, 2) . ".\n";
                        }
                    } else {
                        $totalBotCapital = (float)$totalBotCapital;
                    }
                    
                    // Intelligent Capital Split
                    $actualDivisor = $maxDeals;
                    if ($pairStrategy === 'single') {
                        $actualDivisor = 1;
                    } elseif ($pairStrategy === 'custom_list') {
                        $customPairsCount = count(array_filter(array_map('trim', explode(',', $customPairsStr))));
                        if ($customPairsCount > 0 && $customPairsCount < $maxDeals) {
                            $actualDivisor = $customPairsCount;
                        }
                    }
                    $totalDealBudget = $totalBotCapital / $actualDivisor;
                    $state['total_deal_budget'] = $totalDealBudget;
                    
                    // --- STAGE 1.6: BASE ORDER CALCULATION (GEOMETRIC SERIES MATH) ---
                    $maxDcaSteps = (int)($config['dca']['max_steps'] ?? 0);
                    $volumeScale = (float)($config['dca']['volume_scale'] ?? 1.0);
                    
                    $multiplierSum = 1.0; 
                    for ($i = 1; $i <= $maxDcaSteps; $i++) {
                        $multiplierSum += pow($volumeScale, $i);
                    }
                    
                    $baseOrderAmount = $totalDealBudget / $multiplierSum;
                    
                    if ($baseOrderAmount < 5.0) {
                        $baseOrderAmount = 5.0; 
                        echo "      ⚠️ Warning: Base Order force-raised to the $5.0 minimum.\n";
                    }
                    
                    $state['base_order_amount'] = $baseOrderAmount;

                    // --- ORDER TYPE (STAGE 2) ---
                    $orderType = $config['base_order']['order_type'] ?? 'market';
                    
                    if ($orderType === 'limit') {
                        $buyOrder = $orderManager->executeLimitBuy($scanSymbol, $baseOrderAmount, $currentPrice);
                    } else {
                        $buyOrder = $orderManager->executeMarketBuy($scanSymbol, $baseOrderAmount);
                    }
                    
                    if ($orderType === 'limit' && isset($buyOrder['exchange_res']['id'])) {
                        $state['status'] = 'WAITING_LIMIT';
                        $state['limit_order_id'] = $buyOrder['exchange_res']['id'];
                        $state['average_entry_price'] = 0.0;
                        $state['current_holdings'] = 0.0;
                        $executePrice = $buyOrder['price'] ?? $currentPrice;
                        $executeAmount = $buyOrder['amount'] ?? 0.0;
                        echo "      ⏳ Limit Order placed (ID: {$state['limit_order_id']}). Waiting for exchange fill...\n";
                    } else {
                        $executePrice = $buyOrder['price'] ?? $currentPrice;
                        $executeAmount = $buyOrder['amount'] ?? 0.0;
                        
                        $state['status'] = 'ACTIVE';
                        $state['average_entry_price'] = $executePrice;
                        $state['base_order_price'] = $executePrice;
                        $state['base_order_volume_usdt'] = $executePrice * $executeAmount;
                        $state['current_holdings'] = $executeAmount;
                        $state['trade_start_timestamp'] = time(); 

                        $auditLogger->log($botId, $userId, 'STATE_CHANGE', "Bot ACTIVE — Bought $scanSymbol at $" . round($executePrice, 4), [
                            'symbol'        => $scanSymbol,
                            'entry_price'   => $executePrice,
                            'coin_amount'   => $executeAmount,
                            'usdt_spent'    => $baseOrderAmount,
                            'order_type'    => $orderType,
                        ]); 
                    }
                    
                    $state['dca_current_step'] = 0;
                    $state['latest_tv_signal'] = '';
                    $state['latest_external_signal'] = '';
                    $state['latest_ai_sentiment'] = '';
                    
                    // === VIRTUAL CLONE CONSTRUCTION (IF NOT FROM TRAILING) ===
                    if ($maxDeals > 1 && !$isFromTrailing) {
                        $conn->executeStatement("INSERT INTO bots (user_id, coin_pair, allocated_capital, status, configuration, runtime_state, parent_id) VALUES (?, ?, ?, ?, ?, ?, ?)", [$userId, $scanSymbol, $botData['allocated_capital'], 1, json_encode($botData['configuration']), json_encode($state), $botId]);
                        $newCloneId = $conn->lastInsertId();
                        echo "      🧬 Composite Bot Cloning: Spawned child bot (ID: $newCloneId) to manage $scanSymbol.\n";

                        $state['status'] = 'IDLE';
                        $state['current_holdings'] = 0.0;
                        $state['average_entry_price'] = 0.0;
                    } else {
                        // Regular single pair or trailing clone
                        $conn->executeStatement("UPDATE bots SET coin_pair = ? WHERE id = ?", [$scanSymbol, $botId]);
                    }
                    
                    $auditLogger->logTrade($botId, $userId, [
                        'bot_id'    => $botId,
                        'action' => 'BUY_' . strtoupper($orderType),
                        'symbol' => $scanSymbol,
                        'execute_price' => $executePrice,
                        'coin_amount' => $executeAmount,
                        'usd_spent' => $baseOrderAmount,
                        'status' => $buyOrder['status'] ?? 'SUCCESS'
                    ]);
                    
                    $notifier->sendTelegramAlert("🚀 <b>BASE ORDER OPENED ($orderType)</b>\nPair: $scanSymbol\nPrice: $" . round($executePrice, 4) . "\nAmount: $" . round($baseOrderAmount, 2) . "\nTotal Budget: $" . round($totalDealBudget, 2));
                    
                    $botBoughtSomething = true;
                    if ($state['status'] === 'ACTIVE') {
                        echo "      ✅ Base Order succeeded. Avg Price: $" . $state['average_entry_price'] . "\n";
                    }
                } catch (Exception $e) {
                    echo "      ❌ Base Order execution error: " . $e->getMessage() . "\n";
                    $notifier->sendTelegramAlert("⚠️ <b>BASE BUY ERROR</b>\nPair: $scanSymbol\nError: " . $e->getMessage());
                    $auditLogger->log($botId, $userId, 'ERROR', "Base Order failed for $scanSymbol: " . $e->getMessage(), [
                        'symbol'      => $scanSymbol,
                        'error'       => $e->getMessage(),
                        'order_type'  => $config['base_order']['order_type'] ?? 'market',
                    ]);
                }
            } else {
                if (($state['status'] ?? 'IDLE') === 'IDLE' && !isset($readyToBuySymbol)) {
                    echo "      -> No signal met or waiting for Trailing.\n";
                }
            }
                
                } // Penutup gelung foreach Market Scanner
            }

        }

    } catch (Exception $e) {
        echo "⚠️ CRITICAL ERROR ON TICK: " . $e->getMessage() . "\n";
        if (isset($botId) && isset($userId)) {
            $auditLogger->log($botId, $userId, 'ERROR', "Critical error: " . $e->getMessage(), [
                'error'       => $e->getMessage(),
                'tick'        => $tickCount,
                'bot_id'      => $botId,
                'trace'       => $e->getTraceAsString(),
            ]);
        }
    }

    if (!$hasActiveDeal) {
        echo "💤 No Active Deal. Resting for {$idleInterval} seconds...\n";
        sleep($idleInterval);
        $tickCount++;
        continue;
    }

    $tickCount++;
    sleep($loopInterval);
    }
} finally {
    // Release the lock file on shutdown signal or fatal exception
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);

    echo "\n=========================================================\n";
    echo " 🛑 TICK ENGINE FINISHED\n";
    echo "=========================================================\n";
}