<?php

namespace Tests\Unit\Intelligence\AiCopilot;

use Doctrine\DBAL\DriverManager;
use Fixzy\Kriptobot\Intelligence\AiCopilot\Escalation;
use Fixzy\Kriptobot\Intelligence\AiCopilot\EscalationExecutor;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4 L3 escalation: pure trigger detection + verdict parsing/execution.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
final class EscalationTest extends TestCase
{
    private const ESC = ['flash_move_pct' => 3.0, 'flash_move_window_min' => 10, 'max_drawdown_pct' => 5.0];

    /** @param array<string,mixed> $overrides */
    private function ctx(array $overrides = []): array
    {
        return array_merge([
            'mode' => 'active',
            'state' => ['current_holdings' => 1.0, 'average_entry_price' => 100.0, 'dca_current_step' => 0],
            'config' => ['dca' => ['max_steps' => 4]],
            'esc' => self::ESC,
            'now' => 1000000,
            'price' => 100.0,
            'pnl' => 0.0,
            'price_window' => 100.0,
            'cache_age' => 60,
            'trade_age' => 999999,
            'ttl' => 3600,
        ], $overrides);
    }

    public function testNoTriggerInQuietMarket(): void
    {
        $this->assertNull(Escalation::detect($this->ctx()));
    }

    public function testShadowAdvisorAndOffNeverTrigger(): void
    {
        // L3 escalation is ACTIVE-only (audit-full #7): advisor tunes params
        // but never pauses the bot.
        $this->assertNull(Escalation::detect($this->ctx(['mode' => 'shadow', 'pnl' => -9.0])));
        $this->assertNull(Escalation::detect($this->ctx(['mode' => 'advisor', 'pnl' => -9.0])));
        $this->assertNull(Escalation::detect($this->ctx(['mode' => 'off', 'pnl' => -9.0])));
    }

    public function testFlashMoveCrashAndPump(): void
    {
        $down = Escalation::detect($this->ctx(['price' => 96.0, 'price_window' => 100.0, 'pnl' => -4.0]));
        $this->assertSame('flash_move', $down['trigger']);
        $this->assertSame(-4.0, $down['detail']['window_pct']);
        $up = Escalation::detect($this->ctx(['price' => 104.0, 'price_window' => 100.0, 'pnl' => 4.0]));
        $this->assertSame('flash_move', $up['trigger']);
        // exactly at threshold triggers (>=); just below does not
        $this->assertNotNull(Escalation::detect($this->ctx(['price' => 97.0, 'price_window' => 100.0, 'pnl' => -3.0])));
        $this->assertNull(Escalation::detect($this->ctx(['price' => 97.01, 'price_window' => 100.0, 'pnl' => -2.99])));
    }

    public function testDrawdownBreach(): void
    {
        $t = Escalation::detect($this->ctx(['pnl' => -5.0, 'price_window' => null]));
        $this->assertSame('drawdown', $t['trigger']);
        $this->assertNull(Escalation::detect($this->ctx(['pnl' => -4.9, 'price_window' => null])));
    }

    public function testDcaExhausted(): void
    {
        $t = Escalation::detect($this->ctx([
            'state' => ['current_holdings' => 1.0, 'average_entry_price' => 100.0, 'dca_current_step' => 4],
            'pnl' => -2.0,
            'price' => 98.0,
            'price_window' => null,
        ]));
        $this->assertSame('dca_exhausted', $t['trigger']);
        // steps left => no trigger
        $this->assertNull(Escalation::detect($this->ctx([
            'state' => ['current_holdings' => 1.0, 'average_entry_price' => 100.0, 'dca_current_step' => 3],
            'pnl' => -2.0, 'price' => 98.0, 'price_window' => null,
        ])));
    }

