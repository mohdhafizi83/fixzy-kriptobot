<?php

namespace Fixzy\Kriptobot\Service;

use Doctrine\DBAL\Connection;
use Fixzy\Kriptobot\Database\Database;

/**
 * WebhookService — inbound signal handling (TradingView / external signals).
 *
 * Owns the SQL that webhook endpoints used to contain so that no SQL lives
 * in public/.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class WebhookService
{
    private Connection $db;

    /**
     * __construct.
     * @param Connection|null $db (default: null)
     */
    public function __construct(?Connection $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * Expose the underlying connection (for audit logging in webhook endpoints).
     */
    public function getConnection(): Connection
    {
        return $this->db;
    }

    /**
     * Inject a signal into a bot's runtime state.
     *
     * @return array{status: string, message: string, user_id?: int}
     */
    public function injectSignal(int $botId, string $signal, string $type): array
    {
        $bot = $this->db->fetchAssociative(
            "SELECT runtime_state, status, user_id FROM bots WHERE id = ?",
            [$botId]
        );

        if (!$bot) {
            return ['status' => 'not_found', 'message' => "Bot ID #{$botId} not found."];
        }

        if (!(bool)$bot['status']) {
            return ['status' => 'ignored', 'message' => "Bot ID #{$botId} is disabled (OFF)."];
        }

        $jsonKey = $type === 'tv_webhook' ? '$.latest_tv_signal' : '$.latest_external_signal';
        // Targeted write: a wholesale state write here could erase a fresh
        // ai_l3 pause or ai_copilot_cache written mid-tick by the AI loop.
        $this->db->executeStatement(
            "UPDATE bots SET runtime_state = json_set(
                 CASE WHEN json_valid(runtime_state) AND json_type(runtime_state) = 'object'
                      THEN runtime_state ELSE '{}' END,
                 '{$jsonKey}', ?) WHERE id = ?",
            [$signal, $botId]
        );

        return [
            'status'  => 'success',
            'message' => "Signal '{$signal}' injected into Bot #{$botId} memory.",
            'user_id' => (int)$bot['user_id'],
        ];
    }

    /**
     * Find the user id linked to a Telegram chat id.
     */
    public function findUserIdByTelegramChatId(string $chatId): ?int
    {
        $row = $this->db->fetchAssociative(
            "SELECT id FROM users WHERE telegram_chat_id = ?",
            [$chatId]
        );
        return $row ? (int)$row['id'] : null;
    }
}
