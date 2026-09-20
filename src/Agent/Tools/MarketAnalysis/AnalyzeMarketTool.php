<?php

namespace Fixzy\Kriptobot\Agent\Tools\MarketAnalysis;

use Fixzy\Kriptobot\Agent\Tools\ToolInterface;
use Fixzy\Kriptobot\Agent\Tools\ToolResult;
use Fixzy\Kriptobot\Database\Database;

class AnalyzeMarketTool implements ToolInterface
{
    public function getName(): string
    {
        return 'analyze_market';
    }

    public function getDescription(string $lang = 'en'): string
    {
        return $lang === 'ms'
            ? 'Analyze current market data for a specific cryptocurrency pair. Gets the latest price, 24h trading volume, price change, and basic market data. Use before proposing a trading strategy.'
            : 'Analyze current market data for specific cryptocurrency pairs. Gets latest price, 24h volume, price change, and basic market data. Use before proposing any trading strategy.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'pair' => [
                    'type'        => 'string',
                    'description' => 'Trading pair in format BTC/USDT, ETH/USDT, etc. Use comma for multiple: "BTC/USDT,ETH/USDT"',
                ],
                'action' => [
                    'type'        => 'string',
                    'enum'        => ['price', 'volume', 'overview', 'scan_top'],
                    'description' => 'Type of market analysis: "price" for current price, "volume" for 24h volume data, "overview" for comprehensive snapshot, "scan_top" for top coins by volume',
                ],
            ],
            'required'   => ['action'],
        ];
    }

    public function execute(array $params, int $userId): ToolResult
    {
        $action = $params['action'] ?? 'overview';
        $pair = $params['pair'] ?? '';

        try {
            $conn = Database::getConnection();
            $user = $conn->executeQuery(
                "SELECT is_demo_mode, testnet_api_key, testnet_api_secret_encrypted FROM users WHERE id = ?",
                [$userId]
            )->fetchAssociative();

            $isDemo = (bool)($user['is_demo_mode'] ?? false);

            // Use cached data from APCu if available
            if (function_exists('apcu_fetch')) {
                $cacheKey = 'agent_market_' . $action . '_' . md5($pair);
                $cached = apcu_fetch($cacheKey);
                if ($cached !== false) {
                    return ToolResult::ok($cached);
                }
            }

            $result = match ($action) {
                'scan_top' => $this->scanTopCoins(),
                'price'    => $this->getPrices($pair),
                'volume'   => $this->getVolumeData($pair),
                default    => $this->getOverview($pair),
            };

            if (function_exists('apcu_store')) {
                apcu_store('agent_market_' . $action . '_' . md5($pair), $result, 60);
            }

            return ToolResult::ok($result);

        } catch (\Throwable $e) {
            return ToolResult::fail('Market analysis failed: ' . $e->getMessage());
        }
    }

    private function getOverview(string $pair): array
    {
        if (empty($pair)) {
            return ['error' => 'Pair required for overview'];
        }

        $pairs = array_map('trim', explode(',', $pair));
        $result = [];

        foreach ($pairs as $symbol) {
            $tickerData = $this->fetchTicker($symbol);
            if ($tickerData) {
                $result[$symbol] = $tickerData;
            }
        }

        return [
            'pairs'     => $result,
            'timestamp' => date('Y-m-d H:i:s'),
            'count'     => count($result),
        ];
    }

    private function getPrices(string $pair): array
    {
        if (empty($pair)) {
            return ['error' => 'Pair required for price check'];
        }

        $pairs = array_map('trim', explode(',', $pair));
        $result = [];

        foreach ($pairs as $symbol) {
            $ticker = $this->fetchTicker($symbol);
            if ($ticker) {
                $result[$symbol] = [
                    'price'          => $ticker['last'],
                    'bid'            => $ticker['bid'],
                    'ask'            => $ticker['ask'],
                    '24h_change_pct' => $ticker['percentage'] ?? 0,
                ];
            }
        }

        return $result;
    }

    private function getVolumeData(string $pair): array
    {
        if (empty($pair)) {
            return ['error' => 'Pair required for volume data'];
        }

        $pairs = array_map('trim', explode(',', $pair));
        $result = [];

        foreach ($pairs as $symbol) {
            $ticker = $this->fetchTicker($symbol);
            if ($ticker) {
                $result[$symbol] = [
                    'base_volume'  => $ticker['baseVolume'] ?? 0,
                    'quote_volume' => $ticker['quoteVolume'] ?? 0,
                    '24h_high'     => $ticker['high'] ?? 0,
                    '24h_low'      => $ticker['low'] ?? 0,
                ];
            }
        }

        return $result;
    }

    private function scanTopCoins(): array
    {
        try {
            $exchange = new \ccxt\binance([
                'enableRateLimit' => true,
                'options' => ['defaultType' => 'spot'],
            ]);

            $tickers = $exchange->fetch_tickers();

            $filteredPairs = [];
            foreach ($tickers as $symbol => $ticker) {
                if (str_ends_with($symbol, '/USDT')) {
                    $filteredPairs[] = [
                        'symbol'         => $symbol,
                        'quote_volume'   => $ticker['quoteVolume'] ?? 0,
                        'change_percent' => $ticker['percentage'] ?? 0,
                    ];
                }
            }

            // Top by volume
            $byVolume = $filteredPairs;
            usort($byVolume, fn($a, $b) => $b['quote_volume'] <=> $a['quote_volume']);
            $topVolume = array_slice($byVolume, 0, 20);

            // Top by volatility
            $byVolatility = $filteredPairs;
            usort($byVolatility, fn($a, $b) => abs($b['change_percent']) <=> abs($a['change_percent']));
            $topVolatility = array_slice($byVolatility, 0, 10);

            return [
                'top_volume'      => $topVolume,
                'top_volatility'  => $topVolatility,
                'timestamp'       => date('Y-m-d H:i:s'),
            ];
        } catch (\Throwable $e) {
            return ['error' => 'Market scan failed: ' . $e->getMessage()];
        }
    }

    private function fetchTicker(string $symbol): ?array
    {
        // Try APCu cached ticker first (set by daemon)
        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch('ticker_' . str_replace('/', '_', $symbol));
            if ($cached !== false) {
                return $cached;
            }
        }

        // Try fetching via CCXT
        try {
            $exchange = new \ccxt\binance([
                'enableRateLimit' => true,
                'options' => ['defaultType' => 'spot'],
            ]);

            $ticker = $exchange->fetch_ticker($symbol);
            return [
                'symbol'       => $symbol,
                'last'         => $ticker['last'] ?? 0,
                'bid'          => $ticker['bid'] ?? 0,
                'ask'          => $ticker['ask'] ?? 0,
                'high'         => $ticker['high'] ?? 0,
                'low'          => $ticker['low'] ?? 0,
                'baseVolume'   => $ticker['baseVolume'] ?? 0,
                'quoteVolume'  => $ticker['quoteVolume'] ?? 0,
                'percentage'   => $ticker['percentage'] ?? 0,
                'change'       => $ticker['change'] ?? 0,
            ];
        } catch (\Throwable $e) {
            error_log("AnalyzeMarketTool: Failed to fetch ticker for {$symbol}: " . $e->getMessage());
            return null;
        }
    }
}
