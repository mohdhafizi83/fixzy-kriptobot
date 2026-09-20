<?php

namespace Fixzy\Kriptobot\Intelligence;

use GuzzleHttp\Client;
use Exception;

class AiAnalyzer
{
    private string $apiKey;
    private string $model;
    private Client $httpClient;

    /**
     * Works with any OpenAI-compatible provider (DeepSeek, OpenRouter, OpenAI,
     * Groq, Ollama, vLLM, LM Studio, ...) via the chat/completions endpoint.
     */
    public function __construct(string $apiKey, string $baseUrl, string $model)
    {
        $this->apiKey = $apiKey;
        $this->model  = $model;
        if ($baseUrl !== '' && !str_ends_with($baseUrl, '/')) {
            $baseUrl .= '/';
        }
        $this->httpClient = new Client([
            'base_uri' => $baseUrl,
            'timeout'  => 30.0,
            'headers'  => [
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ]
        ]);
    }

    public function analyzeSentiment(string $coinPair, array $verifiedNews): array
    {
        // PRODUCTION SAFEGUARD: API Key is required
        if (empty($this->apiKey)) {
            throw new Exception("CRITICAL ERROR: AI API key is not set. Analysis halted to avoid the risk of bogus trading.");
        }

        if (empty($verifiedNews)) {
            throw new Exception("No verified news available for AI analysis.");
        }

        $newsText = "";
        foreach ($verifiedNews as $index => $news) {
            $newsText .= ($index + 1) . ". [" . $news['source'] . "] " . $news['title'] . "\n";
        }

        $systemPrompt = "You are Fixzy Kriptobot, an institutional-grade algorithmic trading engine. Your task is to analyze the latest market news for {$coinPair} and provide a high-precision trading signal.";

        $userPrompt = "Please analyze the following verified set of news:\n\n{$newsText}\n\n"
                    . "Based on the macro and blockchain sentiment above, determine the trading signal for {$coinPair}. "
                    . "You MUST reply using only a valid JSON format with the following structure: "
                    . "{\"signal\": \"BUY\"|\"SELL\"|\"HOLD\", \"confidence\": <integer between 0-100>, \"reason\": \"<concise analytical explanation in English>\"}.";

        try {
            $response = $this->httpClient->request('POST', 'chat/completions', [
                'json' => [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt]
                    ],
                    'temperature' => 0.1,
                    'response_format' => ['type' => 'json_object']
                ]
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            $reply = $body['choices'][0]['message']['content'] ?? '';

            $reply = preg_replace('/```json|```/', '', $reply);
            $result = json_decode(trim($reply), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("AI did not return a valid JSON format. Raw response: " . $reply);
            }

            if (!isset($result['signal']) || !isset($result['confidence']) || !isset($result['reason'])) {
                throw new Exception("The JSON structure from the AI does not meet the standard.");
            }

            $result['signal'] = strtoupper($result['signal']);

            return $result;

        } catch (Exception $e) {
            throw new Exception("Failed to connect to the AI API: " . $e->getMessage());
        }
    }

    /**
     * Analyze real-time OHLCV market conditions and return a directional signal.
     *
     * Unlike analyzeSentiment() (news-based), this examines raw candlestick data
     * fetched from the exchange and answers BUY / SELL / EMPTY only.
     *
     * @param string $symbol    Trading pair, e.g. 'BTC/USDT'
     * @param string $timeframe Candle timeframe, e.g. '15m', '1h'
     * @param array  $candles   OHLCV rows: [ [ts, open, high, low, close, volume], ... ]
     *
     * @return array{signal: string, confidence: int, reason: string}
     *         signal is normalized to 'BUY', 'SELL' or 'EMPTY'
     *         (EMPTY = no conviction; dependent conditions do not pass, bot waits)
     */
    public function analyzeMarket(string $symbol, string $timeframe, array $candles): array
    {
        if (empty($this->apiKey)) {
            throw new Exception("CRITICAL ERROR: AI API key is not set. Analysis halted to avoid the risk of bogus trading.");
        }

        if (empty($candles)) {
            throw new Exception("No OHLCV candle data available for market analysis.");
        }

        // Keep the prompt compact: last 50 candles, rounded values.
        $candles = array_slice($candles, -50);
        $rows = '';
        foreach ($candles as $c) {
            $rows .= sprintf(
                "%s,%s,%s,%s,%s,%s\n",
                round((float)($c[1] ?? 0), 6),
                round((float)($c[2] ?? 0), 6),
                round((float)($c[3] ?? 0), 6),
                round((float)($c[4] ?? 0), 6),
                (int) round((float)($c[5] ?? 0)),
                date('Y-m-d H:i', (int) round((($c[0] ?? 0) / 1000)))
            );
        }

        $systemPrompt = "You are Fixzy Kriptobot, an institutional-grade technical analyst. You analyze OHLCV candlestick data (price action, trend, momentum, volume) and produce disciplined directional signals. You never force a signal when the market is unclear.";

        $userPrompt = "Pair: {$symbol} | Timeframe: {$timeframe}\n"
            . "Candles (open, high, low, close, volume, time), oldest first:\n{$rows}\n"
            . "Based purely on the candlestick data above, determine the trading signal for {$symbol}.\n"
            . "You MUST reply using only a valid JSON format with the following structure: "
            . "{\"signal\": \"BUY\"|\"SELL\"|\"EMPTY\", \"confidence\": <integer between 0-100>, \"reason\": \"<concise analytical explanation in English>\"}.\n"
            . "Rules: use BUY only for a clear bullish setup, SELL only for a clear bearish setup, and EMPTY when the market is unclear, choppy, or you lack conviction.";

        try {
            $response = $this->httpClient->request('POST', 'chat/completions', [
                'json' => [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt]
                    ],
                    'temperature' => 0.1,
                    'response_format' => ['type' => 'json_object']
                ]
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            $reply = $body['choices'][0]['message']['content'] ?? '';

            $reply = preg_replace('/```json|```/', '', $reply);
            $result = json_decode(trim($reply), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("AI did not return a valid JSON format. Raw response: " . $reply);
            }

            $signal = strtoupper(trim((string) ($result['signal'] ?? '')));
            if (!in_array($signal, ['BUY', 'SELL'], true)) {
                $signal = 'EMPTY';
            }

            return [
                'signal'     => $signal,
                'confidence' => (int) ($result['confidence'] ?? 0),
                'reason'     => (string) ($result['reason'] ?? ''),
            ];

        } catch (Exception $e) {
            throw new Exception("Failed to connect to the AI API: " . $e->getMessage());
        }
    }
}