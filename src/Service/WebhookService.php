<?php

namespace Fixzy\Kriptobot\Service;

use Doctrine\DBAL\Connection;
use Fixzy\Kriptobot\Database\Database;

/**
 * WebhookService — inbound signal handling (TradingView / external signals).
 *
 * Owns the SQL that webhook endpoints used to contain so that no SQL lives
 * in public/.
 */
class WebhookService
{
    private Connection $db;

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

        $state = json_decode((string)$bot['runtime_state'], true) ?: [];
        if ($type === 'tv_webhook') {
            $state['latest_tv_signal'] = $signal;
        } else {
            $state['latest_external_signal'] = $signal;
        }
        $this->db->executeStatement(
            "UPDATE bots SET runtime_state = ? WHERE id = ?",
            [json_encode($state), $botId]
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
