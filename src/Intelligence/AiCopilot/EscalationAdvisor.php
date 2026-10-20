<?php

namespace Fixzy\Kriptobot\Intelligence\AiCopilot;

use Fixzy\Kriptobot\Config\Config;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * L3 escalation question: a FOCUSED, one-shot ask about a paused position
 * (flash move / drawdown / DCA exhausted). Runs only inside bin/ai_copilot_loop.php.
 * The answer is a single disciplined action; anything malformed → null and the
 * bot simply stays paused (fail-safe).
 *
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
final class EscalationAdvisor
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
     * @param array<string,mixed> $snapshot market_snapshot written by Escalation::openDecision
     * @return array{role:string,content:string}[]
     */
    public static function buildPrompt(string $symbol, array $snapshot): array
    {
        $system = 'You are the risk escalation brain (L3) of Fixzy Kriptobot, a spot-only '
            . 'DCA grid bot. A bot was AUTO-PAUSED because a hard trigger fired. You get the '
            . 'position snapshot and the trigger. Choose exactly ONE action: '
            . '"hold" (keep the position, resume normal bot logic), '
            . '"wait" (keep it frozen and re-evaluate later), or '
            . '"cut" (market-sell everything now; only for decisive breakdowns, never on noise). '
            . 'You never invent data. When evidence is weak choose "wait". '
            . 'Reply ONLY with a JSON object, no prose, matching exactly: '
            . '{"action":"hold|wait|cut","confidence":0.0-1.0,"reason":"short English sentence"}';
        $user = sprintf(
            "Pair: %s\nTrigger: %s\nSnapshot: %s\n"
            . "The bot is paused (no new entries, no DCA; take-profit/trailing sells still run). "
            . "Decide the single best action for the open position now.",
            $symbol,
            (string)($snapshot['trigger'] ?? '?'),
            (string)json_encode($snapshot['detail'] ?? [], JSON_UNESCAPED_SLASHES)
        );
        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array{ok:bool, verdict?:array{action:string,confidence:float,reason:string}, stage?:string, errors?:list<string>}
     */
    public function ask(string $symbol, array $snapshot): array
    {
        try {
            $response = $this->http->request('POST', 'chat/completions', [
                'json' => [
                    'model' => $this->model,
                    'messages' => self::buildPrompt($symbol, $snapshot),
                    'temperature' => 0.1,
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
        $verdict = EscalationExecutor::parseVerdict(json_decode($reply, true));
        if ($verdict === null) {
            return ['ok' => false, 'stage' => 'schema', 'errors' => ['reply not a valid L3 verdict']];
        }
        return ['ok' => true, 'verdict' => $verdict];
    }
}
