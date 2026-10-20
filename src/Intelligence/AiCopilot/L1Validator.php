<?php

namespace Fixzy\Kriptobot\Intelligence\AiCopilot;

/**
 * Validates and normalizes the L1 (slow AI loop) JSON output from the LLM.
 *
 * Schema (PROJECT_BRIEF section 4): regime, bias, params{dca_drop_pct,
 * max_dca_steps, tp_pct, trail_pct, entry_aggressiveness}, confidence, reason.
 * Anything outside the enum/range contract is REJECTED — the caller must then
 * fail safe (keep the previous decision / HOLD). This class never throws on
 * bad LLM output; it returns a structured error instead.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class L1Validator
{
    public const REGIMES = ['trending_up', 'trending_down', 'sideways', 'choppy'];
    public const BIASES = ['bullish', 'neutral', 'bearish'];
    public const AGGRESSION = ['low', 'normal', 'high'];

    /** param => [min, max] — brief: dca_drop_pct 0.5–10, max_dca_steps 0–8. */
    public const PARAM_RANGES = [
        'dca_drop_pct' => [0.5, 10.0],
        'max_dca_steps' => [0, 8],      // integer; hard cap matches RiskGuard
        'tp_pct' => [0.5, 20.0],
        'trail_pct' => [0.1, 10.0],
    ];

    /**
     * @param mixed $decoded parsed LLM JSON (any shape)
     * @return array{ok: bool, errors: list<string>, decision: array<string, mixed>}
     */
    public static function validate(mixed $decoded): array
    {
        $errors = [];
        if (!is_array($decoded)) {
            return ['ok' => false, 'errors' => ['output is not a JSON object'], 'decision' => []];
        }

        $regime = is_string($decoded['regime'] ?? null) ? strtolower(trim($decoded['regime'])) : '';
        if (!in_array($regime, self::REGIMES, true)) {
            $errors[] = 'regime must be one of: ' . implode(', ', self::REGIMES);
        }
        $bias = is_string($decoded['bias'] ?? null) ? strtolower(trim($decoded['bias'])) : '';
        if (!in_array($bias, self::BIASES, true)) {
            $errors[] = 'bias must be one of: ' . implode(', ', self::BIASES);
        }

        $confidence = $decoded['confidence'] ?? null;
        if (!is_numeric($confidence) || !is_finite((float)$confidence) || (float)$confidence < 0 || (float)$confidence > 1) {
            $errors[] = 'confidence must be a number between 0 and 1';
            $confidence = 0.0;
        } else {
            $confidence = (float)$confidence;
        }

        $rawParams = $decoded['params'] ?? null;
        if (!is_array($rawParams)) {
            $errors[] = 'params must be an object';
            $rawParams = [];
        }
        $params = [];
        foreach (self::PARAM_RANGES as $name => [$lo, $hi]) {
            $v = $rawParams[$name] ?? null;
            if (!is_numeric($v) || !is_finite((float)$v) || (float)$v < $lo || (float)$v > $hi) {
                $errors[] = sprintf('params.%s must be within %s–%s', $name, rtrim(rtrim(number_format($lo, 1, '.', ''), '0'), '.'), rtrim(rtrim(number_format($hi, 1, '.', ''), '0'), '.'));
                continue;
            }
            if ($name === 'max_dca_steps' && (float)$v !== floor((float)$v)) {
                $errors[] = 'params.max_dca_steps must be an integer';
                continue;
            }
            $params[$name] = $name === 'max_dca_steps' ? (int)$v : (float)$v;
        }
        $aggr = is_string($rawParams['entry_aggressiveness'] ?? null) ? strtolower(trim($rawParams['entry_aggressiveness'])) : '';
        if (!in_array($aggr, self::AGGRESSION, true)) {
            $errors[] = 'params.entry_aggressiveness must be one of: ' . implode(', ', self::AGGRESSION);
        }

        $reason = is_string($decoded['reason'] ?? null) ? trim($decoded['reason']) : '';
        // Always truncate (mb_substr also drops broken trailing sequences) and
        // force valid UTF-8: an invalid string here breaks json_encode of the
        // runtime_state cache and would fail this bot's L1 pass forever.
        $reason = mb_substr($reason, 0, 500);
        if (!mb_check_encoding($reason, 'UTF-8')) {
            $reason = mb_convert_encoding($reason, 'UTF-8', 'UTF-8');
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'decision' => []];
        }

        return [
            'ok' => true,
            'errors' => [],
            'decision' => [
                'regime'   => $regime,
                'bias'     => $bias,
                'params'   => [
                    'dca_drop_pct'          => $params['dca_drop_pct'],
                    'max_dca_steps'         => $params['max_dca_steps'],
                    'tp_pct'                => $params['tp_pct'],
                    'trail_pct'             => $params['trail_pct'],
                    'entry_aggressiveness'  => $aggr,
                ],
                'confidence' => $confidence,
                'reason'     => $reason,
            ],
        ];
    }
}