    public function testL1ExpiredGoesStraightToHuman(): void
    {
        foreach ([['cache_age' => null], ['cache_age' => 3601]] as $case) {
            $t = Escalation::detect($this->ctx($case + ['price_window' => null]));
            $this->assertSame('l1_expired', $t['trigger']);
            $this->assertTrue($t['human_only']);
        }
    }

    public function testNoTriggerWithoutHoldings(): void
    {
        $flat = ['current_holdings' => 0.0, 'average_entry_price' => 0.0, 'dca_current_step' => 0];
        $this->assertNull(Escalation::detect($this->ctx(['state' => $flat, 'pnl' => -9.0, 'cache_age' => null])));
    }

    public function testDedupWhileOpenAndDuringCooldown(): void
    {
        $open = ['current_holdings' => 1.0, 'average_entry_price' => 100.0, 'ai_l3' => ['paused' => 1, 'trigger' => 'drawdown']];
        $this->assertNull(Escalation::detect($this->ctx(['state' => $open + [], 'pnl' => -9.0, 'price_window' => null])));

        $cooldown = ['current_holdings' => 1.0, 'average_entry_price' => 100.0, 'ai_l3' => ['paused' => 0, 'last_resolved_at' => 999000]];
        $this->assertNull(Escalation::detect($this->ctx(['state' => $cooldown, 'pnl' => -9.0, 'price_window' => null, 'now' => 999000 + 1799])));
        // after cooldown the same bad condition may re-trigger
        $this->assertNotNull(Escalation::detect($this->ctx(['state' => $cooldown, 'pnl' => -9.0, 'price_window' => null, 'now' => 999000 + 1801])));
    }

    public function testL1ExpiredGraceForFreshPosition(): void
    {
        // Missing cache on a position younger than one TTL window is NOT
        // "LLM down" — the loop simply has not produced a decision yet.
        $t = Escalation::detect($this->ctx(['cache_age' => null, 'trade_age' => 600, 'price_window' => null]));
        $this->assertNull($t);
        // Older than the window with no cache => genuine expiry.
        $t2 = Escalation::detect($this->ctx(['cache_age' => null, 'trade_age' => 7200, 'price_window' => null]));
        $this->assertSame('l1_expired', $t2['trigger']);
    }

    public function testCacheAge(): void
    {
        $this->assertNull(Escalation::cacheAge(null, 1000));
        $this->assertNull(Escalation::cacheAge(['created_at' => 0], 1000));
        $this->assertSame(400, Escalation::cacheAge(['created_at' => 600], 1000));
    }

    // ---------------------------------------------------------------- verdicts

