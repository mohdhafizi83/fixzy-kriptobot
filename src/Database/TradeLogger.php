<?php

namespace Fixzy\Kriptobot\Database;

use Doctrine\DBAL\Connection;
use Exception;

class TradeLogger
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Stores decision and execution logs to the database using Doctrine DBAL
     */
    public function logTrade(array $tradeData): void
    {
        try {
            $this->db->insert('trade_logs', [
                'bot_id'     => $tradeData['bot_id'] ?? 0,
                'action'     => $tradeData['action'] ?? 'UNKNOWN',
                'price'      => $tradeData['execute_price'] ?? 0.0,
                'amount'     => $tradeData['coin_amount'] ?? ($tradeData['coin_sold'] ?? 0.0),
                'audit_data' => json_encode($tradeData),
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            echo "💾 [DB AUDIT]: Transaction record " . ($tradeData['action'] ?? '') . " saved to the trade_logs table.\n";
        } catch (Exception $e) {
            echo "⚠️ [LOG ERROR]: Failed to save log to DB: " . $e->getMessage() . "\n";
            file_put_contents(dirname(__DIR__, 2) . '/kriptobot_audit.log', json_encode($tradeData) . "\n", FILE_APPEND);
        }
    }
}