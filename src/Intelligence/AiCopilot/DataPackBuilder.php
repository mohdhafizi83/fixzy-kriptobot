<?php

namespace Fixzy\Kriptobot\Intelligence\AiCopilot;

/**
 * Builds the curated L1 data pack (PROJECT_BRIEF section 4): a compact
 * (~1–2 KB) JSON-ready array of per-timeframe features — NOT raw candles —
 * plus position, account, recent trades, previous decision and Fear & Greed.
 *
 * Candles use the ccxt OHLCV shape: [openTimeMs, open, high, low, close, vol].
 * All feature functions are pure and static so they are unit-testable without
 * network or DB.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class DataPackBuilder
{
    /** Timeframes the pack summarizes (brief: 5m/1h/4h/1d). */
    public const TIMEFRAMES = ['5m', '1h', '4h', '1d'];

    /**
     * Wilder RSI(14) over closing prices. Returns null when < 15 closes.
     *
     * @param list<float> $closes
     */
    public static function rsi14(array $closes): ?float
    {
        $n = count($closes);
        if ($n < 15) {
            return null;
        }
        $gain = 0.0;
        $loss = 0.0;
        for ($i = 1; $i <= 14; $i++) {
            $d = $closes[$i] - $closes[$i - 1];
            if ($d >= 0) {
                $gain += $d;
            } else {
                $loss -= $d;
            }
        }
        $avgGain = $gain / 14;
        $avgLoss = $loss / 14;
        for ($i = 15; $i < $n; $i++) {
            $d = $closes[$i] - $closes[$i - 1];
            $avgGain = ($avgGain * 13 + max(0.0, $d)) / 14;
            $avgLoss = ($avgLoss * 13 + max(0.0, -$d)) / 14;
        }
        if ($avgLoss == 0.0) {
            return 100.0;
        }
        return round(100 - 100 / (1 + $avgGain / $avgLoss), 2);
    }

    /**
     * EMA series (seeded with SMA of the first $period values).
     *
     * @param list<float> $values
     * @return list<float>
     */
    public static function emaSeries(array $values, int $period): array
    {
        $n = count($values);
        if ($n < $period) {
            return [];
        }
        $k = 2 / ($period + 1);
        $ema = array_sum(array_slice($values, 0, $period)) / $period;
        $out = [$ema];
        for ($i = $period; $i < $n; $i++) {
            $ema = $values[$i] * $k + $ema * (1 - $k);
            $out[] = $ema;
        }
        return $out;
    }

    /**
     * Bollinger band position of the last close, 0 = lower band, 1 = upper band.
     *
     * @param list<float> $closes
     */
    public static function bollPosition(array $closes, int $period = 20): ?float
    {
        if (count($closes) < $period) {
            return null;
        }
        $window = array_slice($closes, -$period);
        $mean = array_sum($window) / $period;
        $var = 0.0;
        foreach ($window as $c) {
            $var += ($c - $mean) ** 2;
        }
        $sd = sqrt($var / $period);
        if ($sd <= 0) {
            return 0.5;
        }
        $last = $closes[count($closes) - 1];
        return round(max(-0.5, min(1.5, ($last - ($mean - 2 * $sd)) / (4 * $sd))), 3);
    }

    /**
     * ATR(14) as % of the last close.
     *
     * @param list<array{0:float|int,1:float,2:float,3:float,4:float,5?:float}> $candles
     */
    public static function atr14Pct(array $candles): ?float
    {
        if (count($candles) < 15) {
            return null;
        }
        $trs = [];
        for ($i = 1; $i < count($candles); $i++) {
            $hi = (float)$candles[$i][2];
            $lo = (float)$candles[$i][3];
            $pc = (float)$candles[$i - 1][4];
            $trs[] = max($hi - $lo, abs($hi - $pc), abs($lo - $pc));
        }
        $atr = array_sum(array_slice($trs, 0, 14)) / 14;
        for ($i = 14; $i < count($trs); $i++) {
            $atr = ($atr * 13 + $trs[$i]) / 14;
        }
        $lastClose = (float)$candles[count($candles) - 1][4];
        return $lastClose > 0 ? round($atr / $lastClose * 100, 3) : null;
    }

    /**
     * Realized volatility (% stdev of log returns) over the last $hours of
     * candles for the given timeframe. Returns null when not enough candles.
     *
     * @param list<array{0:float|int,1:float,2:float,3:float,4:float,5?:float}> $candles
     */
    public static function realizedVolPct(array $candles, string $tf, float $hours = 30.0): ?float
    {
        $minutesPerCandle = self::tfMinutes($tf);
        $needed = (int)ceil($hours * 60 / $minutesPerCandle);
        if ($needed < 3 || count($candles) < $needed + 1) {
            return null;
        }
        $closes = array_map(static fn($c) => (float)$c[4], array_slice($candles, -($needed + 1)));
        $rets = [];
        for ($i = 1; $i < count($closes); $i++) {
            if ($closes[$i - 1] > 0 && $closes[$i] > 0) {
                $rets[] = log($closes[$i] / $closes[$i - 1]);
            }
        }
        if (count($rets) < 3) {
            return null;
        }
        $mean = array_sum($rets) / count($rets);
        $var = 0.0;
        foreach ($rets as $r) {
            $var += ($r - $mean) ** 2;
        }
        return round(sqrt($var / count($rets)) * 100, 3);
    }

    /** High-low range over the last $hours as % of last close. */
    public static function rangePct(array $candles, string $tf, float $hours = 30.0): ?float
    {
        $needed = max(2, (int)ceil($hours * 60 / self::tfMinutes($tf)));
        if (count($candles) < $needed) {
            return null;
        }
        $window = array_slice($candles, -$needed);
        $hi = -INF;
        $lo = INF;
        foreach ($window as $c) {
            $hi = max($hi, (float)$c[2]);
            $lo = min($lo, (float)$c[3]);
        }
        $last = (float)$candles[count($candles) - 1][4];
        return $last > 0 && is_finite($hi) ? round(($hi - $lo) / $last * 100, 2) : null;
    }

    /**
     * Curated per-timeframe features from one TF's candles.
     *
     * @param list<array<int, float>> $candles
     * @return array<string, mixed>
     */
    public static function tfFeatures(array $candles, string $tf): array
    {
        $closes = array_map(static fn($c) => (float)$c[4], $candles);
        $last = $closes !== [] ? $closes[count($closes) - 1] : null;
        $ema20 = self::emaSeries($closes, 20);
        $ema50 = self::emaSeries($closes, 50);
        $e20 = $ema20 !== [] ? $ema20[count($ema20) - 1] : null;
        $e50 = $ema50 !== [] ? $ema50[count($ema50) - 1] : null;
        $rel = null;
        if ($last !== null && $e20 !== null && $e50 !== null && $e50 > 0) {
            $rel = [
                'above20' => $last > $e20,
                'above50' => $last > $e50,
                'spread_pct' => round(($e20 - $e50) / $e50 * 100, 2),
            ];
        }
        return array_filter([
            'close'    => $last !== null ? round($last, 6) : null,
            'rsi14'    => self::rsi14($closes),
            'boll_pos' => self::bollPosition($closes),
            'ema'      => $rel,
            'atr14_pct' => self::atr14Pct($candles),
            'vol30h'   => self::realizedVolPct($candles, $tf),
            'range30h' => self::rangePct($candles, $tf),
        ], static fn($v) => $v !== null);
    }

    /**
     * Assemble the full L1 data pack.
     *
     * @param array<string, list<array<int, float>>> $tfCandles  candles per timeframe
     * @param array<string, mixed> $position   entry, avg_price, pnl_pct, dca_step, dist_tp_pct, dist_sl_pct
     * @param array<string, mixed> $account    free_usdt, exposure_pct
     * @param list<array<string, mixed>> $recentTrades  up to 10, already trimmed
     * @param array<string, mixed>|null $previousDecision  last cached L1 decision
     * @return array<string, mixed>
     */
    public static function build(
        string $symbol,
        array $tfCandles,
        array $position,
        array $account,
        array $recentTrades,
        ?array $previousDecision,
        ?int $fearGreed,
        int $cacheAgeSec
    ): array {
        $pack = [
            'symbol' => $symbol,
            'ts' => time(),
            'tf' => [],
        ];
        foreach (self::TIMEFRAMES as $tf) {
            if (!empty($tfCandles[$tf]) && is_array($tfCandles[$tf])) {
                $pack['tf'][$tf] = self::tfFeatures($tfCandles[$tf], $tf);
            }
        }
        $pack['position'] = $position === [] ? 'none' : array_filter([
            'avg_price'   => isset($position['avg_price']) ? round((float)$position['avg_price'], 6) : null,
            'pnl_pct'     => isset($position['pnl_pct']) ? round((float)$position['pnl_pct'], 2) : null,
            'dca_step'    => isset($position['dca_step']) ? (int)$position['dca_step'] : null,
            'dist_tp_pct' => isset($position['dist_tp_pct']) ? round((float)$position['dist_tp_pct'], 2) : null,
            'dist_sl_pct' => isset($position['dist_sl_pct']) ? round((float)$position['dist_sl_pct'], 2) : null,
        ], static fn($v) => $v !== null);
        $pack['account'] = array_filter([
            'free_usdt'    => isset($account['free_usdt']) ? round((float)$account['free_usdt'], 2) : null,
            'exposure_pct' => isset($account['exposure_pct']) ? round((float)$account['exposure_pct'], 1) : null,
        ], static fn($v) => $v !== null);
        $pack['trades10'] = array_slice($recentTrades, -10);
        $pack['prev'] = $previousDecision === null ? null : [
            'regime'     => $previousDecision['regime'] ?? null,
            'bias'       => $previousDecision['bias'] ?? null,
            'confidence' => $previousDecision['confidence'] ?? null,
            'age_sec'    => $cacheAgeSec,
        ];
        $pack['fng'] = $fearGreed;
        return $pack;
    }

    public static function tfMinutes(string $tf): float
    {
        $num = 0.0;
        if (preg_match('/^([0-9]+)([mhd])$/', $tf, $m)) {
            $num = match ($m[2]) {
                'm' => (float)$m[1],
                'h' => (float)$m[1] * 60,
                'd' => (float)$m[1] * 1440,
            };
        }
        return $num > 0 ? $num : 60.0;
    }
}
