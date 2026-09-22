<?php

declare(strict_types=1);

namespace Fixzy\Kriptobot\Security;

use Fixzy\Kriptobot\Config\Config;

/**
 * SessionGuard — hardened session lifecycle for all web endpoints.
 *
 * - Secure cookie flags (HttpOnly, SameSite=Lax, Secure on HTTPS)
 * - Idle timeout (default 8 hours)
 * - Session ID regeneration on login (fixation protection)
 * - CSRF token helpers
 */
class SessionGuard
{
    private static bool $started = false;

    public const IDLE_TIMEOUT_SECONDS = 28800; // 8 hours

    public static function start(): void
    {
        if (self::$started) {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        $secure = self::isHttps();

        session_name('kb_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        self::$started = true;

        // Idle timeout: log out stale sessions.
        $last = (int)($_SESSION['last_activity'] ?? 0);
        if ($last > 0 && (time() - $last) > self::IDLE_TIMEOUT_SECONDS) {
            self::logout();
            self::start();
            if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'login.php') {
                header('Location: login.php?timeout=1');
                exit;
            }
        }
        $_SESSION['last_activity'] = time();
    }

    public static function isAuthenticated(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    /**
     * Gate for browser pages: redirect to setup when the install has not been
     * configured yet, or to login when unauthenticated.
     */
    public static function requireWeb(): void
    {
        self::start();
        if (self::setupPending()) {
            header('Location: setup.php');
            exit;
        }
        if (!self::isAuthenticated()) {
            header('Location: login.php');
            exit;
        }
    }

    /**
     * True when the admin account still has no password (fresh install).
     * Cached per request; safe when the DB is missing (treated as pending).
     */
    public static function setupPending(): bool
    {
        static $pending = null;
        if ($pending === null) {
            try {
                $pending = (new \Fixzy\Kriptobot\Service\SetupService())->setupRequired();
            } catch (\Throwable $e) {
                $pending = true;
            }
        }
        return $pending;
    }

    /**
     * Establish an authenticated session after successful credential check.
     */
    public static function login(int $userId, string $email): void
    {
        // New session ID on privilege change — prevents session fixation.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_email'] = $email;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['last_activity'] = time();
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        self::$started = false;
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['csrf_token'];
    }

    public static function verifyCsrf(string $token): bool
    {
        return !empty($_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], $token);
    }

    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            return true;
        }
        return (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }
}
