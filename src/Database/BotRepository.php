<?php

namespace Fixzy\Kriptobot\Database;

use Doctrine\DBAL\Connection;

/**
 * BotRepository — Data access layer for the bots table.
 *
 * Handles fetching enabled bots (status = 1) and updating
 * runtime_state (JSON) during daemon operation.
 *
 * @package Fixzy\Kriptobot\Database
 */
class BotRepository
{
    private Connection $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Get all enabled bots with configuration and runtime_state
     * already decoded from JSON.
     *
     * @return array List of bots with keys: id, user_id, coin_pair, allocated_capital,
     *               status, configuration (array), runtime_state (array), parent_id
     */
    public function getAllEnabledBots(): array
    {
        $sql    = "SELECT * FROM bots WHERE status = 1";
        $result = $this->db->executeQuery($sql);
        $bots   = $result->fetchAllAssociative();

        foreach ($bots as &$bot) {
            $bot['configuration'] = json_decode($bot['configuration'], true);
            $bot['runtime_state'] = json_decode($bot['runtime_state'], true);
        }

        return $bots;
    }

    /**
     * Update the runtime_state for a specific bot.
     *
     * @param int   $botId Bot ID
     * @param array $state New state (will be encoded to JSON)
     */
    public function updateRuntimeState(int $botId, array $state): void
    {
        $this->db->update('bots', [
            'runtime_state' => json_encode($state),
        ], ['id' => $botId]);
    }
}
