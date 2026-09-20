<?php

namespace Fixzy\Kriptobot\Trading;

class SmartRecoveryManager
{
    private bool $isActive = false;
    private float $targetRecoveryAmount = 0.0;
    private float $currentRecoveredAmount = 0.0;
    private float $lockedCapital = 0.0; // Dedicated capital ($2100)

    /**
     * Load state from the database when the bot starts
     */
    public function loadState(bool $isActive, float $target, float $recovered, float $capital): void
    {
        $this->isActive = $isActive;
        $this->targetRecoveryAmount = $target;
        $this->currentRecoveredAmount = $recovered;
        $this->lockedCapital = $capital;
    }

    /**
     * Enable Smart Recovery Mode after a loss is detected
     */
    public function activateSmartMode(float $realizedLossUsd, float $remainingCapitalUsd): void
    {
        $this->isActive = true;
        $this->targetRecoveryAmount = abs($realizedLossUsd); // Example: $400
        $this->currentRecoveredAmount = 0.0;
        $this->lockedCapital = $remainingCapitalUsd;         // Example: $2100
    }

    /**
     * Called every time the bot takes profit while in Smart Mode
     */
    public function registerProfit(float $profitUsd): void
    {
        if (!$this->isActive) return;

        $this->currentRecoveredAmount += $profitUsd;

        // If accumulated profit exceeds the loss, turn off Smart Mode!
        if ($this->currentRecoveredAmount >= $this->targetRecoveryAmount) {
            $this->isActive = false;
            $this->targetRecoveryAmount = 0.0;
            $this->currentRecoveredAmount = 0.0;
            $this->lockedCapital = 0.0;
        }
    }

    public function isSmartModeActive(): bool
    {
        return $this->isActive;
    }

    public function getActiveCapital(): float
    {
        return $this->lockedCapital;
    }

    public function getRecoveryProgress(): array
    {
        return [
            'target' => $this->targetRecoveryAmount,
            'recovered' => $this->currentRecoveredAmount,
            'remaining_loss' => max(0, $this->targetRecoveryAmount - $this->currentRecoveredAmount)
        ];
    }
}