<?php

namespace Fixzy\Kriptobot\Trading;

use Exception;

/**
 * ExchangeService — Abstraction layer for interacting with crypto exchanges via CCXT.
 *
 * Provides safe access to market data (price, balance, OHLCV) and order execution
 * (Market, Limit) with APCu caching for high performance and API call rate
 * control.
 *
 * Used by bot_daemon.php for all real-time trading operations.
 *
 * @package Fixzy\Kriptobot\Trading
 */
class ExchangeService
{
    /** @var \ccxt\Exchange The underlying CCXT exchange object */
    private $exchange;

    /** @var string Lowercase exchange name (e.g. 'binance') */
    private string $exchangeName;

    /**
     * @param string $exchangeName Exchange name (e.g. 'binance', 'kucoin')
     * @param string $apiKey       Plaintext API key (already decrypted)
     * @param string $secret       Plaintext API secret (already decrypted)
     * @param bool   $isTestnet    Enable Sandbox/Testnet mode (default: true)
     *
     * @throws Exception If the exchange is not supported by CCXT
     *
     * @example
     * $ex = new ExchangeService('binance', 'key123', 'secret456', true);
     */
    public function __construct(string $exchangeName, string $apiKey, string $secret, bool $isTestnet = true)
    {
        $exchangeName = strtolower($exchangeName);
        $this->exchangeName = $exchangeName;
        $exchangeClass = '\\ccxt\\' . $exchangeName;

        if (!class_exists($exchangeClass)) {
            throw new Exception("Error: Exchange '$exchangeName' is not supported by CCXT.");
        }

        $this->exchange = new $exchangeClass([
            'apiKey' => $apiKey,
            'secret' => $secret,
            'enableRateLimit' => true,
        ]);

        if ($isTestnet) {
            $this->exchange->set_sandbox_mode(true);
        }
    }

    /**
     * Get the CCXT exchange object to allow direct API calls
     * (e.g. fetch_order, cancel_order, fetch_tickers).
     *
     * @return \ccxt\Exchange
     */
    public function getExchange()
    {
        return $this->exchange;
    }

    /**
     * Get the lowercase exchange name.
     *
     * @return string e.g. 'binance'
     */
    public function getExchangeName(): string
    {
        return strtolower($this->exchange->id);
    }

    // ────────────────────────────────────────────────────────
    //  PRICE & MARKET DATA
    // ────────────────────────────────────────────────────────

    /**
     * Get the latest price (last price) for a coin pair.
     *
     * Two-tier caching system:
     *   1. Check APCu shared memory (15s TTL) — provided by the Data Hub in the daemon
     *   2. Fall back to the fetch_ticker REST API if the cache is empty
     *
     * @param string $symbol Coin pair (e.g. 'BTC/USDT')
     *
     * @return float Current price. Always > 0 for a valid symbol.
     *
     * @throws Exception If the price cannot be obtained from the exchange
     *
     * @example
     * $price = $ex->getCurrentPrice('BTC/USDT'); // 50000.00
     */
    public function getCurrentPrice(string $symbol): float
    {
        $cacheKey = 'kriptobot_price_' . $this->exchangeName . '_' . $symbol;

        if (function_exists('apcu_fetch')) {
            $cachedPrice = apcu_fetch($cacheKey);
            if ($cachedPrice !== false && $cachedPrice > 0) {
                return (float) $cachedPrice;
            }
        }

        try {
            $ticker = $this->exchange->fetch_ticker($symbol);
            $price = isset($ticker['last']) ? (float) $ticker['last'] : null;

            if ($price === null || $price <= 0) {
                throw new Exception("Incomplete ticker data or invalid price for $symbol.");
            }

            if (function_exists('apcu_store')) {
                apcu_store($cacheKey, $price, 15);
            }

            return $price;
        } catch (Exception $e) {
            throw new Exception("Failed to get price for $symbol: " . $e->getMessage());
        }
    }

