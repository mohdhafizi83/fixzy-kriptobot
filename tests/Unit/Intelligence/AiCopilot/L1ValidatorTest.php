<?php

namespace Fixzy\Kriptobot\Tests\Unit\Intelligence\AiCopilot;

use Fixzy\Kriptobot\Intelligence\AiCopilot\L1Analyzer;
use Fixzy\Kriptobot\Intelligence\AiCopilot\L1Validator;
use PHPUnit\Framework\TestCase;

/**
 * L1 output contract tests (Phase 2): enum checks, numeric ranges, the
 * confidence gate input, and fence/JSON tolerance of the analyzer parser.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class L1ValidatorTest extends TestCase
{
    /**
     * A fully valid L1 payload.
     * @return array<string, mixed>
     */
    private function valid(): array
    {
        return [
            'regime' => 'trending_up',
            'bias' => 'bullish',
            'params' => [
                'dca_drop_pct' => 2.0,
                'max_dca_steps' => 5,
                'tp_pct' => 3.0,
                'trail_pct' => 1.5,
                'entry_aggressiveness' => 'normal',
            ],
            'confidence' => 0.72,
            'reason' => 'Higher highs on 1h with expanding EMA spread.',
        ];
    }

    public function testValidPayloadPassesAndNormalizes(): void
    {
        $r = L1Validator::validate($this->valid());
        $this->assertTrue($r['ok'], implode('; ', $r['errors']));
        $this->assertSame(0.72, $r['decision']['confidence']);
        $this->assertSame(5, $r['decision']['params']['max_dca_steps']);
        $this->assertSame(2.0, $r['decision']['params']['dca_drop_pct']);
    }

    public function testEnumRejection(): void
    {
        $bad = $this->valid();
        $bad['regime'] = 'mooning';
        $bad['bias'] = 'HOPEFUL';
        $bad['params']['entry_aggressiveness'] = 'yolo';
        $r = L1Validator::validate($bad);
        $this->assertFalse($r['ok']);
        $this->assertCount(3, $r['errors']);
    }

    public function testRangeRejection(): void
    {
        foreach ([
            ['dca_drop_pct', 0.4], ['dca_drop_pct', 10.1],
            ['max_dca_steps', -1], ['max_dca_steps', 9],
            ['tp_pct', 0.1], ['trail_pct', 11],
        ] as [$key, $val]) {
            $bad = $this->valid();
            $bad['params'][$key] = $val;
            $this->assertFalse(L1Validator::validate($bad)['ok'], "$key=$val must be rejected");
        }
    }

    public function testConfidenceBounds(): void
    {
        foreach ([-0.01, 1.01, 'high', null, NAN] as $bad) {
            $payload = $this->valid();
            $payload['confidence'] = $bad;
            $this->assertFalse(L1Validator::validate($payload)['ok'], 'confidence ' . var_export($bad, true) . ' must be rejected');
        }
    }

    public function testNonArrayAndMissingParams(): void
    {
        $this->assertFalse(L1Validator::validate('string')['ok']);
        $this->assertFalse(L1Validator::validate(null)['ok']);
        $missing = $this->valid();
        unset($missing['params']);
        $this->assertFalse(L1Validator::validate($missing)['ok']);
    }

    public function testParseAndValidateToleratesCodeFences(): void
    {
        $raw = "```json\n" . json_encode($this->valid()) . "\n```";
        $r = L1Analyzer::parseAndValidate($raw);
        $this->assertTrue($r['ok']);
        $this->assertSame('trending_up', $r['decision']['regime']);
    }

    public function testParseAndValidateRejectsGarbageAndSchemaViolations(): void
    {
        $garbage = L1Analyzer::parseAndValidate('the market looks bullish to me');
        $this->assertFalse($garbage['ok']);
        $this->assertSame('parse', $garbage['stage']);

        $schemaFail = L1Analyzer::parseAndValidate(json_encode(['regime' => 'x']));
        $this->assertFalse($schemaFail['ok']);
        $this->assertSame('schema', $schemaFail['stage']);
    }

    public function testStrictIntegerAndArrayGuards(): void
    {
        $frac = $this->valid();
        $frac['params']['max_dca_steps'] = 5.9;
        $this->assertFalse(L1Validator::validate($frac)['ok'], 'fractional steps must be rejected');

        $arrEnum = $this->valid();
        $arrEnum['regime'] = ['trending_up'];
        $arrEnum['bias'] = ['bullish'];
        $r = L1Validator::validate($arrEnum);
        $this->assertFalse($r['ok']); // arrays rejected without warnings
    }

    public function testReasonIsTruncated(): void
    {
        $payload = $this->valid();
        $payload['reason'] = str_repeat('a', 900);
        $r = L1Validator::validate($payload);
        $this->assertTrue($r['ok']);
        $this->assertSame(500, mb_strlen($r['decision']['reason']));
    }
}
