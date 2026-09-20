<?php

namespace Fixzy\Kriptobot\Agent;

use Fixzy\Kriptobot\Agent\Tools\ToolResult;
use Fixzy\Kriptobot\Agent\Approval\ApprovalManager;
use Fixzy\Kriptobot\Notifications\NotificationService;
use Fixzy\Kriptobot\Database\AuditLogger;
use Fixzy\Kriptobot\Database\Database;
use Fixzy\Kriptobot\Config\Config;

class AgentOrchestrator
{
    private AgentLlmClient $llmClient;
    private AgentToolRegistry $toolRegistry;
    private AgentSession $session;
    private AgentPromptBuilder $promptBuilder;
    private RiskProfiler $riskProfiler;
    private ApprovalManager $approvalManager;
    private AuditLogger $auditLogger;
    private int $maxIterations = 10;
    private string $lang = 'en';

    public function __construct(
        string $aiApiKey,
        string $model,
        string $baseUrl
    ) {
        $this->llmClient        = new AgentLlmClient($aiApiKey, $model, $baseUrl);
        $this->toolRegistry     = new AgentToolRegistry();
        $this->session          = new AgentSession();
        $this->promptBuilder    = new AgentPromptBuilder();
        $this->riskProfiler     = new RiskProfiler();
        $this->approvalManager  = new ApprovalManager();
        $this->auditLogger      = new AuditLogger(Database::getConnection());

        $this->registerDefaultTools();
    }

    public function setLanguage(string $lang): void
    {
        $this->lang = $lang === 'ms' ? 'ms' : 'en';
        $this->promptBuilder->setLanguage($this->lang);
    }

    public function setMaxIterations(int $max): void
    {
        $this->maxIterations = $max;
    }

    /**
     * Register all default tools.
     */
    private function registerDefaultTools(): void
    {
        // Market Analysis
        $this->toolRegistry->register(new Tools\MarketAnalysis\AnalyzeMarketTool());
        $this->toolRegistry->register(new Tools\MarketAnalysis\AnalyzeTechnicalTool());
        $this->toolRegistry->register(new Tools\MarketAnalysis\AnalyzeSentimentTool());
        $this->toolRegistry->register(new Tools\MarketAnalysis\AnalyzeVolatilityTool());
        $this->toolRegistry->register(new Tools\MarketAnalysis\AnalyzeTrendTool());

        // Data
        $this->toolRegistry->register(new Tools\Data\GetHistoricalDataTool());
        $this->toolRegistry->register(new Tools\Data\GetTradeHistoryTool());
        $this->toolRegistry->register(new Tools\Data\ListBotsTool());
        $this->toolRegistry->register(new Tools\Data\GetBotStatusTool());

        // Backtesting
        $this->toolRegistry->register(new Tools\Backtesting\RunBacktestTool());
        $this->toolRegistry->register(new Tools\Backtesting\OptimizeStrategyTool());

        // Bot Management
        $this->toolRegistry->register(new Tools\BotManagement\CreateBotTool());
        $this->toolRegistry->register(new Tools\BotManagement\UpdateBotConfigTool());
        $this->toolRegistry->register(new Tools\BotManagement\ActivateBotTool());
        $this->toolRegistry->register(new Tools\BotManagement\DeleteBotTool());
        $this->toolRegistry->register(new Tools\BotManagement\MonitorBotTool());
    }

    /**
     * Main entry point: process a user message.
     *
     * @return array Response with messages, tool calls, and optional proposal
     */
    public function processMessage(int $userId, string $message, ?int $sessionId = null): array
    {
        // Check agent settings
        $settings = $this->approvalManager->getAgentSettings($userId);
        if (!$settings['agent_enabled']) {
            return $this->errorResponse('AI Agent is not enabled. Enable it in Global Settings.');
        }

        // Auto-detect language if set to auto
        if ($settings['language_preference'] === 'auto') {
            $this->detectLanguage($message);
        } elseif ($settings['language_preference'] === 'ms') {
            $this->setLanguage('ms');
        } else {
            $this->setLanguage('en');
        }

        // Create or get session
        if (!$sessionId) {
            $sessionId = $this->session->create($userId, mb_substr($message, 0, 50), $this->lang);
        }

        // Store user message
        $this->session->addMessage($sessionId, 'user', $message);

        // Build system prompt
        $systemPrompt = $this->promptBuilder->buildSystemPrompt();

        // Get conversation history for LLM
        $history = $this->session->getMessagesForLlm($sessionId, 20);

        $messages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $history
        );