    /**
     * Fetch OHLCV (candlestick) data with APCu caching.
     *
     * @param string $symbol    Coin pair (e.g. 'BTC/USDT')
     * @param string $timeframe Timeframe (e.g. '1m', '5m', '15m', '1h', '4h', '1d')
     * @param int    $limit     Number of candles to fetch (default: 100)
     *
     * @return array Candle array in CCXT format:
     *               [[timestamp, open, high, low, close, volume], ...]
     *
     * @throws \Exception If the OHLCV data cannot be fetched
     *
     * @example
     * $candles = $ex->getHistoricalCandles('BTC/USDT', '1h', 100);
     */
    public function getHistoricalCandles(string $symbol, string $timeframe = '1h', int $limit = 100): array
    {
        $cacheKey = 'kriptobot_ohlcv_' . $this->exchangeName . '_' . $symbol . '_' . $timeframe . '_' . $limit;

        if (function_exists('apcu_fetch')) {
            $cachedCandles = apcu_fetch($cacheKey);
            if ($cachedCandles !== false) {
                return $cachedCandles;
            }
        }

        try {
            $candles = $this->exchange->fetch_ohlcv($symbol, $timeframe, null, $limit);

            if (function_exists('apcu_store')) {
                apcu_store($cacheKey, $candles, 15);
            }

            return $candles;
        } catch (\Exception $e) {
            throw new \Exception("Failed to fetch OHLCV data for $symbol: " . $e->getMessage());
        }
    }

    // ────────────────────────────────────────────────────────
    //  BALANCE & CAPITAL
    // ────────────────────────────────────────────────────────

    /**
     * Get the available wallet balance (free balance) for a given currency.
     *
     * @param string $currency Currency (e.g. 'USDT', 'BNB', 'BTC')
     *
     * @return float Free balance. 0.0 if the currency does not exist.
     *
     * @throws Exception If the balance check against the exchange fails
     *
     * @example
     * $usdt = $ex->getAvailableBalance('USDT'); // 1000.50
     */
    public function getAvailableBalance(string $currency = 'USDT'): float
    {
        try {
            $balance = $this->exchange->fetch_balance();
            return (float) ($balance['free'][$currency] ?? 0.0);
        } catch (Exception $e) {
            throw new Exception("Failed to get wallet balance from exchange: " . $e->getMessage());
        }
    }

    /**
     * Check the free balance for a specific coin with a nested guard.
     *
     * Used by OrderManager::ensureBnbFeeBalance() for the BNB check.
     *
     * @param string $coin Coin symbol (e.g. 'BNB')
     *
     * @return float Free balance. 0.0 if the coin does not exist in the wallet.
     *
     * @throws Exception If the balance check against the exchange fails
     */
    public function getFreeBalance(string $coin): float
    {
        try {
            $balances = $this->exchange->fetch_balance();

            if (isset($balances[$coin]) && isset($balances[$coin]['free'])) {
                return (float) $balances[$coin]['free'];
            }

            return 0.0;
        } catch (Exception $e) {
            throw new Exception("Failed to check the actual balance for $coin: " . $e->getMessage());
        }
    }

    /**
     * Calculate dynamic capital (90% of the wallet balance).
     *
     * Used by the dashboard UI to estimate capital allocation.
     *
     * @param float $totalBalance Total USDT balance
     *
     * @return float 90% of the balance, or 0.0 if the balance is ≤ 0
     */
    public function calculateDynamicCapital(float $totalBalance): float
    {
        if ($totalBalance <= 0) {
            return 0.0;
        }

        return $totalBalance * 0.90;
    }

    // ────────────────────────────────────────────────────────
    //  ORDER EXECUTION
    // ────────────────────────────────────────────────────────

    /**
     * Execute a market order.
     *
     * @param string $symbol Coin pair (e.g. 'BTC/USDT')
     * @param string $side   'buy' or 'sell'
     * @param float  $amount Amount of COINS (not USDT) to buy/sell
     *
     * @return array Full response from the exchange (CCXT structure)
     *
     * @throws Exception If the order fails to execute
     *
     * @example
     * $order = $ex->createMarketOrder('BTC/USDT', 'buy', 0.001);
     */
    public function createMarketOrder(string $symbol, string $side, float $amount): array
    {
        try {
            return $this->exchange->create_market_order($symbol, $side, $amount);
        } catch (Exception $e) {
            throw new Exception("Failed to execute $side order for $symbol: " . $e->getMessage());
        }
    }

    /**
     * Execute a limit order.
     *
     * @param string $symbol Coin pair (e.g. 'BTC/USDT')
     * @param string $side   'buy' or 'sell'
     * @param float  $amount Amount of COINS
     * @param float  $price  Target price
     *
     * @return array Full response from the exchange (CCXT structure)
     *
     * @throws Exception If the order fails to execute
     *
     * @example
     * $order = $ex->createLimitOrder('BTC/USDT', 'buy', 0.001, 49000);
     */
    public function createLimitOrder(string $symbol, string $side, float $amount, float $price): array
    {
        try {
            return $this->exchange->create_limit_order($symbol, $side, $amount, $price);
        } catch (Exception $e) {
            throw new Exception("Failed to execute limit $side order for $symbol: " . $e->getMessage());
        }
    }
}
