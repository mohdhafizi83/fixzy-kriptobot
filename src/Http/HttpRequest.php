<?php

namespace Fixzy\Kriptobot\Http;

/**
 * HttpRequest — small helpers for reading the incoming API request.
 */
class HttpRequest
{
    /**
     * HTTP method, uppercased.
     */
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * Parsed request body: JSON body first, then form-encoded fallback.
     *
     * @return array<string, mixed>
     */
    public static function body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $_POST;
    }

    /**
     * Bearer token from the Authorization header (mobile / non-browser clients).
     */
    public static function bearerToken(): ?string
    {
        $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /**
     * API route path. Uses PATH_INFO when available (e.g. /api/v1/index.php/bots/3)
     * and falls back to a ?route= query parameter.
     */
    public static function path(): string
    {
        $path = (string)($_SERVER['PATH_INFO'] ?? '');
        if ($path === '' && isset($_GET['route'])) {
            $path = (string)$_GET['route'];
        }
        return '/' . trim($path, '/');
    }

    /**
     * CSRF token from JSON body, form post, or X-CSRF-Token header.
     */
    public static function csrfToken(array $body): string
    {
        $fromBody = $body['csrf_token'] ?? $_POST['csrf_token'] ?? '';
        $fromHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        return (string)($fromBody !== '' ? $fromBody : $fromHeader);
    }
}
