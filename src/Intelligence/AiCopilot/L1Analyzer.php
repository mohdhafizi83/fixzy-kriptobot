<?php

namespace Fixzy\Kriptobot\Intelligence\AiCopilot;

use Fixzy\Kriptobot\Config\Config;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * L1 slow-loop analyzer: builds the prompt from the curated data pack, calls
 * the OpenAI-compatible endpoint once per decision candle, and validates the
 * reply through L1Validator. Runs ONLY inside bin/ai_copilot_loop.php — never
 * inside the 1-minute daemon loop.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class L1Analyzer
{
    private Client $http;

    public function __construct(
        private string $apiKey,
        private string $baseUrl,
        private string $model,
        private float $timeoutSec = 45.0,
    ) {
        if ($baseUrl !== '' && !str_ends_with($baseUrl, '/')) {
            $baseUrl .= '/';
        }
        $this->http = new Client([
            'base_uri' => $baseUrl,
            'timeout'  => $timeoutSec,
            'headers'  => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ],
        ]);
    }

    public static function fromConfig(): ?self
    {
        $cfg = Config::aiConfig();
        if ($cfg['api_key'] === '' || $cfg['base_url'] === '' || $cfg['model'] === '') {
            return null;
        }
        return new self($cfg['api_key'], $cfg['base_url'], $cfg['model']);
    }

    /**
     * Compact system + user prompt. The data pack is already curated and small;
     * we send it as JSON and demand the strict L1 schema back.
     */
    public static function buildPrompt(string $symbol, string $timeframe, array $dataPack): array
    {
        $system = 'You are the slow strategic brain ("L1") of Fixzy Kriptobot, a spot-only '
            . 'DCA grid bot. You read a curated market data pack (features, not raw candles) '
            . 'and output ONE disciplined regime assessment for the decision timeframe. '
            . 'You never invent data that is not in the pack. When evidence is weak or mixed, '
            . 'output regime "sideways", bias "neutral", low confidence, and conservative params. '
            . 'Reply ONLY with a JSON object, no prose, matching exactly: '
            . '{"regime":"trending_up|trending_down|sideways|choppy",'
            . '"bias":"bullish|neutral|bearish",'
            . '"params":{"dca_drop_pct":0.5-10,"max_dca_steps":0-8,"tp_pct":0.5-20,'
            . '"trail_pct":0.1-10,"entry_aggressiveness":"low|normal|high"},'
            . '"confidence":0.0-1.0,"reason":"short English sentence"}';
        $user = sprintf(
            "Pair: %s | Decision timeframe: %s\nData pack (curated features):\n%s\n"
            . "Assess the current regime and recommend DCA-grid parameters for the next %s. "
            . "Higher confidence only when multiple timeframes agree.",
            $symbol,
            $timeframe,
            json_encode($dataPack, JSON_UNESCAPED_SLASHES),
            $timeframe
        );
        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * Call the LLM for one bot/candle. Returns:
     *  ok=true  + decision (validated)          — usable decision
     *  ok=false + stage='llm'|'parse'|'schema' + errors — fail-safe: caller keeps previous decision.
     *
     * @param array<string, mixed> $dataPack
     * @return array{ok: bool, stage?: string, errors?: list<string>, decision?: array<string, mixed>, raw?: string}
     */
    public function analyze(string $symbol, string $timeframe, array $dataPack): array
    {
        try {
            $response = $this->http->request('POST', 'chat/completions', [
                'json' => [
                    'model' => $this->model,
                    'messages' => self::buildPrompt($symbol, $timeframe, $dataPack),
                    'temperature' => 0.2,
                    'response_format' => ['type' => 'json_object'],
                ],
                'stream' => true, // do not buffer a hostile full body in RAM
            ]);
        } catch (GuzzleException $e) {
            return ['ok' => false, 'stage' => 'llm', 'errors' => ['LLM unreachable: ' . $e->getMessage()]];
        }

        // Cap the read: a hostile/compromised endpoint must not OOM the loop.
        // Loop-read the stream (a single read() may return a partial body).
        $stream = $response->getBody();
        $raw = '';
        while (!$stream->eof() && strlen($raw) <= 65536) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                break;
            }
            $raw .= $chunk;
        }
        $body = json_decode($raw, true);
        $reply = (string)($body['choices'][0]['message']['content'] ?? '');
        return self::parseAndValidate($reply);
    }

    /**
     * Strip code fences, parse JSON, validate against the L1 schema.
     *
     * @return array{ok: bool, stage?: string, errors?: list<string>, decision?: array<string, mixed>, raw: string}
     */
    public static function parseAndValidate(string $reply): array
    {
        $clean = trim(preg_replace('/```json|```/', '', $reply) ?? $reply);
        $decoded = json_decode($clean, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['ok' => false, 'stage' => 'parse', 'errors' => ['LLM reply is not valid JSON'], 'raw' => $clean];
        }
        $result = L1Validator::validate($decoded);
        $result['raw'] = $clean;
        if (!$result['ok']) {
            $result['stage'] = 'schema';
        }
        return $result;
    }
}
