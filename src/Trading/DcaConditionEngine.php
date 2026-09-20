<?php

namespace Fixzy\Kriptobot\Trading;

use Fixzy\Kriptobot\Trading\Rules\LogicalAnd;
use Fixzy\Kriptobot\Trading\Rules\TradingViewRule;
use Fixzy\Kriptobot\Trading\Rules\SentimentRule;
use Fixzy\Kriptobot\Trading\Rules\StartAsapRule;
use Fixzy\Kriptobot\Trading\Rules\ExternalSignalRule;
use Fixzy\Kriptobot\Trading\Rules\RsiRule;
use Fixzy\Kriptobot\Trading\Rules\BollingerRule;
use Fixzy\Kriptobot\Trading\Rules\QflRule;
use Fixzy\Kriptobot\Trading\Rules\AiMarketRule;

/**
 * DcaConditionEngine — Condition evaluation engine for Safety Orders (DCA).
 *
 * Two-stage evaluation:
 *   1. Cumulative price drop check (Martingale Step Scale) — must be deep enough
 *   2. If Custom Conditions are enabled: ALL additional conditions must pass (logical AND)
 *
 * @package Fixzy\Kriptobot\Trading
 */
class DcaConditionEngine
{
    private array $dcaConfig;
    private float $entryPrice;
    private float $currentPrice;
    private int $currentStep;
    private string $latestWebhookSignal;
    private string $latestAiSentiment;
    private array $candles;
    private array $aiMarketSignals;
    private array $lastRuleResults = [];
    private array $lastEvaluationDetails = [];

    /**
     * @param array  $dcaConfig            Full DCA configuration from settings
     * @param float  $entryPrice           Current average entry price
     * @param float  $currentPrice         Current market price
     * @param int    $currentStep          Current DCA step (0 = no DCA yet)
     * @param string $latestWebhookSignal  Latest TradingView signal
     * @param string $latestAiSentiment    Latest AI sentiment
     * @param array  $candles              OHLCV data (optional)
     */
    public function __construct(
        array $dcaConfig,
        float $entryPrice,
        float $currentPrice,
        int $currentStep,
        string $latestWebhookSignal,
        string $latestAiSentiment,
        array $candles = [],
        array $aiMarketSignals = []
    ) {
        $this->dcaConfig           = $dcaConfig;
        $this->entryPrice          = $entryPrice;
        $this->currentPrice        = $currentPrice;
        $this->currentStep         = $currentStep;
        $this->latestWebhookSignal = $latestWebhookSignal;
        $this->latestAiSentiment   = $latestAiSentiment;
        $this->candles             = $candles;
        $this->aiMarketSignals     = $aiMarketSignals;
    }

    /**
     * Evaluate whether a DCA can be executed.
     *
     * @return bool TRUE if conditions are met, FALSE if not yet
     */
    public function evaluate(): bool
    {
        // 1. Cumulative price drop check (geometric step scale)
        $baseDrop  = $this->dcaConfig['price_drop_trigger'] ?? 2.0;
        $stepScale = $this->dcaConfig['step_scale'] ?? 1.0;

        $requiredDropPercent = 0.0;
        for ($i = 0; $i <= $this->currentStep; $i++) {
            $requiredDropPercent += $baseDrop * pow($stepScale, $i);
        }

        $actualDropPercent = (($this->entryPrice - $this->currentPrice) / $this->entryPrice) * 100;

        $this->lastEvaluationDetails = [
            'entry_price'           => $this->entryPrice,
            'current_price'         => $this->currentPrice,
            'actual_drop_percent'   => round($actualDropPercent, 2),
            'required_drop_percent' => round($requiredDropPercent, 2),
            'current_step'          => $this->currentStep,
            'base_drop'             => $baseDrop,
            'step_scale'            => $stepScale,
        ];

        if ($actualDropPercent < $requiredDropPercent) {
            $this->lastRuleResults = [];
            return false;
        }

        // 2. If Custom Conditions are disabled → pass through immediately
        if (empty($this->dcaConfig['custom_conditions_enabled'])) {
            $this->lastRuleResults = [];
            return true;
        }

        // 3. Smart Averaging — evaluate the additional conditions
        $conditions = $this->dcaConfig['conditions'] ?? [];

        if (empty($conditions)) {
            $this->lastRuleResults = [];
            return true;
        }

        $rulesToEvaluate = [];

        foreach ($conditions as $condition) {
            $rule = $this->buildRule($condition);

            if ($rule === null) {
                continue;
            }

            $rulesToEvaluate[] = $rule;
        }

        if (!empty($rulesToEvaluate)) {
            $logicalAnd = new LogicalAnd(...$rulesToEvaluate);
            $result = $logicalAnd->evaluate();
            $ctx = $logicalAnd->getContext();
            $this->lastRuleResults = $ctx['indicators']['rules'] ?? [];
            return $result;
        }

        $this->lastRuleResults = [];
        return false;
    }

    /**
     * Get the latest rule evaluation results for audit logging.
     */
    public function getLastRuleResults(): array
    {
        return $this->lastRuleResults;
    }

    /**
     * Get the DCA evaluation details (price drop, etc).
     */
    public function getLastEvaluationDetails(): array
    {
        return $this->lastEvaluationDetails;
    }

    /**
     * Build a Rule object based on the DCA condition type.
     */
    private function buildRule(array $condition): ?object
    {
        $type = $condition['type'] ?? '';

        return match ($type) {
            'start_asap'     => new StartAsapRule(),
            'tv_webhook'     => new TradingViewRule($condition['value'], $this->latestWebhookSignal),
            'news_sentiment' => new SentimentRule($condition['value'], $this->latestAiSentiment),
            'ai_market'      => new AiMarketRule($condition['value'], $this->resolveAiMarketSignal($condition)),
            'external_signal' => new ExternalSignalRule($condition['value'], $this->dcaConfig['runtime_external_signal'] ?? ''),
            'rsi', 'rsi_14'   => self::buildRsiRule($condition),
            'bollinger'       => self::buildBollingerRule($condition),
            'qfl'             => self::buildQflRule($condition),
            default           => null,
        };
    }

    private function buildRsiRule(array $condition): RsiRule
    {
        $tf     = $condition['timeframe'] ?? '1h';
        $period = (int) ($condition['period'] ?? 14);
        $c      = $this->resolveCandles($tf);

        return new RsiRule($condition['value'], $c, $period);
    }

    private function buildBollingerRule(array $condition): BollingerRule
    {
        $tf     = $condition['timeframe'] ?? '1h';
        $period = (int) ($condition['period'] ?? 20);
        $stddev = (float) ($condition['stddev'] ?? 2.0);
        $c      = $this->resolveCandles($tf);

        return new BollingerRule($condition['value'], $c, $period, $stddev);
    }

    private function buildQflRule(array $condition): QflRule
    {
        $tf = $condition['timeframe'] ?? '1h';
        $c  = $this->resolveCandles($tf);

        return new QflRule($condition['value'], $c);
    }

    private function resolveCandles(string $timeframe): array
    {
        return $this->candles[$timeframe] ?? (isset($this->candles[0]) ? $this->candles : []);
    }

    /**
     * Resolve the cached AI market signal for the condition's timeframe.
     * Missing/unknown value → '' (treated as EMPTY: not passed, bot waits).
     */
    private function resolveAiMarketSignal(array $condition): string
    {
        $tf = $condition['timeframe'] ?? '1h';
        $signal = strtoupper(trim((string) ($this->aiMarketSignals[$tf] ?? '')));

        return in_array($signal, ['BUY', 'SELL'], true) ? $signal : '';
    }
}
