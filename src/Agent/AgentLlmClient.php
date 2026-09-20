<?php

namespace Fixzy\Kriptobot\Agent;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class AgentLlmClient
{
    private string $apiKey;
    private string $model;
    private Client $httpClient;
    private int $maxRetries = 2;

    public function __construct(string $apiKey, string $model, string $baseUrl)
    {
        $this->apiKey  = $apiKey;
        $this->model   = $model;
        if ($baseUrl !== '' && !str_ends_with($baseUrl, '/')) {
            $baseUrl .= '/';
        }
        $this->httpClient = new Client([
            'base_uri' => $baseUrl,
            'timeout'  => 120.0,
            'headers'  => [
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
        ]);
    }

    /**
     * Native tool calling via the AI API (OpenAI-compatible tools parameter).
     */
    public function chatWithTools(
        array $messages,
        array $tools,
        float $temperature = 0.3,
        int $maxTokens = 4096
    ): array {
        $payload = [
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => $temperature,
            'max_tokens'  => $maxTokens,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $response = $this->sendRequest('chat/completions', $payload);
        return $this->parseResponse($response);
    }

    /**
     * ReAct pattern fallback (prompt-based tool calling for models without native support).
     */
    public function reactChat(
        array $messages,
        string $toolDescriptions,
        float $temperature = 0.3,
        int $maxTokens = 4096
    ): array {
        $lastMessage = end($messages);
        $content = $lastMessage['content'] ?? '';

        $prompt = $this->buildReactPrompt($content, $toolDescriptions);

        $messages[count($messages) - 1] = [
            'role'    => 'user',
            'content' => $prompt,
        ];

        $payload = [
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => $temperature,
            'max_tokens'  => $maxTokens,
        ];

        $response = $this->sendRequest('chat/completions', $payload);
        $parsed = $this->parseResponse($response);

        if (!empty($parsed['content'])) {
            $parsed = $this->extractToolCallsFromText($parsed);
        }

        return $parsed;
    }

    /**
     * Simple chat without tool calling.
     */
    public function simpleChat(
        array $messages,
        float $temperature = 0.3,
        int $maxTokens = 4096
    ): array {
        $payload = [
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => $temperature,
            'max_tokens'  => $maxTokens,
        ];

        $response = $this->sendRequest('chat/completions', $payload);
        return $this->parseResponse($response);
    }

    private function sendRequest(string $endpoint, array $payload): array
    {
        $attempt = 0;
        $lastError = null;

        while ($attempt <= $this->maxRetries) {
            try {
                $response = $this->httpClient->request('POST', $endpoint, [
                    'json' => $payload,
                ]);

                $body = json_decode($response->getBody()->getContents(), true);
                return $body;

            } catch (GuzzleException $e) {
                $lastError = $e;
                $attempt++;
                if ($attempt <= $this->maxRetries) {
                    usleep(500000 * $attempt);
                }
            }
        }

        throw new \RuntimeException(
            "Agent LLM request failed after {$this->maxRetries} retries: " . $lastError->getMessage()
        );
    }

    private function parseResponse(array $body): array
    {
        $choice = $body['choices'][0] ?? null;
        if (!$choice) {
            throw new \RuntimeException('No choices returned from LLM');
        }

        $message = $choice['message'] ?? [];
        $result = [
            'role'              => $message['role'] ?? 'assistant',
            'content'           => $message['content'] ?? '',
            'reasoning_content' => $message['reasoning_content'] ?? null,
            'tool_calls'        => $message['tool_calls'] ?? null,
            'usage'             => $body['usage'] ?? null,
        ];

        return $result;
    }

    private function buildReactPrompt(string $userQuery, string $toolDescriptions): string
    {
        return <<<PROMPT
You are a trading bot AI agent. You have access to the following tools:

{$toolDescriptions}

When you need to use a tool, respond with a JSON block in the following format:

\`\`\`tool_call
{"tool": "tool_name", "params": {"param1": "value1", "param2": "value2"}}
\`\`\`

You may call MULTIPLE tools in one response by including multiple JSON blocks separated by newlines within a single \`\`\`tool_call block.

If you have enough information to provide a final answer without more tool calls, respond directly in natural language.

User Query: {$userQuery}
PROMPT;
    }

    private function extractToolCallsFromText(array $parsed): array
    {
        $content = $parsed['content'] ?? '';

        if (!preg_match('/```tool_call\s*\n(.*?)\n```/s', $content, $matches)) {
            return $parsed;
        }

        $toolJsonBlock = $matches[1];
        $cleanContent = trim(preg_replace('/```tool_call\s*\n.*?\n```/s', '', $content));
        $parsed['content'] = $cleanContent;

        $toolCalls = [];
        foreach (explode("\n", trim($toolJsonBlock)) as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            $decoded = json_decode($line, true);
            if ($decoded && isset($decoded['tool'])) {
                $toolCalls[] = [
                    'id'   => 'react_' . uniqid(),
                    'type' => 'function',
                    'function' => [
                        'name'      => $decoded['tool'],
                        'arguments' => json_encode($decoded['params'] ?? []),
                    ],
                ];
            }
        }

        if (!empty($toolCalls)) {
            $parsed['tool_calls'] = $toolCalls;
        }

        return $parsed;
    }
}
