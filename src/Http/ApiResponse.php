<?php

namespace Fixzy\Kriptobot\Http;

/**
 * ApiResponse — standard JSON envelope for every API response.
 *
 * Shape: { "ok": bool, "data": mixed|null, "error": string|null }
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class ApiResponse
{
    /**
     * Send a JSON payload and terminate the request.
     */
    public static function send(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Successful response with optional data payload.
     */
    public static function ok(mixed $data = null): void
    {
        self::send(['ok' => true, 'data' => $data, 'error' => null]);
    }

    /**
     * Error response with a human-readable message and HTTP status code.
     */
    public static function error(string $message, int $status = 400, mixed $data = null): void
    {
        self::send(['ok' => false, 'data' => $data, 'error' => $message], $status);
    }
}