        // Get tools based on user's allowed actions
        $allowedActions = $settings['allowed_actions'];
        $tools = $this->toolRegistry->getFilteredToolDefinitions($allowedActions, $this->lang);

        // Agent loop
        $iteration = 0;
        $finalResponse = null;
        $toolTrace = [];

        while ($iteration < $this->maxIterations) {
            $iteration++;

            $response = empty($tools)
                ? $this->llmClient->simpleChat($messages)
                : $this->llmClient->chatWithTools($messages, $tools);

            $content = $response['content'] ?? '';
            $toolCalls = $response['tool_calls'] ?? null;

            // Store assistant message
            $this->session->addMessage(
                $sessionId, 'assistant', $content,
                $toolCalls, null,
                $response['usage'] ? ($response['usage']['total_tokens'] ?? 0) : 0
            );

            // No tool calls => final response
            if (empty($toolCalls)) {
                $finalResponse = [
                    'role'    => 'assistant',
                    'content' => $content,
                    'tool_trace' => $toolTrace,
                ];
                break;
            }

            // Execute tool calls
            $toolResults = [];
            foreach ($toolCalls as $tc) {
                $funcName = $tc['function']['name'] ?? '';
                $funcArgs = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];

                $startTime = microtime(true);
                $result = $this->toolRegistry->execute($funcName, $funcArgs, $userId);
                $elapsed = round((microtime(true) - $startTime) * 1000, 2);

                $toolTrace[] = [
                    'tool'     => $funcName,
                    'params'   => $funcArgs,
                    'success'  => $result->success,
                    'elapsed_ms' => $elapsed,
                ];

                $toolResults[] = [
                    'tool_call_id' => $tc['id'] ?? '',
                    'name'   => $funcName,
                    'result' => $result,
                ];

                // Store tool message
                $this->session->addMessage(
                    $sessionId, 'tool',
                    $result->toJson(),
                    null,
                    ['tool_call_id' => $tc['id'] ?? '', 'tool_name' => $funcName]
                );

                // Audit log
                $this->auditLogger->log(
                    null, $userId, 'AGENT_TOOL_CALL',
                    "Agent called tool: {$funcName}",
                    [
                        'session_id' => $sessionId,
                        'tool'       => $funcName,
                        'params'     => $funcArgs,
                        'success'    => $result->success,
                        'elapsed_ms' => $elapsed,
                    ]
                );
            }

