<?php

namespace Fixzy\Kriptobot\Trading;

/**
 * The "backtest ringkas 30h" gate for advisor mode (Phase 3).
 *
 * A deliberately simple, deterministic DCA-grid replay over the last 30 hours
 * of decision-timeframe candles: the SAME candle sequence is replayed once
 * with the L1 params and once with the bot's static params; the new params
 * are approved only when they are not worse (realized PnL within epsilon and
 * drawdown not materially deeper). Runs in bin/ai_copilot_loop.php — never in
 * the daemon hot path.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class AdvisorBacktestGate
{
    public const EPSILON_PCT = 0.1;   // tolerate tiny noise between param sets
    public const DD_FACTOR   = 1.5;   // new max drawdown may not exceed static x1.5

    /**
     * @param list<array<int, float>> $candles decision-TF OHLCV, oldest first
     * @param array<string, float>    $new     L1 param set (AdvisorParams::staticParams shape)
     * @param array<string, float>    $static  current bot param set
     * @return array{approved: bool, new_pnl_pct: float, static_pnl_pct: float, new_max_dd_pct: float, static_max_dd_pct: float, reason: string}
     */
    public static function evaluate(array $candles, array $new, array $static): array
    {
        $newRes = self::replay($candles, $new);
        $staticRes = self::replay($candles, $static);

        $pnlOk = $newRes['pnl_pct'] >= $staticRes['pnl_pct'] - self::EPSILON_PCT;
        $ddOk = $newRes['max_dd_pct'] <= max($staticRes['max_dd_pct'] * self::DD_FACTOR, 0.5);
        $reason = $pnlOk && $ddOk
            ? 'not worse than static'
            : ($pnlOk ? 'drawdown deeper than static limit' : 'realized pnl worse than static');

        return [
            'approved' => $pnlOk && $ddOk,
            'new_pnl_pct' => $newRes['pnl_pct'],
            'static_pnl_pct' => $staticRes['pnl_pct'],
            'new_max_dd_pct' => $newRes['max_dd_pct'],
            'static_max_dd_pct' => $staticRes['max_dd_pct'],
            'reason' => $reason,
        ];
    }

    /**
     * Deterministic grid replay: enter at first close, DCA on drop triggers up
     * to max steps, take profit at tp_pct over average, then re-enter.
     * Tracks summed realized PnL% and worst peak-to-trough drawdown% of the
     * mark-to-market equity (flat periods count as the running peak).
     *
     * @param list<array<int, float>> $candles
     * @param array<string, float>    $params
     * @return array{pnl_pct: float, max_dd_pct: float}
     */
    public static function replay(array $candles, array $params): array
    {
        $drop = (float)($params['dca_drop_pct'] ?? 2.0);
        $maxSteps = (int)($params['max_dca_steps'] ?? 4);
        $tp = (float)($params['tp_pct'] ?? 2.0);
        if ($drop <= 0 || $tp <= 0 || count($candles) < 3) {
            return ['pnl_pct' => 0.0, 'max_dd_pct' => 0.0];
        }

        $pnl = 0.0;
        $peakEquity = 0.0;
        $maxDd = 0.0;

        $inPos = false;
        $avg = 0.0;
        $lastEntry = 0.0;
        $steps = 0;

        foreach ($candles as $c) {
            $close = (float)$c[4];
            if ($close <= 0) {
                continue;
            }
            if (!$inPos) {
                $inPos = true;
                $avg = $lastEntry = $close;
                $steps = 0;
            } else {
                // take profit first (conservative ordering)
                if ($close >= $avg * (1 + $tp / 100)) {
                    $pnl += ($close - $avg) / $avg * 100;
                    $inPos = false;
                    $avg = $lastEntry = $close; // re-enter at this candle
                    $steps = 0;
                } elseif ($close <= $lastEntry * (1 - $drop / 100) && $steps < $maxSteps) {
                    // equal-weight DCA step
                    $avg = ($avg + $close) / 2;
                    $lastEntry = $close;
                    $steps++;
                }
            }
            // mark-to-market equity in % relative to avg cost (0 when flat)
            $equity = $inPos ? ($close - $avg) / $avg * 100 : 0.0;
            $peakEquity = max($peakEquity, $equity);
            $maxDd = max($maxDd, $peakEquity - $equity);
        }

        return ['pnl_pct' => round($pnl, 4), 'max_dd_pct' => round($maxDd, 4)];
    }
}
