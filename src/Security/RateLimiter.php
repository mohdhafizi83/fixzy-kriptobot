<?php

declare(strict_types=1);

namespace Fixzy\Kriptobot\Security;

/**
 * RateLimiter — fixed-window rate limiting for auth-sensitive endpoints.
 *
 * Uses APCu when available (fast, shared across workers). Falls back to
 * small files under storage/ratelimit so it also works on plain shared
 * hosting without APCu.
 *
 * Typical usage:
 *   if ($limiter->tooMany('login:' . $ip, 10, 900)) { ... reject 429 ... }
 */
class RateLimiter
{
    private bool $apcu;
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->apcu = function_exists('apcu_enabled') && apcu_enabled();
        $this->dir = $dir ?? dirname(__DIR__, 2) . '/storage/ratelimit';
        if (!$this->apcu && !is_dir($this->dir)) {
            @mkdir($this->dir, 0700, true);
        }
    }

    /**
     * Returns true when the caller has exceeded $max hits within $window seconds.
     */
    public function tooMany(string $key, int $max, int $windowSeconds): bool
    {
        $bucket = (int)floor(time() / $windowSeconds);
        $slot = $key . '|' . $bucket;

        if ($this->apcu) {
            $count = (int)apcu_inc($slot, 1, $found, $windowSeconds);
            if (!$found) {
                // apcu_inc on missing key may not set TTL; store explicitly.
                apcu_store($slot, 1, $windowSeconds);
                $count = 1;
            }
            return $count > $max;
        }

        $file = $this->dir . '/' . sha1($slot) . '.count';
        $count = 1;
        if (is_readable($file)) {
            $count = (int)@file_get_contents($file) + 1;
        }
        @file_put_contents($file, (string)$count, LOCK_EX);
        return $count > $max;
    }

    /**
     * Reset the counter for a key (e.g. after successful login).
     */
    public function clear(string $key): void
    {
        $bucket = (int)floor(time() / 3600);
        $slot = $key . '|' . $bucket;
        if ($this->apcu) {
            apcu_delete($slot);
        } else {
            @unlink($this->dir . '/' . sha1($slot) . '.count');
        }
    }

    /**
     * Best-effort client IP. Note: X-Forwarded-For is only trustworthy behind a
     * reverse proxy; on shared hosting REMOTE_ADDR is set by the provider.
     */
    public static function clientIp(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }
}