            // Add assistant and tool messages to LLM context
            $assistantMsg = ['role' => 'assistant', 'content' => $content, 'tool_calls' => $toolCalls];
            if (!empty($response['reasoning_content'])) {
                $assistantMsg['reasoning_content'] = $response['reasoning_content'];
            }
            $messages[] = $assistantMsg;
            foreach ($toolResults as $tr) {
                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $tr['tool_call_id'],
                    'content'      => $tr['result']->toJson(),
                ];
            }
        }

        // Check if we hit max iterations
        if ($finalResponse === null) {
            $finalResponse = [
                'role'    => 'assistant',
                'content' => $this->lang === 'ms'
                    ? 'I have reached the maximum number of analysis iterations. Please summarize what you need.'
                    : 'I reached the maximum analysis iterations. Please summarize what you need.',
                'tool_trace' => $toolTrace,
            ];
        }

        // Check if the response contains a bot config proposal
        $proposal = $this->extractProposal($finalResponse['content'], $userId, $sessionId);

        // If proposal found AND autonomy is approval_required, store as pending
        if ($proposal) {
            $isFullAutonomy = $this->approvalManager->isFullAutonomy($userId);

            $decisionId = $this->approvalManager->createDecision(
                $userId,
                $sessionId,
                $proposal['decision_type'],
                $proposal['config'],
                $proposal['bot_id'],
                $proposal['justification'],
                $proposal['market_snapshot'] ?? null,
                $proposal['risk_profile'] ?? null,
                $proposal['backtest_results'] ?? null,
                $proposal['original_config'] ?? null
            );

            $proposal['decision_id'] = $decisionId;

            if ($isFullAutonomy && in_array('create_bot', $settings['allowed_actions'])) {
                $approval = $this->approvalManager->approve($decisionId, $userId, true);
                $proposal['auto_applied'] = $approval['success'] ?? false;
            } elseif ($settings['telegram_notifications']) {
                $telegramToken = Config::get('TELEGRAM_BOT_TOKEN');
                $user = Database::getConnection()->executeQuery(
                    "SELECT telegram_chat_id FROM users WHERE id = ?", [$userId]
                )->fetchAssociative();
                $chatId = is_array($user) ? ($user['telegram_chat_id'] ?? '') : '';

                if ($telegramToken && $chatId) {
                    $this->approvalManager->sendTelegramApproval(
                        $decisionId, $userId, $telegramToken, $chatId
                    );
                    $proposal['telegram_sent'] = true;
                }
            }

            $proposal['requires_approval'] = !$isFullAutonomy;
        }

        return [
            'success'   => true,
            'session_id' => $sessionId,
            'message'   => $finalResponse,
            'proposal'  => $proposal,
        ];
    }

    /**
     * Extract bot config proposal from LLM response text.
     */
    private function extractProposal(string $content, int $userId, int $sessionId): ?array
    {
        // Try to find JSON config block in the response
        if (!preg_match('/```(?:json)?\s*(\{[\s\S]*?"general"[\s\S]*?\})\s*```/', $content, $matches)) {
            // Try without code block
            if (!preg_match('/(\{[\s\S]*?"general"[\s\S]*?"risk_management"[\s\S]*?\})/', $content, $matches)) {
                return null;
            }
        }

        $configJson = $matches[1];
        $config = json_decode($configJson, true);

        if (!$config || !isset($config['general'])) {
            return null;
        }

        // Extract justification (text outside the JSON block)
        $cleanContent = preg_replace('/```(?:json)?\s*\{[\s\S]*?\}\s*```/', '', $content);
        $cleanContent = preg_replace('/\{[\s\S]*?"general"[\s\S]*?"risk_management"[\s\S]*?\}/', '', $cleanContent);
        $justification = trim($cleanContent) ?: 'AI Agent generated configuration based on analysis.';

        // Determine decision type
        $decisionType = 'create_bot';
        $botId = null;
        if (preg_match('/bot[_\s]*#?\s*(\d+)/i', $content, $botMatch)) {
            $botId = (int)$botMatch[1];
            $decisionType = 'update_config';
        }

        // Extract risk profile from justification
        $riskProfile = $this->riskProfiler->analyzeUserInput($justification, $this->lang);

        return [
            'decision_type'    => $decisionType,
            'bot_id'           => $botId,
            'config'           => $config,
            'justification'    => $justification,
            'risk_profile'     => $riskProfile,
            'original_config'  => null,
            'market_snapshot'  => null,
            'backtest_results' => null,
        ];
    }

    private function detectLanguage(string $text): void
    {
        $msKeywords = ['i', 'you', 'create', 'bot', 'trading', 'risk', 'low', 'high',
            'profit', 'maximum', 'analysis', 'market', 'configuration', 'change', 'activate',
            'deactivate', 'performance', 'recommendation', 'approve', 'reject'];

        $msCount = 0;
        $textLower = strtolower($text);
        foreach ($msKeywords as $kw) {
            if (strpos($textLower, $kw) !== false) {
                $msCount++;
            }
        }

        $this->setLanguage($msCount >= 3 ? 'ms' : 'en');
    }

    private function errorResponse(string $message): array
    {
        return [
            'success' => false,
            'error'   => $message,
        ];
    }

    /**
     * Process Telegram approval command.
     */
    public function processTelegramCommand(int $userId, string $command, string $args): array
    {
        $decisionId = (int)trim($args);

        if ($decisionId <= 0) {
            return ['success' => false, 'error' => 'Invalid decision ID. Usage: /approve 123'];
        }

        return match ($command) {
            'approve' => $this->approvalManager->approve($decisionId, $userId, true),
            'reject'  => $this->approvalManager->reject($decisionId, $userId, 'Rejected via Telegram'),
            default   => ['success' => false, 'error' => "Unknown command: {$command}"],
        };
    }
}
