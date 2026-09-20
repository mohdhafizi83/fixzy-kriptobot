<?php

namespace Fixzy\Kriptobot\Service;

use Doctrine\DBAL\Connection;
use Fixzy\Kriptobot\Database\Database;

/**
 * MarketService — read-only market data (price ticks, cached pair list).
 */
class MarketService
{
    private Connection $db;

    public function __construct(?Connection $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * Recent price ticks for a bot, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentTicks(int $botId, int $limit = 250): array
    {
        $limit = max(1, min($limit, 1000));
        $ticks = $this->db->fetchAllAssociative(
            "SELECT price, pnl_percent, created_at FROM price_ticks WHERE bot_id = ? ORDER BY id DESC LIMIT " . $limit,
            [$botId]
        );
        return array_reverse($ticks);
    }

    /**
     * Cached list of tradeable pairs (written by the market scanner).
     *
     * @return array<int, string>
     */
    public function availablePairs(): array
    {
        $cacheFile = dirname(__DIR__, 2) . '/database/cache/available_pairs.json';
        if (!file_exists($cacheFile)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($cacheFile), true);
        return is_array($data) ? $data : [];
    }
}
