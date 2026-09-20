<?php

namespace Fixzy\Kriptobot\Service;

use Doctrine\DBAL\Connection;
use Fixzy\Kriptobot\Database\Database;

/**
 * ApiTokenService — token-based authentication for non-browser clients
 * (mobile app, scripts). Tokens are stored as SHA-256 hashes; the plaintext
 * token is shown exactly once at creation time.
 *
 * Token format: kb_<32 hex id>_<48 hex secret>
 * Stored: hash = sha256(full token), so verification is a single lookup.
 */
class ApiTokenService
{
    private Connection $db;

    public function __construct(?Connection $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * Ensure the api_tokens table exists (idempotent).
     */
    public function ensureSchema(): void
    {
        $this->db->executeStatement("CREATE TABLE IF NOT EXISTS api_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            token_hash TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at TEXT DEFAULT NULL,
            revoked_at TEXT DEFAULT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
    }

    /**
     * Create a new token. Returns ['token' => plaintext, 'id' => int].
     * The plaintext is never recoverable after this call.
     *
     * @return array{token: string, id: int, name: string}
     */
    public function create(int $userId, string $name): array
    {
        $this->ensureSchema();
        $id = bin2hex(random_bytes(8));
        $secret = bin2hex(random_bytes(24));
        $token = 'kb_' . $id . '_' . $secret;

        $this->db->insert('api_tokens', [
            'user_id'    => $userId,
            'name'       => $name !== '' ? $name : 'api-token',
            'token_hash' => hash('sha256', $token),
        ]);

        return ['token' => $token, 'id' => (int)$this->db->lastInsertId(), 'name' => $name];
    }

    /**
     * Verify a plaintext token. Returns the owning user_id or null.
     */
    public function verify(string $token): ?int
    {
        if ($token === '' || !str_starts_with($token, 'kb_')) {
            return null;
        }
        $this->ensureSchema();
        $row = $this->db->fetchAssociative(
            "SELECT user_id FROM api_tokens WHERE token_hash = ? AND revoked_at IS NULL",
            [hash('sha256', $token)]
        );
        if (!$row) {
            return null;
        }
        // Best-effort last_used tracking (do not fail auth on this).
        try {
            $this->db->executeStatement(
                "UPDATE api_tokens SET last_used_at = CURRENT_TIMESTAMP WHERE token_hash = ?",
                [hash('sha256', $token)]
            );
        } catch (\Throwable $e) {
            // ignore
        }
        return (int)$row['user_id'];
    }

    /**
     * List tokens (without secrets) for the settings UI.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        $this->ensureSchema();
        return $this->db->fetchAllAssociative(
            "SELECT id, name, created_at, last_used_at, revoked_at FROM api_tokens WHERE user_id = ? ORDER BY id DESC",
            [$userId]
        );
    }

    /**
     * Revoke a token by id (must belong to the user). Returns true if revoked.
     */
    public function revoke(int $userId, int $tokenId): bool
    {
        $this->ensureSchema();
        $affected = $this->db->executeStatement(
            "UPDATE api_tokens SET revoked_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ? AND revoked_at IS NULL",
            [$tokenId, $userId]
        );
        return $affected > 0;
    }
}
