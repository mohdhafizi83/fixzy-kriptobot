<?php

declare(strict_types=1);

namespace Fixzy\Kriptobot\Security;

/**
 * SecurityHeaders — consistent hardening headers on every response.
 *
 * CSP allows the CDNs the UI actually uses (Tailwind, Alpine, axios,
 * Chart.js, Tom Select) plus inline scripts/styles required by Alpine's
 * x-data attributes. frame-ancestors 'none' + X-Frame-Options DENY
 * block clickjacking entirely.
 */
class SecurityHeaders
{
    public static function send(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('X-XSS-Protection: 0'); // disabled deliberately; CSP is the modern control
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdn.jsdelivr.net",
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",
            "img-src 'self' data:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
        header('Content-Security-Policy: ' . $csp);

        $secure = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        if ($secure) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}
