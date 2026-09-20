<?php

namespace Fixzy\Kriptobot\Trading\Rules;

use Doctrine\DBAL\Connection;

/**
 * ExecutionGuardService — Security layer that prevents unsafe buys.
 *
 * Three layered checks:
 *   1. Blacklist (bot-level + global) — block blacklisted coins
 *   2. Cooldown — prevent re-buying the same coin during the cool-down period
 *   3. Max Active Deals — limit the number of concurrent deals (Composite Bot)
 *
 * @package Fixzy\Kriptobot\Trading\Rules
 */
class ExecutionGuardService
{
    private Connection $db;
    private array $lastRejectionContext = [];

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Check whether the bot is allowed to buy a specific coin.
     *
     * @param int    $userId          User ID
     * @param string $coinPair        Coin pair (e.g. 'BTC/USDT')
     * @param int    $cooldownSeconds Cooldown duration in seconds
     * @param int    $maxActiveDeals  Limit on concurrent deals
     * @param string $botBlacklist    Bot-level blacklist (comma-separated, optional)
     * @param int    $botId           Current bot ID
     *
     * @return bool TRUE if buying is allowed
     */
    public function canBuy(
        int $userId,
        string $coinPair,
        int $cooldownSeconds,
        int $maxActiveDeals,
        string $botBlacklist = '',
        int $botId = 0
    ): bool {
        $this->lastRejectionContext = [];

        if ($this->isBlacklisted($userId, $coinPair, $botBlacklist)) {
            $this->lastRejectionContext = [
                'reason'      => 'BLACKLIST',
                'coin_pair'   => $coinPair,
                'bot_blacklist' => $botBlacklist,
            ];
            return false;
        }

        $cooldownResult = $this->isCooldownPassed($userId, $coinPair, $cooldownSeconds);
        if (!$cooldownResult) {
            $this->lastRejectionContext = [
                'reason'           => 'COOLDOWN',
                'coin_pair'        => $coinPair,
                'cooldown_seconds' => $cooldownSeconds,
                'time_remaining'   => $this->lastCooldownRemaining,
            ];
            return false;
        }

        if ($this->hasReachedMaxDeals($userId, $maxActiveDeals, $botId)) {
            $this->lastRejectionContext = [
                'reason'           => 'MAX_DEALS',
                'max_active_deals'  => $maxActiveDeals,
                'current_active'   => $this->lastActiveCount,
            ];
            return false;
        }

        return true;
    }

    /**
     * Purchase check for backtesting (simulation) — uses the timestamp and active
     * deals supplied by the backtest engine instead of querying the DB directly.
     *
     * @param int    $userId             User ID
     * @param string $coinPair           Coin pair (e.g. 'BTC/USDT')
     * @param int    $cooldownSeconds    Cooldown duration in seconds
     * @param int    $maxActiveDeals     Limit on concurrent deals
     * @param string $botBlacklist       Bot-level blacklist
     * @param int    $botId              Current bot ID
     * @param int    $simulatedNow       Simulation timestamp (seconds)
     * @param int    $lastTradeTimestamp Last trade timestamp in the simulation (0 if none)
     * @param int    $currentActiveDeals Number of currently active deals in the simulation
     *
     * @return bool TRUE if buying is allowed
     */
    public function canBuyForBacktest(
        int $userId,
        string $coinPair,
        int $cooldownSeconds,
        int $maxActiveDeals,
        string $botBlacklist = '',
        int $botId = 0,
        int $simulatedNow = 0,
        int $lastTradeTimestamp = 0,
        int $currentActiveDeals = 0
    ): bool {
        $this->lastRejectionContext = [];

        if ($this->isBlacklisted($userId, $coinPair, $botBlacklist)) {
            $this->lastRejectionContext = [
                'reason'       => 'BLACKLIST',
                'coin_pair'    => $coinPair,
                'bot_blacklist' => $botBlacklist,
            ];
            return false;
        }

        if ($cooldownSeconds > 0 && $lastTradeTimestamp > 0) {
            $elapsed = $simulatedNow - $lastTradeTimestamp;
            if ($elapsed < $cooldownSeconds) {
                $this->lastRejectionContext = [
                    'reason'           => 'COOLDOWN',
                    'coin_pair'        => $coinPair,
                    'cooldown_seconds' => $cooldownSeconds,
                    'time_remaining'   => $cooldownSeconds - $elapsed,
                ];
                return false;
            }
        }

        if ($maxActiveDeals > 0 && $currentActiveDeals >= $maxActiveDeals) {
            $this->lastRejectionContext = [
                'reason'          => 'MAX_DEALS',
                'max_active_deals' => $maxActiveDeals,
                'current_active'  => $currentActiveDeals,
            ];
            return false;
        }

        return true;
    }

    /**
     * Get the last rejection context for audit logging.
     */
    public function getLastRejectionContext(): array
    {
        return $this->lastRejectionContext;
    }

    private int $lastCooldownRemaining = 0;
    private int $lastActiveCount = 0;

    private function isBlacklisted(int $userId, string $coinPair, string $botBlacklist = ''): bool
    {
        $baseCoin = explode('/', $coinPair)[0];

        if (!empty($botBlacklist)) {
            $botList = array_map('trim', explode(',', strtoupper($botBlacklist)));
            if (in_array($baseCoin, $botList) || in_array($coinPair, $botList)) {
                return true;
            }
        }

        $row = $this->db->executeQuery(
            "SELECT global_filters FROM users WHERE id = ?",
            [$userId]
        )->fetchAssociative();

        if (!$row || empty($row['global_filters'])) {
            return false;
        }

        $filters = json_decode($row['global_filters'], true);
        if (!$filters) {
            return false;
        }

        $blacklistStr = $filters['blacklist'] ?? '';
        if (empty($blacklistStr)) {
            return false;
        }

        $blacklist = array_map('trim', explode(',', strtoupper($blacklistStr)));

        return in_array($baseCoin, $blacklist) || in_array($coinPair, $blacklist);
    }

    private function isCooldownPassed(int $userId, string $coinPair, int $cooldownSeconds): bool
    {
        $row = $this->db->executeQuery(
            "SELECT t.created_at
             FROM trade_logs t
             JOIN bots b ON t.bot_id = b.id
             WHERE b.user_id = ? AND b.coin_pair = ?
             ORDER BY t.created_at DESC LIMIT 1",
            [$userId, $coinPair]
        )->fetchAssociative();

        if (!$row) {
            $this->lastCooldownRemaining = 0;
            return true;
        }

        $lastTradeTime = strtotime($row['created_at']);
        $elapsed = time() - $lastTradeTime;
        $this->lastCooldownRemaining = max(0, $cooldownSeconds - $elapsed);

        return $elapsed >= $cooldownSeconds;
    }

    private function hasReachedMaxDeals(int $userId, int $maxActiveDeals, int $botId): bool
    {
        $rows = $this->db->executeQuery(
            "SELECT runtime_state FROM bots WHERE user_id = ? AND status = 1 AND (id = ? OR parent_id = ?)",
            [$userId, $botId, $botId]
        )->fetchAllAssociative();

        $activeCount = 0;

        foreach ($rows as $row) {
            $state = json_decode($row['runtime_state'], true);
            if ($state && ($state['status'] ?? '') === 'ACTIVE') {
                $activeCount++;
            }
        }

        $this->lastActiveCount = $activeCount;

        return $activeCount >= $maxActiveDeals;
    }
}
