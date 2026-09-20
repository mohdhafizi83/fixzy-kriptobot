<?php

namespace Fixzy\Kriptobot\Service;

use Doctrine\DBAL\Connection;
use Fixzy\Kriptobot\Database\Database;

/**
 * AgentSettingsService — read/write the agent_settings table.
 *
 * The read path (with defaults) lives in ApprovalManager::getAgentSettings();
 * this service owns the write path used by the Web UI and the REST API.
 */
class AgentSettingsService
{
    private Connection $db;

    public const KNOWN_ACTIONS = [
        'analyze_market', 'view_data', 'run_backtest',
        'create_bot', 'update_config', 'activate_bot', 'deactivate_bot',
    ];

    public function __construct(?Connection $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * Upsert agent settings for a user.
     *
     * @param array{agent_enabled?: bool, autonomy_mode?: string, max_capital_per_bot?: float,
     *              max_total_capital?: float, allowed_actions?: array<int, string>,
     *              telegram_notifications?: bool, language_preference?: string} $settings
     */
    public function update(int $userId, array $settings): void
    {
        $allowed = $settings['allowed_actions'] ?? [];
        if (!is_array($allowed)) {
            $allowed = [];
        }
        // Only accept known action names.
        $allowed = array_values(array_intersect($allowed, self::KNOWN_ACTIONS));

        $payload = [
            'agent_enabled'          => !empty($settings['agent_enabled']) ? 1 : 0,
            'autonomy_mode'          => in_array($settings['autonomy_mode'] ?? '', ['approval_required', 'full_autonomy'], true)
                ? $settings['autonomy_mode'] : 'approval_required',
            'max_capital_per_bot'    => (float)($settings['max_capital_per_bot'] ?? 1000),
            'max_total_capital'      => (float)($settings['max_total_capital'] ?? 10000),
            'allowed_actions'        => json_encode($allowed),
            'telegram_notifications' => !empty($settings['telegram_notifications']) ? 1 : 0,
            'language_preference'    => (string)($settings['language_preference'] ?? 'auto'),
            'updated_at'             => date('Y-m-d H:i:s'),
        ];

        $existing = $this->db->fetchAssociative(
            "SELECT id FROM agent_settings WHERE user_id = ?",
            [$userId]
        );

        if ($existing) {
            $this->db->update('agent_settings', $payload, ['user_id' => $userId]);
        } else {
            $this->db->insert('agent_settings', array_merge(['user_id' => $userId], $payload));
        }
    }

    /**
     * Seed default agent settings if none exist (used by the agent page bootstrap).
     */
    public function seedIfMissing(int $userId, array $defaults): void
    {
        $exists = $this->db->fetchAssociative(
            "SELECT id FROM agent_settings WHERE user_id = ?",
            [$userId]
        );
        if (!$exists) {
            $this->update($userId, $defaults);
        }
    }
}
