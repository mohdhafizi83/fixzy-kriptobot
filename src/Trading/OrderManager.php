<?php

namespace Fixzy\Kriptobot\Trading;

use Exception;

/**
 * OrderManager — Order execution layer that converts USDT into coins (and back).
 *
 * Each function computes the coin amount from the USDT amount, runs the BNB fee
 * check (best-effort), and submits the order to the exchange via ExchangeService.
 *
 * For Market Orders, the return values use the ACTUAL fill data from the CCXT response
 * (average fill price, filled amount) to ensure accurate state tracking.
 *
 * @package Fixzy\Kriptobot\Trading
 */
class OrderManager
{
    private ExchangeService $exchangeService;

    /**
     * @param ExchangeService $exchangeService
     */
    public function __construct(ExchangeService $exchangeService)
    {
        $this->exchangeService = $exchangeService;
    }

    // ────────────────────────────────────────────────────────
    //  BUY ORDERS
    // ────────────────────────────────────────────────────────

    /**
     * Calculate the Base Order size using the geometric series formula (static method).
     *
     * Formula: BaseOrderSize = TotalBudget / sum(volumeScale^i, i=0..maxDcaSteps)
     * This ensures (Base + all DCA) = totalDealBudget.
     *
     * @param float $totalDealBudget Capital allocated for one deal (USDT)
     * @param int   $maxDcaSteps     Maximum number of DCA steps
     * @param float $volumeScale     Multiplication factor for each DCA step
     *
     * @return float Base Order size in USDT
     */
    public static function calculateBaseOrderSize(float $totalDealBudget, int $maxDcaSteps, float $volumeScale): float
    {
        $divisor = 0.0;
        for ($i = 0; $i < $maxDcaSteps + 1; $i++) {
            $divisor += pow($volumeScale, $i);
        }
        return $totalDealBudget / max($divisor, 1.0);
    }

    /**
     * Execute a Market Buy — buy coins at the current market price.
     *
     * Used for Base Order and DCA Market by bot_daemon.php.
     *
     * @param string $symbol          Coin pair (e.g. 'BTC/USDT')
     * @param float  $usdAmountToSpend Amount of USDT to spend
     *
     * @return array {
     *     status:       'SUCCESS',
     *     action:       'MARKET_BUY',
     *     symbol:       string,
     *     usd_spent:    float (estimated USDT spent),
     *     price:        float (ACTUAL average fill price from CCXT),
     *     amount:       float (ACTUAL coin amount filled from CCXT),
     *     exchange_res: array (full CCXT response)
     * }
     *
     * @throws Exception If the price cannot be fetched or the order fails
     *
     * @example
     * $result = $orderManager->executeMarketBuy('BTC/USDT', 100);
     * echo $result['price']; // Actual average price
     */
    public function executeMarketBuy(string $symbol, float $usdAmountToSpend): array
    {
        $currentPrice = $this->exchangeService->getCurrentPrice($symbol);
        $coinAmountToBuy = $usdAmountToSpend / $currentPrice;

        $this->ensureBnbFeeBalance($usdAmountToSpend);

        try {
            $result = $this->exchangeService->createMarketOrder($symbol, 'buy', $coinAmountToBuy);

            return [
                'status'       => 'SUCCESS',
                'action'       => 'MARKET_BUY',
                'symbol'       => $symbol,
                'usd_spent'    => round($usdAmountToSpend, 2),
                'price'        => (float) ($result['average'] ?? $currentPrice),
                'amount'       => (float) ($result['filled'] ?? $coinAmountToBuy),
                'exchange_res' => $result,
            ];
        } catch (Exception $e) {
            throw new Exception("Market Buy execution error: " . $e->getMessage());
        }
    }

    /**
     * Execute a Limit Buy — place a buy limit order at the target price.
     *
     * Used for Base Order (Try Limit 1st) and DCA Limit.
     *
     * @param string $symbol          Coin pair
     * @param float  $usdAmountToSpend Amount of USDT
     * @param float  $targetPrice     Limit target price
     *
     * @return array {
     *     status:       'PENDING',
     *     action:       'LIMIT_BUY',
     *     symbol:       string,
     *     usd_spent:    float,
     *     price:        float (target price),
     *     amount:       float (estimated coin amount),
     *     exchange_res: array (CCXT response, including order ID)
     * }
     *
     * @throws Exception If the order fails
     *
     * @example
     * $result = $orderManager->executeLimitBuy('BTC/USDT', 100, 49000);
     * echo $result['exchange_res']['id']; // Order ID
     */
    public function executeLimitBuy(string $symbol, float $usdAmountToSpend, float $targetPrice): array
    {
        $coinAmountToBuy = $usdAmountToSpend / $targetPrice;

        $this->ensureBnbFeeBalance($usdAmountToSpend);

        try {
            $result = $this->exchangeService->createLimitOrder($symbol, 'buy', $coinAmountToBuy, $targetPrice);

            return [
                'status'       => 'PENDING',
                'action'       => 'LIMIT_BUY',
                'symbol'       => $symbol,
                'usd_spent'    => round($usdAmountToSpend, 2),
                'price'        => $targetPrice,
                'amount'        => $coinAmountToBuy,
                'exchange_res' => $result,
            ];
        } catch (Exception $e) {
            throw new Exception("Limit Buy execution error: " . $e->getMessage());
        }
    }

