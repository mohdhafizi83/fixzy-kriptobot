<?php

namespace Fixzy\Kriptobot\Trading;

class DCACalculator
{
    /**
     * Calculate the DCA percentage breakdown based on 2x multiples.
     * Uses geometric series logic (2^n - 1).
     * * @param int $steps Number of DCA steps (example: 4)
     * @return array List of percentages for each step
     */
    public static function calculatePercentages(int $steps): array
    {
        if ($steps < 1) return [];

        // 1. Compute the total weight
        $totalWeight = pow(2, $steps) - 1;
        
        $percentages = [];
        $sumCheck = 0;

        for ($i = 0; $i < $steps; $i++) {
            // 2. Compute the weight for the current step (1, 2, 4, 8, ...)
            $weight = pow(2, $i);
            
            // 3. Convert to a percentage
            if ($i === $steps - 1) {
                // Final step: take the exact remainder to avoid decimal rounding errors
                $percentages[] = round(100 - $sumCheck, 2);
            } else {
                $percent = round(($weight / $totalWeight) * 100, 2);
                $percentages[] = $percent;
                $sumCheck += $percent;
            }
        }

        return $percentages;
    }

    /**
     * Calculate the fund size of each order based on tradable capital.
     * * @param float $totalCapital Allocated capital (example: 90% of balance)
     * @param int $steps Number of DCA steps
     * @return array List of monetary values (USD/USDT) for each order
     */
    public static function calculateOrderSizes(float $totalCapital, int $steps): array
    {
        $percentages = self::calculatePercentages($steps);
        $orderSizes = [];
        $sumSizes = 0;

        foreach ($percentages as $index => $pct) {
            if ($index === $steps - 1) {
                // Final step: assign the entire remaining funds
                $orderSizes[] = round($totalCapital - $sumSizes, 2);
            } else {
                // Compute the monetary value from the percentage
                $size = round($totalCapital * ($pct / 100), 2);
                $orderSizes[] = $size;
                $sumSizes += $size;
            }
        }

        return $orderSizes;
    }
}