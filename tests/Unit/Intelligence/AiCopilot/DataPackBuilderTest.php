<?php

namespace Fixzy\Kriptobot\Tests\Unit\Intelligence\AiCopilot;

use Fixzy\Kriptobot\Intelligence\AiCopilot\DataPackBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Data pack feature tests (Phase 2): indicator math on synthetic series and
 * the ~1–2 KB size contract of the assembled pack.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class DataPackBuilderTest extends TestCase
{
    /**
     * Synthetic OHLCV candles walking $start by $step.
     * @return list<array<int, float>>
     */
    private function candles(int $n, float $start = 100.0, float $step = 0.5): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $c = $start + $step * $i;
            $out[] = [1700000000000 + $i * 3600000.0, $c, $c * 1.01, $c * 0.99, $c, 10.0];
        }
        return $out;
    }

    public function testRsiMonotonicUpIs100AndDownIsLow(): void
    {
        $this->assertSame(100.0, DataPackBuilder::rsi14(array_map(fn($c) => (float)$c[4], $this->candles(30))));
        $down = array_map(fn($c) => 200.0 - (float)$c[4], $this->candles(30));
        $rsi = DataPackBuilder::rsi14($down);
        $this->assertLessThan(5.0, (float)$rsi);
        $this->assertNull(DataPackBuilder::rsi14([1, 2, 3]));
    }

    public function testBollingerPositionBounds(): void
    {
        $closes = array_map(fn($c) => (float)$c[4], $this->candles(40));
        $pos = DataPackBuilder::bollPosition($closes);
        $this->assertNotNull($pos);
        $this->assertGreaterThanOrEqual(-0.5, (float)$pos);
        $this->assertLessThanOrEqual(1.5, (float)$pos);
        // flat series => sd 0 => neutral 0.5
        $this->assertSame(0.5, DataPackBuilder::bollPosition(array_fill(0, 25, 100.0)));
    }

    public function testAtrAndVolAndRange(): void
    {
        $candles = $this->candles(60);
        $atr = DataPackBuilder::atr14Pct($candles);
        $this->assertNotNull($atr);
        $this->assertGreaterThan(0.0, (float)$atr);
        $this->assertNotNull(DataPackBuilder::realizedVolPct($candles, '1h'));
        $this->assertNull(DataPackBuilder::realizedVolPct($candles, '1d')); // not enough 1d candles for 30h
        $range = DataPackBuilder::rangePct($candles, '1h');
        $this->assertGreaterThan(0.0, (float)$range);
    }

    public function testTfFeaturesKeysAndPackSize(): void
    {
        $tfCandles = [
            '5m' => $this->candles(400),
            '1h' => $this->candles(100),
            '4h' => $this->candles(60),
            '1d' => $this->candles(60),
        ];
        $feats = DataPackBuilder::tfFeatures($tfCandles['1h'], '1h');
        foreach (['close', 'rsi14', 'boll_pos', 'ema', 'atr14_pct', 'vol30h', 'range30h'] as $key) {
            $this->assertArrayHasKey($key, $feats);
        }

        $pack = DataPackBuilder::build(
            'BTC/USDT',
            $tfCandles,
            ['avg_price' => 60000.0, 'pnl_pct' => -2.5, 'dca_step' => 2, 'dist_tp_pct' => 3.0, 'dist_sl_pct' => 5.0],
            ['free_usdt' => 250.75, 'exposure_pct' => 40.0],
            array_fill(0, 10, ['a' => 'CUT_LOSS', 'pnl' => -1.2]),
            ['regime' => 'sideways', 'bias' => 'neutral', 'confidence' => 0.5],
            44,
            3600
        );
        $json = (string)json_encode($pack);
        $this->assertLessThan(2048, strlen($json), 'data pack must stay ~1-2KB, got ' . strlen($json));
        $this->assertSame('BTC/USDT', $pack['symbol']);
        $this->assertSame(44, $pack['fng']);
        $this->assertCount(10, $pack['trades10']);
        $this->assertSame(3600, $pack['prev']['age_sec']);
    }

    public function testTfMinutes(): void
    {
        $this->assertSame(5.0, DataPackBuilder::tfMinutes('5m'));
        $this->assertSame(60.0, DataPackBuilder::tfMinutes('1h'));
        $this->assertSame(2880.0, DataPackBuilder::tfMinutes('2d'));
        // malformed/zero units fall back to 60 (no division by zero downstream)
        $this->assertSame(60.0, DataPackBuilder::tfMinutes('m'));
        $this->assertSame(60.0, DataPackBuilder::tfMinutes('0m'));
        $this->assertSame(60.0, DataPackBuilder::tfMinutes('1w'));
        $this->assertSame(60.0, DataPackBuilder::tfMinutes(''));
    }

    public function testBuildIgnoresNonArrayTfValues(): void
    {
        $pack = DataPackBuilder::build('X/USDT', ['1h' => 'garbage'], [], [], [], null, null, 0);
        $this->assertArrayNotHasKey('1h', $pack['tf']);
    }
}
