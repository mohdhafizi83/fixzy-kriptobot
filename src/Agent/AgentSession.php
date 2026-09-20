<?php

namespace Fixzy\Kriptobot\Agent;

use Doctrine\DBAL\Connection;
use Fixzy\Kriptobot\Database\Database;

class AgentSession
{
    private Connection $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function create(int $userId, ?string $title = null, string $lang = 'en'): int
    {
        $this->db->executeStatement(
            "INSERT INTO agent_sessions (user_id, title, context, language, status)
             VALUES (?, ?, ?, ?, 'active')",
            [
                $userId,
                $title ?? 'Chat ' . date('Y-m-d H:i'),
                json_encode([
                    'objective'      => '',
                    'risk_profile'   => null,
                    'selected_pairs' => [],
                    'last_proposal'  => null,
                    'iteration'      => 0,
                ]),
                $lang,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    public function get(int $sessionId): ?array
    {
        $row = $this->db->executeQuery(
            "SELECT * FROM agent_sessions WHERE id = ?", [$sessionId]
        )->fetchAssociative();

        if (!$row) return null;

        $row['context'] = json_decode($row['context'], true) ?? [];
        return $row;
    }

    public function getActiveSession(int $userId): ?array
    {
        $row = $this->db->executeQuery(
            "SELECT * FROM agent_sessions WHERE user_id = ? AND status = 'active' ORDER BY updated_at DESC LIMIT 1",
            [$userId]
        )->fetchAssociative();

        if (!$row) return null;

        $row['context'] = json_decode($row['context'], true) ?? [];
        return $row;
    }

    public function getSessionsForUser(int $userId): array
    {
        return $this->db->executeQuery(
            "SELECT id, title, language, status, created_at, updated_at FROM agent_sessions WHERE user_id = ? ORDER BY updated_at DESC LIMIT 20",
            [$userId]
        )->fetchAllAssociative();
    }

    public function updateContext(int $sessionId, array $context): void
    {
        $this->db->update('agent_sessions', [
            'context' => json_encode($context),
        ], ['id' => $sessionId]);
    }

    public function updateTitle(int $sessionId, string $title): void
    {
        $this->db->update('agent_sessions', [
            'title' => $title,
        ], ['id' => $sessionId]);
    }

    public function archive(int $sessionId): void
    {
        $this->db->update('agent_sessions', [
            'status' => 'archived',
        ], ['id' => $sessionId]);
    }

    /**
     * Returns messages for a session in LLM-compatible format.
     */
    public function getMessagesForLlm(int $sessionId, int $limit = 30): array
    {
        $rows = $this->db->executeQuery(
            "SELECT role, content, tool_calls, tool_results FROM agent_messages WHERE session_id = ? ORDER BY created_at ASC LIMIT " . (int)$limit,
            [$sessionId]
        )->fetchAllAssociative();

        $messages = [];
        foreach ($rows as $row) {
            $msg = ['role' => $row['role'], 'content' => $row['content']];

            if ($row['role'] === 'assistant' && !empty($row['tool_calls'])) {
                $toolCalls = json_decode($row['tool_calls'], true);
                if ($toolCalls) {
                    $msg['tool_calls'] = $toolCalls;
                }
            }

            if ($row['role'] === 'tool' && !empty($row['tool_results'])) {
                $decoded = json_decode($row['tool_results'], true);
                $msg['tool_call_id'] = is_array($decoded) ? ($decoded['tool_call_id'] ?? '') : '';
            }

            $messages[] = $msg;
        }

        return $messages;
    }

    public function addMessage(int $sessionId, string $role, string $content, ?array $toolCalls = null, ?array $toolResults = null, int $tokenUsage = 0): int
    {
        $this->db->executeStatement(
            "INSERT INTO agent_messages (session_id, role, content, tool_calls, tool_results, token_usage)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $sessionId,
                $role,
                $content,
                $toolCalls ? json_encode($toolCalls) : null,
                $toolResults ? json_encode($toolResults) : null,
                $tokenUsage,
            ]
        );
        $msgId = (int) $this->db->lastInsertId();

        $this->touchSession($sessionId);

        return $msgId;
    }

    public function getMessages(int $sessionId, int $limit = 50): array
    {
        return $this->db->executeQuery(
            "SELECT * FROM agent_messages WHERE session_id = ? ORDER BY created_at ASC LIMIT " . (int)$limit,
            [$sessionId]
        )->fetchAllAssociative();
    }

    private function touchSession(int $sessionId): void
    {
        $this->db->executeStatement(
            "UPDATE agent_sessions SET updated_at = NOW() WHERE id = ?",
            [$sessionId]
        );
    }
}
