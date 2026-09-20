<?php

namespace Fixzy\Kriptobot\Trading;

use Exception;

class MarketScannerService
{
    private ExchangeService $exchangeService;

    public function __construct(ExchangeService $exchangeService)
    {
        $this->exchangeService = $exchangeService;
    }

    /**
     * Scan the market and return a list of target coins based on the strategy
     * 
     * @param string $quoteCurrency Quote currency (e.g. 'USDT')
     * @param string $strategy Strategy choice: 'top_volume', 'top_volatility', or 'custom'
     * @param int $limit Maximum number of coins to return
     * @param array $customList Custom list when strategy = 'custom' (e.g. ['BTC/USDT', 'ETH/USDT'])
     * @return array List of recommended coin pairs
     */
    public function scanMarket(string $quoteCurrency = 'USDT', string $strategy = 'top_10_volume', int $limit = 10, array $customList = []): array
    {
        if ($strategy === 'custom_list') {
            return array_slice($customList, 0, $limit);
        }

        // Override the limit if the strategy is Top 50
        if ($strategy === 'top_50_global') {
            $limit = 50;
        }

        try {
            // Caching system: store the ticker list for 5 minutes to avoid API rate limits / bans
            $cacheFile = dirname(__DIR__, 2) . '/storage/cache/market_tickers.json';
            $cacheTTL = 300; // 5 minutes
            $tickers = [];

            if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
                $tickers = json_decode(file_get_contents($cacheFile), true);
            } else {
                $exchange = $this->exchangeService->getExchange();
                $tickers = $exchange->fetch_tickers(); // A SINGLE bulk call
                
                $cacheDir = dirname($cacheFile);
                if (!is_dir($cacheDir)) {
                    mkdir($cacheDir, 0755, true);
                }
                file_put_contents($cacheFile, json_encode($tickers));
            }
            
            $filteredPairs = [];

            foreach ($tickers as $symbol => $ticker) {
                // Keep only pairs with the target quote currency (e.g. X/USDT) that are active
                if (str_ends_with($symbol, '/' . $quoteCurrency) && isset($ticker['info'])) {
                    $filteredPairs[$symbol] = [
                        'symbol' => $symbol,
                        'volume' => $ticker['quoteVolume'] ?? 0, // Volume denominated in USDT
                        'change_percent' => $ticker['percentage'] ?? 0 // 24-hour change percentage
                    ];
                }
            }

            // 'top_50_global' is treated as a close proxy for 'top_volume' on the same exchange
            if ($strategy === 'top_10_volume' || $strategy === 'top_50_global') {
                // Sort by highest volume
                usort($filteredPairs, function ($a, $b) {
                    return $b['volume'] <=> $a['volume'];
                });
            } elseif ($strategy === 'top_10_volatility') {
                // Sort by percentage movement (volatility — large positive or negative)
                usort($filteredPairs, function ($a, $b) {
                    return abs($b['change_percent']) <=> abs($a['change_percent']);
                });
            }

            // Take the top pairs up to the limit
            $selected = array_slice($filteredPairs, 0, $limit);
            
            // Extract the symbol names only
            return array_map(function($item) {
                return $item['symbol'];
            }, $selected);

        } catch (Exception $e) {
            throw new Exception("Failed to scan the market: " . $e->getMessage());
        }
    }
}
