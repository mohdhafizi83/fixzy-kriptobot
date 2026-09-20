<?php

namespace Fixzy\Kriptobot\Database;

use Doctrine\DBAL\Connection;
use Exception;

class AuditLogger
{
    private Connection $db;
    private string $fallbackLogPath;

    public function __construct(Connection $db)
    {
        $this->db = $db;
        $this->fallbackLogPath = dirname(__DIR__, 2) . '/kriptobot_audit.log';
    }

    /**
     * Log any event to the audit_logs table.
     *
     * @param int    $botId
     * @param int    $userId
     * @param string $eventType    e.g. 'BUY', 'SELL', 'RULE_EVAL', 'REJECTION',
     *                              'AI_DECISION', 'SIGNAL', 'ERROR', 'STATE_CHANGE',
     *                              'TRAILING'
     * @param string $summary      Human-readable summary
     * @param array  $contextData  Full context data (JSON)
     */
    public function log(?int $botId, int $userId, string $eventType, string $summary, array $contextData = []): void
    {
        try {
            $this->db->insert('audit_logs', [
                'bot_id'       => $botId,
                'user_id'      => $userId,
                'event_type'   => $eventType,
                'event_summary' => $summary,
                'context_data' => json_encode($contextData),
                'created_at'   => date('Y-m-d H:i:s'),
            ]);

            if (PHP_SAPI === 'cli') {
                echo "[AUDIT] $eventType | Bot #$botId: $summary\n";
            }
        } catch (Exception $e) {
            if (PHP_SAPI === 'cli') {
                echo "[AUDIT FAIL] $eventType: " . $e->getMessage() . "\n";
            }
            error_log("AuditLogger failed: " . $e->getMessage());
            $fallback = [
                'bot_id'      => $botId,
                'user_id'     => $userId,
                'event_type'  => $eventType,
                'summary'     => $summary,
                'context'     => $contextData,
                'timestamp'   => date('Y-m-d H:i:s'),
            ];
            file_put_contents($this->fallbackLogPath, json_encode($fallback) . "\n", FILE_APPEND);
        }
    }

    /**
     * Log a trade execution (BUY/SELL) — writes to trade_logs + audit_logs.
     */
    public function logTrade(int $botId, int $userId, array $tradeData): void
    {
        $action = $tradeData['action'] ?? 'UNKNOWN';

        try {
            $this->db->insert('trade_logs', [
                'bot_id'     => $botId,
                'action'     => $action,
                'price'      => $tradeData['execute_price'] ?? $tradeData['sell_price'] ?? 0.0,
                'amount'     => $tradeData['coin_amount'] ?? $tradeData['coin_sold'] ?? 0.0,
                'audit_data' => json_encode($tradeData),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            echo "[DB AUDIT] $action transaction record saved to trade_logs (Bot #$botId).\n";
        } catch (Exception $e) {
            echo "[LOG ERROR] Failed to save trade_logs: " . $e->getMessage() . "\n";
            file_put_contents($this->fallbackLogPath, json_encode($tradeData) . "\n", FILE_APPEND);
        }
    }

    /**
     * Log rule evaluation — every rule is evaluated and the results are recorded.
     */
    public function logRuleEval(int $botId, int $userId, string $context, array $ruleResults): void
    {
        $totalCount = count($ruleResults);

        if ($totalCount === 0) {
            return;
        }

        $passedCount = count(array_filter($ruleResults, fn($r) => $r['passed']));
        $overallPassed = ($passedCount === $totalCount);

        $summary = $overallPassed
            ? "ALL $totalCount/$totalCount rules PASSED ($context)"
            : "$passedCount/$totalCount rules PASSED, " . ($totalCount - $passedCount) . " FAILED ($context)";

        $this->log($botId, $userId, 'RULE_EVAL', $summary, [
            'context'      => $context,
            'passed_count' => $passedCount,
            'total_count'  => $totalCount,
            'overall'      => $overallPassed ? 'PASS' : 'FAIL',
            'rules'        => $ruleResults,
        ]);
    }

    /**
     * Log SELL actions (Take Profit, Cut Loss, Custom Sell, etc.).
     */
    public function logSell(int $botId, int $userId, string $sellType, array $details): void
    {
        $this->log($botId, $userId, 'SELL', "$sellType for " . ($details['symbol'] ?? '?'), $details);

        $this->logTrade($botId, $userId, [
            'action'      => $sellType,
            'symbol'      => $details['symbol'] ?? '',
            'sell_price'  => $details['sell_price'] ?? 0.0,
            'coin_sold'   => $details['coin_sold'] ?? 0.0,
            'pnl_percent' => $details['pnl_percent'] ?? 0.0,
            'usdt_value'  => $details['usdt_value'] ?? 0.0,
            'reason'      => $details['reason'] ?? '',
            'status'      => $details['status'] ?? 'SUCCESS',
        ]);
    }
}
