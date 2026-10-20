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
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class BotRepository
{
    private Connection $db;

    /**
     * __construct.
     */
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
        // Audit-full #5: the AI loop / escalation executor write their keys
        // (ai_copilot_cache, ai_l3) concurrently via json_set. A wholesale
        // write built from a tick-start read must not resurrect stale AI
        // state, so re-read and preserve those keys from the live row.
        $live = $this->db->fetchOne('SELECT runtime_state FROM bots WHERE id = ?', [$botId]);
        $liveArr = json_decode((string)$live, true);
        if (is_array($liveArr)) {
            foreach (['ai_copilot_cache', 'ai_l3'] as $aiKey) {
                if (array_key_exists($aiKey, $liveArr)) {
                    $state[$aiKey] = $liveArr[$aiKey];
                } elseif (array_key_exists($aiKey, $state)) {
                    unset($state[$aiKey]); // live row removed it: do not resurrect
                }
            }
        }
        $this->db->update('bots', [
            'runtime_state' => json_encode($state),
        ], ['id' => $botId]);
    }
}
