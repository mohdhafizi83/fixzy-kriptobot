<?php

namespace Fixzy\Kriptobot\Trading;

use Fixzy\Kriptobot\Trading\Rules\LogicalAnd;
use Fixzy\Kriptobot\Trading\Rules\TradingViewRule;
use Fixzy\Kriptobot\Trading\Rules\StartAsapRule;
use Fixzy\Kriptobot\Trading\Rules\ExternalSignalRule;
use Fixzy\Kriptobot\Trading\Rules\RsiRule;
use Fixzy\Kriptobot\Trading\Rules\BollingerRule;
use Fixzy\Kriptobot\Trading\Rules\QflRule;
use Fixzy\Kriptobot\Trading\Rules\SentimentRule;
use Fixzy\Kriptobot\Trading\Rules\AiMarketRule;

/**
 * BaseOrderConditionEngine — Condition evaluation engine for trade entry (Base Order).
 *
 * Uses a logical AND: ALL configured conditions must pass before a BUY signal is
 * generated. Supports multiple signal types: RSI, Bollinger, QFL, TradingView
 * Webhook, External Signal, News Sentiment AI, and Start ASAP.
 *
 * @package Fixzy\Kriptobot\Trading
 */
class BaseOrderConditionEngine
{
    private array $baseOrderConfig;
    private array $runtimeState;
    private array $candles;
    private array $lastRuleResults = [];
    private ?LogicalAnd $lastLogicalAnd = null;

    /**
     * @param array $baseOrderConfig base_order configuration (e.g. from settings.php)
     * @param array $runtimeState    Current bot state (for webhook/AI signals)
     * @param array $candles         Multi-timeframe OHLCV data (e.g. ['1h' => [...], '15m' => [...]])
     */
    public function __construct(array $baseOrderConfig, array $runtimeState, array $candles = [])
    {
        $this->baseOrderConfig = $baseOrderConfig;
        $this->runtimeState    = $runtimeState;
        $this->candles         = $candles;
    }

    /**
     * Evaluate ALL entry conditions.
     *
     * @return bool TRUE if all conditions pass, FALSE if any condition fails
     */
    public function evaluate(): bool
    {
        $conditions = $this->baseOrderConfig['conditions'] ?? [];

        if (empty($conditions)) {
            $this->lastRuleResults = [];
            $this->lastLogicalAnd = null;
            return false;
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
            $this->lastLogicalAnd = new LogicalAnd(...$rulesToEvaluate);
            $result = $this->lastLogicalAnd->evaluate();
            $ctx = $this->lastLogicalAnd->getContext();
            $this->lastRuleResults = $ctx['indicators']['rules'] ?? [];
            return $result;
        }

        $this->lastLogicalAnd = null;
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
     * Build a Rule object based on the condition type.
     */
    private function buildRule(array $condition): ?object
    {
        $type = $condition['type'] ?? '';

        return match ($type) {
            'start_asap'     => new StartAsapRule(),
            'tv_webhook'     => new TradingViewRule($condition['value'], $this->runtimeState['latest_tv_signal'] ?? ''),
            'external_signal' => new ExternalSignalRule($condition['value'], $this->runtimeState['latest_external_signal'] ?? ''),
            'news_sentiment' => new SentimentRule($condition['value'], $this->runtimeState['latest_ai_sentiment'] ?? ''),
            'ai_market'      => new AiMarketRule($condition['value'], $this->resolveAiMarketSignal($condition)),
            'rsi', 'rsi_14'  => self::buildRsiRule($condition),
            'bollinger'      => self::buildBollingerRule($condition),
            'qfl'            => self::buildQflRule($condition),
            default          => null,
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
     *
     * The daemon stores signals in runtime state as:
     *   ai_market_signals[<timeframe>] = 'BUY'|'SELL'|'EMPTY'
     * Missing/unknown timeframe or value → '' (treated as EMPTY: not passed, wait).
     */
    private function resolveAiMarketSignal(array $condition): string
    {
        $tf = $condition['timeframe'] ?? '1h';
        $signals = $this->runtimeState['ai_market_signals'] ?? [];
        $signal = strtoupper(trim((string) ($signals[$tf] ?? '')));

        return in_array($signal, ['BUY', 'SELL'], true) ? $signal : '';
    }
}