    // ────────────────────────────────────────────────────────
    //  SELL ORDERS
    // ────────────────────────────────────────────────────────

    /**
     * Execute a Market Sell — sell the entire holding at the market price.
     *
     * Used for Take Profit, Cut Loss, Global Sell, Custom Sell.
     *
     * @param string $symbol            Coin pair
     * @param float  $coinAmountToSell  Amount of COINS to sell
     *
     * @return array {
     *     status:       'SUCCESS',
     *     action:       'MARKET_SELL',
     *     symbol:       string,
     *     price:        float (ACTUAL average fill price),
     *     amount:       float (ACTUAL coin amount filled),
     *     exchange_res: array (full CCXT response)
     * }
     *
     * @throws Exception If the amount is ≤ 0 or the order fails
     *
     * @example
     * $result = $orderManager->executeMarketSell('BTC/USDT', 0.001);
     */
    public function executeMarketSell(string $symbol, float $coinAmountToSell): array
    {
        if ($coinAmountToSell <= 0) {
            throw new Exception("Invalid coin amount to sell.");
        }

        try {
            $currentPrice = $this->exchangeService->getCurrentPrice($symbol);
            $result = $this->exchangeService->createMarketOrder($symbol, 'sell', $coinAmountToSell);

            return [
                'status'       => 'SUCCESS',
                'action'       => 'MARKET_SELL',
                'symbol'       => $symbol,
                'price'        => (float) ($result['average'] ?? $currentPrice),
                'amount'       => (float) ($result['filled'] ?? $coinAmountToSell),
                'exchange_res' => $result,
            ];
        } catch (Exception $e) {
            throw new Exception("Market Sell execution error: " . $e->getMessage());
        }
    }

    /**
     * Execute a Limit Sell — place a sell limit order at the target price.
     *
     * @param string $symbol            Coin pair
     * @param float  $coinAmountToSell  Amount of COINS
     * @param float  $targetPrice       Target price
     *
     * @return array {
     *     status:       'PENDING',
     *     action:       'LIMIT_SELL',
     *     symbol:       string,
     *     price:        float,
     *     amount:       float,
     *     exchange_res: array
     * }
     *
     * @throws Exception If the order fails
     */
    public function executeLimitSell(string $symbol, float $coinAmountToSell, float $targetPrice): array
    {
        try {
            $result = $this->exchangeService->createLimitOrder($symbol, 'sell', $coinAmountToSell, $targetPrice);

            return [
                'status'       => 'PENDING',
                'action'       => 'LIMIT_SELL',
                'symbol'       => $symbol,
                'price'        => $targetPrice,
                'amount'       => $coinAmountToSell,
                'exchange_res' => $result,
            ];
        } catch (Exception $e) {
            throw new Exception("Limit Sell execution error: " . $e->getMessage());
        }
    }

    // ────────────────────────────────────────────────────────
    //  FEE MANAGEMENT
    // ────────────────────────────────────────────────────────

    /**
     * Ensure the BNB balance is sufficient for the Binance trading fee discount.
     *
     * BEST-EFFORT operation: any failure (API down, missing BNB pair) will
     * log a warning and allow the trade to continue without the BNB discount.
     *
     * @param float $tradedCapitalUsd Estimated trade value in USDT
     *
     * @internal Only active for Binance. For other exchanges, returns immediately.
     */
    private function ensureBnbFeeBalance(float $tradedCapitalUsd): void
    {
        try {
            if ($this->exchangeService->getExchangeName() !== 'binance') {
                return;
            }

            $requiredBnbUsd = $tradedCapitalUsd * 0.002;

            $currentBnbBalance = $this->exchangeService->getFreeBalance('BNB');
            $bnbCurrentPrice   = $this->exchangeService->getCurrentPrice('BNB/USDT');

            $currentBnbValueUsd = $currentBnbBalance * $bnbCurrentPrice;

            if ($currentBnbValueUsd >= $requiredBnbUsd) {
                return;
            }

            $shortageUsd     = $requiredBnbUsd - $currentBnbValueUsd;
            $amountUsdToBuy  = max($shortageUsd, 5.50);
            $bnbAmountToBuy  = $amountUsdToBuy / $bnbCurrentPrice;

            $this->exchangeService->createMarketOrder('BNB/USDT', 'buy', $bnbAmountToBuy);
            echo "⛽ [FEE MANAGER]: Successfully added BNB value " . round($bnbAmountToBuy, 4) . " BNB (\$" . round($amountUsdToBuy, 2) . ") for fee savings.\n";
        } catch (Exception $e) {
            echo "⚠️ [FEE MANAGER]: BNB management failed (" . $e->getMessage() . "). Trade continues without fee management.\n";
        }
    }
}