    public function testExecutorTransitions(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE bots (id INTEGER PRIMARY KEY, runtime_state TEXT)');
        $db->executeStatement('CREATE TABLE agent_decisions (id INTEGER PRIMARY KEY, status TEXT, proposed_config TEXT, market_snapshot TEXT, justification TEXT, applied_at TEXT)');
        $db->executeStatement('INSERT INTO bots VALUES (1, ?)', ['{}']);

        $mk = function (string $status, ?array $verdict) use ($db): array {
            $db->executeStatement('DELETE FROM agent_decisions');
            $db->executeStatement(
                'INSERT INTO agent_decisions (id, status, proposed_config, market_snapshot) VALUES (9, ?, ?, ?)',
                [$status, $verdict === null ? null : (string)json_encode($verdict), (string)json_encode(['trigger' => 'drawdown'])]
            );
            return ['id' => 9, 'status' => $status, 'proposed_config' => $verdict, 'market_snapshot' => ['trigger' => 'drawdown']];
        };
        $escOf = static fn(): array => json_decode((string)$db->fetchOne('SELECT runtime_state FROM bots WHERE id = 1'), true)['ai_l3'];
        $sold = [];
        $sellOk = static function (string $a) use (&$sold): array { $sold[] = $a; return ['ok' => true, 'message' => 'sold']; };

        // hold: resumes, decision applied, no sell
        $r = EscalationExecutor::execute($db, $mk('auto_approved', ['action' => 'hold', 'confidence' => 0.8, 'reason' => 'x']), 1, $sellOk, 500);
        $this->assertTrue($r['resolved']);
        $this->assertSame([], $sold);
        $this->assertSame('applied', $db->fetchOne('SELECT status FROM agent_decisions WHERE id = 9'));
        $this->assertSame(0, $escOf()['paused']);

        // cut FAILS: pause must stay (never silently resume into a position to exit)
        $r = EscalationExecutor::execute($db, $mk('auto_approved', ['action' => 'cut', 'confidence' => 0.9, 'reason' => 'x']), 1, static fn(): array => ['ok' => false, 'message' => 'exchange down'], 600);
        $this->assertFalse($r['executed']);
        $this->assertSame([], $sold);

        // cut succeeds: sell called, applied
        $r = EscalationExecutor::execute($db, $mk('auto_approved', ['action' => 'cut', 'confidence' => 0.9, 'reason' => 'x']), 1, $sellOk, 700);
        $this->assertTrue($r['executed']);
        $this->assertCount(1, $sold);

        // wait: stays paused with a re-ask deadline, decision NOT applied yet
        $r = EscalationExecutor::execute($db, $mk('auto_approved', ['action' => 'wait', 'confidence' => 0.5, 'reason' => 'x']), 1, $sellOk, 800);
        $this->assertTrue($r['executed']);
        $this->assertFalse($r['resolved']);
        $esc = $escOf();
        $this->assertSame(1, $esc['paused']);
        $this->assertSame(800 + 1800, $esc['reask_at']);

        // wait window expired: becomes hold (fresh escalation may re-fire later)
        $r = EscalationExecutor::execute($db, $mk('auto_approved', ['action' => 'wait', 'confidence' => 0.5, 'reason' => 'x']), 1, $sellOk, 3000, ['action' => 'wait', 'reask_at' => 2600]);
        $this->assertSame('hold', $r['action']);
        $this->assertTrue($r['resolved']);

        // human reject: resume without selling
        $r = EscalationExecutor::execute($db, $mk('rejected', null), 1, $sellOk, 4000);
        $this->assertSame('hold', $r['action']);

        // human approve without AI verdict (l1_expired): acknowledgement resumes
        $r = EscalationExecutor::execute($db, $mk('approved', null), 1, $sellOk, 5000);
        $this->assertSame('hold', $r['action']);

        // auto_approved with a corrupt verdict: routed to a human, not retried forever
        $r = EscalationExecutor::execute($db, $mk('auto_approved', null), 1, $sellOk, 4500);
        $this->assertFalse($r['executed']);
        $this->assertStringContainsString('routed to human', $r['message']);
        $this->assertSame('pending_approval', $db->fetchOne('SELECT status FROM agent_decisions WHERE id = 9'));

        // unresolved statuses are no-ops
        $this->assertFalse(EscalationExecutor::execute($db, $mk('pending_ai', null), 1, $sellOk, 5000)['executed']);
        $this->assertFalse(EscalationExecutor::execute($db, $mk('pending_approval', null), 1, $sellOk, 5000)['executed']);
    }

    public function testParseVerdictStrict(): void
    {
        $ok = EscalationExecutor::parseVerdict(['action' => 'cut', 'confidence' => 0.9, 'reason' => 'x']);
        $this->assertSame('cut', $ok['action']);
        foreach ([
            null, 'str', [],
            ['action' => 'sell', 'confidence' => 0.5],      // not an allowed action
            ['action' => 'hold'],                           // missing confidence
            ['action' => 'hold', 'confidence' => 'x'],      // non-numeric
            ['action' => 'hold', 'confidence' => 1.5],      // out of range
            ['action' => 'hold', 'confidence' => -0.1],
            ['action' => 'hold', 'confidence' => 1e400],    // INF
        ] as $bad) {
            $this->assertNull(EscalationExecutor::parseVerdict($bad));
        }
    }
}
