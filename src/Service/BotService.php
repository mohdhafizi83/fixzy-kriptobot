<?php

namespace Fixzy\Kriptobot\Service;

use Doctrine\DBAL\Connection;
use Fixzy\Kriptobot\Database\Database;
use RuntimeException;

/**
 * BotService — backend-owned business logic for the bots table.
 *
 * All bot CRUD used by the Web UI and the REST API goes through here.
 * Frontend pages must never touch the bots table directly.
 */
class BotService
{
    private Connection $db;

    public function __construct(?Connection $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * All bots for a user with JSON columns decoded, split into parents and clones.
     *
     * @return array{parents: array<int, array<string, mixed>>, clones: array<int, array<int, array<string, mixed>>>}
     */
    public function listGrouped(int $userId): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT * FROM bots WHERE user_id = ? ORDER BY id DESC",
            [$userId]
        );

        $parents = [];
        $clones  = [];
        foreach ($rows as $bot) {
            $bot['configuration'] = json_decode((string)$bot['configuration'], true) ?: [];
            $bot['runtime_state'] = json_decode((string)$bot['runtime_state'], true) ?: [];
            if (!empty($bot['parent_id'])) {
                $clones[(int)$bot['parent_id']][] = $bot;
            } else {
                $parents[] = $bot;
            }
        }

        return ['parents' => $parents, 'clones' => $clones];
    }

    /**
     * Flat list of bots for a user (JSON decoded).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAll(int $userId): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT * FROM bots WHERE user_id = ? ORDER BY id DESC",
            [$userId]
        );
        foreach ($rows as &$bot) {
            $bot['configuration'] = json_decode((string)$bot['configuration'], true) ?: [];
            $bot['runtime_state'] = json_decode((string)$bot['runtime_state'], true) ?: [];
        }
        return $rows;
    }

    /**
     * Fetch a single bot owned by the user.
     */
    public function get(int $userId, int $botId): ?array
    {
        $row = $this->db->fetchAssociative(
            "SELECT * FROM bots WHERE id = ? AND user_id = ? LIMIT 1",
            [$botId, $userId]
        );
        if (!$row) {
            return null;
        }
        $row['configuration'] = json_decode((string)$row['configuration'], true) ?: [];
        $row['runtime_state'] = json_decode((string)$row['runtime_state'], true) ?: [];
        return $row;
    }

    /**
     * Create or update a bot from the editor payload.
     *
     * Payload shape (same contract the Web UI editor sends):
     *   is_enabled: bool, general: { custom_pairs, capital, ... }, plus full config tree.
     *
     * @return array{bot_id: int, created: bool}
     */
    public function save(int $userId, ?int $botId, array $payload): array
    {
        $isEnabled = !empty($payload['is_enabled']) ? 1 : 0;

        $customPairsRaw = $payload['general']['custom_pairs'] ?? '';
        $coinPairStr = is_array($customPairsRaw) ? implode(',', $customPairsRaw) : (string)$customPairsRaw;
        $coinPair = trim(strtoupper($coinPairStr));

        // allocated_capital column: AUTO is stored as 0.0; the daemon reads the
        // real sizing rule from the JSON configuration.
        $capitalRaw = $payload['general']['capital'] ?? 0.0;
        $capital = $capitalRaw === 'AUTO' ? 0.0 : (float)$capitalRaw;

        // Merge over the existing configuration so partial updates never wipe
        // sections (base_order/dca/risk_management) that the client omitted.
        $configToSave = $payload;
        unset($configToSave['is_enabled'], $configToSave['csrf_token'], $configToSave['runtime_state']);
        if ($botId) {
            $existing = $this->get($userId, $botId);
            $configToSave = $this->deepMerge($existing['configuration'] ?? [], $configToSave);
        }
        $configJson = json_encode($configToSave);
        if ($configJson === false) {
            throw new RuntimeException('Failed to encode the JSON configuration. The data may contain invalid characters.');
        }

        if ($botId) {
            $affected = $this->db->executeStatement(
                "UPDATE bots SET coin_pair = ?, allocated_capital = ?, status = ?, configuration = ? WHERE id = ? AND user_id = ?",
                [$coinPair, $capital, $isEnabled, $configJson, $botId, $userId]
            );
            if ($affected === 0) {
                throw new RuntimeException("Bot #{$botId} not found for this user.");
            }
            return ['bot_id' => $botId, 'created' => false];
        }

        $initialState = [
            'status'               => 'IDLE',
            'current_holdings'     => 0.0,
            'average_entry_price'  => 0.0,
            'live_pnl_percent'     => 0.0,
            'dca_current_step'     => 0,
            'latest_tv_signal'     => '',
            'latest_external_signal' => '',
            'latest_ai_sentiment'  => '',
        ];
        $this->db->insert('bots', [
            'user_id'           => $userId,
            'coin_pair'         => $coinPair,
            'allocated_capital' => $capital,
            'status'            => $isEnabled,
            'configuration'     => $configJson,
            'runtime_state'     => json_encode($initialState),
        ]);

        return ['bot_id' => (int)$this->db->lastInsertId(), 'created' => true];
    }

    /**
     * Recursively merge $override on top of $base.
     * Associative arrays merge key-by-key; lists (sequential arrays) and scalars
     * are replaced wholesale by the override.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (
                is_array($value) && isset($base[$key]) && is_array($base[$key])
                && !array_is_list($value) && !array_is_list($base[$key])
            ) {
                $base[$key] = $this->deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    /**
     * Toggle a bot ON/OFF.
     */
    public function setActive(int $userId, int $botId, bool $on): void
    {
        $affected = $this->db->executeStatement(
            "UPDATE bots SET status = ? WHERE id = ? AND user_id = ?",
            [$on ? 1 : 0, $botId, $userId]
        );
        if ($affected === 0) {
            throw new RuntimeException("Bot #{$botId} not found for this user.");
        }
    }

    /**
     * Delete a bot and its clone (composite deal) rows.
     *
     * If the deleted bot was a recovery bot, all other bots of the user are
     * re-enabled (recovery mode cancellation semantics).
     *
     * @return array{deleted: bool, recovery_cancelled: bool}
     */
    public function delete(int $userId, int $botId): array
    {
        $bot = $this->db->fetchAssociative(
            "SELECT runtime_state FROM bots WHERE id = ? AND user_id = ?",
            [$botId, $userId]
        );
        if (!$bot) {
            throw new RuntimeException("Bot #{$botId} not found for this user.");
        }

        $state = json_decode((string)$bot['runtime_state'], true) ?: [];
        $recoveryCancelled = false;

        if (!empty($state['is_recovery_bot'])) {
            $this->db->executeStatement(
                "UPDATE bots SET status = 1 WHERE user_id = ? AND id != ?",
                [$userId, $botId]
            );
            $recoveryCancelled = true;
        }

        $this->db->executeStatement("DELETE FROM bots WHERE id = ?", [$botId]);
        $this->db->executeStatement("DELETE FROM bots WHERE parent_id = ?", [$botId]);

        return ['deleted' => true, 'recovery_cancelled' => $recoveryCancelled];
    }
}
