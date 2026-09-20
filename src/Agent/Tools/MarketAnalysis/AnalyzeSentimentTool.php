<?php

namespace Fixzy\Kriptobot\Agent\Tools\MarketAnalysis;

use Fixzy\Kriptobot\Agent\Tools\ToolInterface;
use Fixzy\Kriptobot\Agent\Tools\ToolResult;
use Fixzy\Kriptobot\Intelligence\AiAnalyzer;
use Fixzy\Kriptobot\Intelligence\CrossVerifier;
use Fixzy\Kriptobot\Intelligence\Fetchers\CryptoPanicFetcher;
use Fixzy\Kriptobot\Intelligence\Fetchers\BlockonomiFetcher;
use Fixzy\Kriptobot\Config\Config;

class AnalyzeSentimentTool implements ToolInterface
{
    public function getName(): string
    {
        return 'analyze_sentiment';
    }

    public function getDescription(string $lang = 'en'): string
    {
        return $lang === 'ms'
            ? 'Analyze market sentiment for a cryptocurrency pair using verified news from multiple sources and AI analysis. Returns a sentiment signal (BULLISH/BEARISH/HOLD) with a confidence level.'
            : 'Analyze market sentiment for a cryptocurrency pair using verified news from multiple sources and AI analysis. Returns sentiment signal (BULLISH/BEARISH/HOLD) with confidence level.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'pair' => [
                    'type'        => 'string',
                    'description' => 'Trading pair, e.g. BTC/USDT',
                ],
            ],
            'required'   => ['pair'],
        ];
    }

    public function execute(array $params, int $userId): ToolResult
    {
        $pair = strtoupper($params['pair'] ?? 'BTC/USDT');

        try {
            $aiCfg = \Fixzy\Kriptobot\Config\Config::aiConfig();
            $aiKey = $aiCfg['api_key'];
            $cryptoPanicKey = Config::get('CRYPTOPANIC_API_KEY');

            $result = [
                'pair'       => $pair,
                'timestamp'  => date('Y-m-d H:i:s'),
            ];

            // Try cross-verify with available news sources
            $verifier = new CrossVerifier(7200);

            if (!empty($cryptoPanicKey)) {
                $verifier->addFetcher(new CryptoPanicFetcher($cryptoPanicKey));
            }
            $verifier->addFetcher(new BlockonomiFetcher());

            $verification = $verifier->verifySignals($pair);

            if ($verification['status'] === 'VERIFIED' && !empty($aiKey)) {
                $analyzer = new AiAnalyzer($aiKey, $aiCfg['base_url'], $aiCfg['model']);
                $analysis = $analyzer->analyzeSentiment($pair, $verification['data']);

                $result['signal']     = $analysis['signal'] ?? 'HOLD';
                $result['confidence'] = $analysis['confidence'] ?? 0;
                $result['reason']     = $analysis['reason'] ?? '';
                $result['sources']    = $verification['sources_list'];
            } else {
                $result['signal']     = 'NEUTRAL';
                $result['confidence'] = 0;
                $result['reason']     = $verification['message'] ?? 'Insufficient verified news sources';
                $result['sources']    = [];
            }

            return ToolResult::ok($result);

        } catch (\Throwable $e) {
            return ToolResult::fail('Sentiment analysis failed: ' . $e->getMessage());
        }
    }
}
