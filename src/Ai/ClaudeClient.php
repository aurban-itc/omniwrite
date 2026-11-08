<?php
declare(strict_types=1);

namespace OmniWrite\Ai;

/** Anthropic Messages API. Der Schlüssel gehört OmniWrite (config ai.anthropic_api_key), nicht dem Kunden. */
final class ClaudeClient implements TextModel
{
    public const DEFAULT_MODEL = 'claude-sonnet-5-5';
    public const DEFAULT_ENDPOINT = 'https://api.anthropic.com/v1/messages';

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $apiKey,
        private readonly string $model = self::DEFAULT_MODEL,
        private readonly string $endpoint = self::DEFAULT_ENDPOINT,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function callTool(string $system, string $user, array $tool, int $maxTokens = 4096, bool $retry = true): array
    {
        if (!$this->isConfigured()) {
            throw new AiException('err.ai_not_configured');
        }
        $payload = [
            'model' => $this->model,
            'max_tokens' => $maxTokens,
            'system' => $system . "\n\nDeliver your answer by calling the tool " . $tool['name'] . '. Do not reply with plain text.',
            'messages' => [['role' => 'user', 'content' => $user]],
            'tools' => [$tool],
            'tool_choice' => ['type' => 'auto'], // manche Modelle lehnen erzwungene Werkzeugwahl ab; der Prompt verlangt den Aufruf
        ];
        $res = $this->http->postJson(
            $this->endpoint,
            ['content-type' => 'application/json', 'x-api-key' => $this->apiKey, 'anthropic-version' => '2023-06-01'],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            150,
        );
        if ($res['status'] === 0) {
            throw new AiException('err.ai_unreachable');
        }
        $json = json_decode($res['body'], true);
        if ($res['status'] !== 200 || !is_array($json)) {
            // Rohtext des Anbieters nur ins Log (ohne Schlüssel), nie an den Nutzer.
            error_log('anthropic: HTTP ' . $res['status'] . ' ' . substr((string) preg_replace('/\s+/', ' ', $res['body']), 0, 300));
            throw new AiException(match (true) {
                $res['status'] === 401 || $res['status'] === 403 => 'err.ai_key_rejected',
                $res['status'] === 429 || $res['status'] === 529 => 'err.ai_busy',
                default => 'err.ai_failed',
            });
        }
        foreach ($json['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'tool_use' && is_array($block['input'] ?? null)) {
                return $block['input'];
            }
        }
        $stop = $json['stop_reason'] ?? '';
        if ($retry && $stop !== 'max_tokens') {
            return $this->callTool($system, $user . "\n\nReminder: answer only by calling the tool " . $tool['name'] . '.', $tool, $maxTokens, false);
        }
        throw new AiException($stop === 'max_tokens' ? 'err.ai_truncated' : 'err.ai_unreadable');
    }
}
