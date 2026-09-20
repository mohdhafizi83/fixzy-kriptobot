<?php

namespace Fixzy\Kriptobot\Trading;

use Exception;

class BotStateController
{
    /**
     * Function for the UI to update the bot's Enable/Disable status
     * * @param int $botId Bot ID in the database
     * @param bool $requestedStatus True (Enable) or False (Disable)
     * @param float $currentCoinHoldings Number of coins currently held by this bot
     * @return string Success message
     */
    public function toggleBotStatus(int $botId, bool $requestedStatus, float $currentCoinHoldings): string
    {
        // IDLE STATUS CHECK
        // If the bot holds any coins (> 0), it is CURRENTLY TRADING (not idle)
        if ($currentCoinHoldings > 0) {
            throw new Exception("WARNING: Bot status cannot be changed because the bot is actively trading (has holdings). Please wait until the bot has sold all assets (returns to IDLE status).");
        }

        // If the check passes (idle), allow the update to the database
        $statusText = $requestedStatus ? 'ENABLED' : 'DISABLED';
        
        // Your actual database code would go here
        // $stmt = $this->db->prepare("UPDATE bots SET is_enabled = ? WHERE id = ?");
        // $stmt->execute([$requestedStatus, $botId]);

        return "Success: Bot #$botId has been $statusText.";
    }
}