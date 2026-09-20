<?php

namespace Fixzy\Kriptobot\Agent;

class RiskProfiler
{
    public const PROFILES = [
        'conservative' => [
            'label_en'      => 'Conservative (Low Risk)',
            'label_ms'      => 'Conservative (Low Risk)',
            'target_profit' => 1.5,
            'cut_loss'      => 10,
            'max_dca_steps' => 2,
            'volume_scale'  => 1.5,
            'step_scale'    => 1.0,
            'trailing_tp'   => 0.3,
            'entry_rules'   => [
                ['type' => 'rsi', 'value' => '< 25', 'timeframe' => '1h', 'period' => 14],
                ['type' => 'bollinger', 'value' => 'below_lower', 'timeframe' => '1h', 'period' => 20, 'stddev' => 2.0],
            ],
            'description_en' => 'Minimal risk with strong entry confirmation. Suitable for capital preservation.',
            'description_ms' => 'Minimal risk with strong entry confirmation. Suitable for capital preservation.',
        ],
        'moderate' => [
            'label_en'      => 'Moderate (Balanced)',
            'label_ms'      => 'Moderate (Balanced)',
            'target_profit' => 3.0,
            'cut_loss'      => 15,
            'max_dca_steps' => 4,
            'volume_scale'  => 2.0,
            'step_scale'    => 1.0,
            'trailing_tp'   => 0.5,
            'entry_rules'   => [
                ['type' => 'rsi', 'value' => '< 30', 'timeframe' => '1h', 'period' => 14],
            ],
            'description_en' => 'Balanced risk/reward. Classic DCA strategy with moderate safety orders.',
            'description_ms' => 'Balanced risk/reward. Classic DCA strategy with moderate safety orders.',
        ],
        'aggressive' => [
            'label_en'      => 'Aggressive (High Risk)',
            'label_ms'      => 'Aggressive (High Risk)',
            'target_profit' => 5.0,
            'cut_loss'      => 25,
            'max_dca_steps' => 6,
            'volume_scale'  => 2.5,
            'step_scale'    => 1.5,
            'trailing_tp'   => 0.5,
            'entry_rules'   => [
                ['type' => 'rsi', 'value' => '< 35', 'timeframe' => '1h', 'period' => 14],
            ],
            'description_en' => 'Higher risk for maximum profit potential. Deep DCA with aggressive entries.',
            'description_ms' => 'Higher risk for maximum profit potential. Deep DCA with aggressive entries.',
        ],
        'scalping' => [
            'label_en'      => 'Scalping (Very Aggressive)',
            'label_ms'      => 'Scalping (Very Aggressive)',
            'target_profit' => 0.8,
            'cut_loss'      => 5,
            'max_dca_steps' => 1,
            'volume_scale'  => 1.0,
            'step_scale'    => 1.0,
            'trailing_tp'   => 0.2,
            'entry_rules'   => [
                ['type' => 'rsi', 'value' => '< 20', 'timeframe' => '15m', 'period' => 14],
            ],
            'description_en' => 'Ultra-short term trades. Tight stops, quick profits. High frequency.',
            'description_ms' => 'Ultra-short-term trades. Tight stops, quick profits. High frequency.',
        ],
    ];

    /**
     * Map natural language keywords to risk profiles.
     */
    public function analyzeUserInput(string $text, string $lang = 'en'): array
    {
        $text = strtolower($text);

        $keywords = [
            'conservative' => [
                'en' => ['low risk', 'safe', 'conservative', 'preserve capital', 'minimal risk', 'cautious', 'defensive'],
                'ms' => ['low risk', 'safe', 'conservative', 'preserve capital', 'minimal risk', 'cautious', 'defensive'],
            ],
            'moderate' => [
                'en' => ['balanced', 'moderate', 'medium risk', 'steady', 'reliable', 'consistent'],
                'ms' => ['balanced', 'moderate', 'medium risk', 'steady', 'reliable', 'consistent'],
            ],
            'aggressive' => [
                'en' => ['high risk', 'aggressive', 'maximum profit', 'high return', 'maximize', 'growth'],
                'ms' => ['high risk', 'aggressive', 'maximum profit', 'high return', 'maximize', 'growth'],
            ],
            'scalping' => [
                'en' => ['scalp', 'quick', 'fast', 'short term', 'intraday', 'rapid'],
                'ms' => ['scalp', 'quick', 'fast', 'short term', 'intraday', 'rapid'],
            ],
        ];

        $scores = ['conservative' => 0, 'moderate' => 0, 'aggressive' => 0, 'scalping' => 0];

        foreach ($keywords as $profile => $langKeywords) {
            foreach ($langKeywords[$lang] ?? $langKeywords['en'] as $kw) {
                if (strpos($text, $kw) !== false) {
                    $scores[$profile] += 1;
                }
            }
        }

        $bestProfile = 'moderate';
        $bestScore = 0;
        foreach ($scores as $profile => $score) {
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestProfile = $profile;
            }
        }

        $profile = self::PROFILES[$bestProfile];
        $profile['profile_key'] = $bestProfile;

        return $profile;
    }

    public function getProfile(string $key): ?array
    {
        return self::PROFILES[$key] ?? null;
    }

    public function getAllProfiles(string $lang = 'en'): array
    {
        $result = [];
        foreach (self::PROFILES as $key => $profile) {
            $result[] = [
                'key'         => $key,
                'label'       => $profile['label_' . $lang] ?? $profile['label_en'],
                'description' => $profile['description_' . $lang] ?? $profile['description_en'],
                'params'      => [
                    'target_profit' => $profile['target_profit'],
                    'cut_loss'      => $profile['cut_loss'],
                    'max_dca_steps' => $profile['max_dca_steps'],
                    'volume_scale'  => $profile['volume_scale'],
                ],
            ];
        }
        return $result;
    }
}
