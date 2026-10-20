<?php

namespace Fixzy\Kriptobot\Tests\Unit\Config;

use Fixzy\Kriptobot\Config\AiCopilotConfig;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the ai_copilot config validator/normalizer (Phase 0).
 *
 * Core guarantee: a bot config WITHOUT the section (every bot created before
 * this feature) always resolves to trust level "off" — zero behaviour change.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class AiCopilotConfigTest extends TestCase
{
    public function testMissingSectionResolvesToSafeDefaults(): void
    {
        $resolved = AiCopilotConfig::resolve([]);
        $this->assertFalse($resolved['enabled']);
        $this->assertSame('off', $resolved['trust_level']);
        $this->assertSame('1h', $resolved['decision_timeframe']);
        $this->assertSame(0.6, $resolved['min_confidence']);
        $this->assertSame(3600, $resolved['cache_ttl_seconds']);
        $this->assertSame(3.0, $resolved['escalation']['flash_move_pct']);
        $this->assertSame(10, $resolved['escalation']['flash_move_window_min']);
        $this->assertSame(5.0, $resolved['escalation']['max_drawdown_pct']);
    }

    public function testResolveIgnoresGarbageSection(): void
    {
        foreach ([['ai_copilot' => 'nonsense'], ['ai_copilot' => 42], ['ai_copilot' => null]] as $bad) {
            $resolved = AiCopilotConfig::resolve($bad);
            $this->assertSame('off', $resolved['trust_level']);
            $this->assertFalse($resolved['enabled']);
        }
    }

    public function testResolveToleratesNonArrayBotConfig(): void
    {
        // Corrupt bots.configuration rows decode to null/scalars; resolve()
        // must never raise a TypeError that could kill the daemon loop.
        foreach ([null, 'garbage', 42, true, [1, 2, 3]] as $bad) {
            $resolved = AiCopilotConfig::resolve($bad);
            $this->assertSame('off', $resolved['trust_level']);
        }
    }

    public function testNormalizeNeverReturnsEmptyTrustLevel(): void
    {
        // Explicit null trust_level used to cast to '' (undefined mode).
        $normalized = AiCopilotConfig::normalize(['enabled' => true, 'trust_level' => null]);
        $this->assertSame('off', $normalized['trust_level']);
        $normalized = AiCopilotConfig::normalize(['enabled' => true, 'trust_level' => ['x']]);
        $this->assertSame('off', $normalized['trust_level']);
    }

    public function testValidateRejectsNonBooleanEnabled(): void
    {
        foreach ([INF, -1, 2, 'yes', [1]] as $bad) {
            $this->assertNotSame(
                [],
                AiCopilotConfig::validate(['enabled' => $bad, 'trust_level' => 'shadow']),
                'enabled=' . var_export($bad, true) . ' must be rejected'
            );
        }
        $this->assertSame([], AiCopilotConfig::validate(['enabled' => true, 'trust_level' => 'shadow']));
        $this->assertSame([], AiCopilotConfig::validate(['enabled' => '1', 'trust_level' => 'shadow']));
    }

    public function testDisabledSwitchForcesTrustOff(): void
    {
        $normalized = AiCopilotConfig::normalize([
            'enabled' => false,
            'trust_level' => 'active',
        ]);
        $this->assertFalse($normalized['enabled']);
        $this->assertSame('off', $normalized['trust_level']);
    }

    public function testUnknownTrustLevelFailsSafeToOff(): void
    {
        $normalized = AiCopilotConfig::normalize([
            'enabled' => true,
            'trust_level' => 'yolo',
        ]);
        $this->assertSame('off', $normalized['trust_level']);
    }

    public function testValidTrustLevelsRoundTrip(): void
    {
        foreach (AiCopilotConfig::TRUST_LEVELS as $level) {
            $normalized = AiCopilotConfig::normalize([
                'enabled' => $level !== 'off',
                'trust_level' => $level,
            ]);
            $this->assertSame($level, $normalized['trust_level']);
        }
    }

    public function testNumericValuesAreClampedIntoRange(): void
    {
        $normalized = AiCopilotConfig::normalize([
            'enabled' => true,
            'trust_level' => 'advisor',
            'min_confidence' => 99,
            'cache_ttl_seconds' => 1,
            'escalation' => [
                'flash_move_pct' => -5,
                'flash_move_window_min' => 9999,
                'max_drawdown_pct' => 'abc',
            ],
        ]);
        $this->assertSame(1.0, $normalized['min_confidence']);
        $this->assertSame(300, $normalized['cache_ttl_seconds']);
        $this->assertSame(0.5, $normalized['escalation']['flash_move_pct']);
        $this->assertSame(120, $normalized['escalation']['flash_move_window_min']);
        // non-numeric falls back to the default
        $this->assertSame(5.0, $normalized['escalation']['max_drawdown_pct']);
    }

    public function testValidateAcceptsDefaultsAndRejectsGarbage(): void
    {
        $this->assertSame([], AiCopilotConfig::validate(null));
        $this->assertSame([], AiCopilotConfig::validate(AiCopilotConfig::defaults()));
        $this->assertNotSame([], AiCopilotConfig::validate('not-an-object'));
    }

    public function testValidateRejectsBadEnumsAndRanges(): void
    {
        $errors = AiCopilotConfig::validate([
            'enabled' => true,
            'trust_level' => 'nope',
            'decision_timeframe' => '7m',
            'min_confidence' => 2.5,
            'cache_ttl_seconds' => 10,
            'escalation' => ['flash_move_pct' => 'x'],
        ]);
        $this->assertCount(5, $errors);
    }

    public function testValidateRejectsEnabledWithOffTrust(): void
    {
        $errors = AiCopilotConfig::validate(['enabled' => true, 'trust_level' => 'off']);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('trust_level', $errors[0]);
    }

    public function testEffectiveTrustLevelGating(): void
    {
        $advisor = ['enabled' => true, 'trust_level' => 'advisor'];

        // All prerequisites met -> honoured.
        $this->assertSame('advisor', AiCopilotConfig::effectiveTrustLevel($advisor, true, true));

        // Agent switch off -> off (fail-safe).
        $this->assertSame('off', AiCopilotConfig::effectiveTrustLevel($advisor, false, true));

        // AI feature not verified -> off.
        $this->assertSame('off', AiCopilotConfig::effectiveTrustLevel($advisor, true, false));

        // Config itself off -> off regardless of switches.
        $this->assertSame('off', AiCopilotConfig::effectiveTrustLevel(AiCopilotConfig::defaults(), true, true));
    }
}
