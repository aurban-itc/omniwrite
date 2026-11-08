<?php
declare(strict_types=1);

namespace OmniWrite\Ai;

/** OpenAI Bild-API (gpt-image). Der Schlüssel gehört OmniWrite (config ai.openai_api_key). */
final class OpenAiImageClient implements ImageModel
{
    public const DEFAULT_ENDPOINT = 'https://api.openai.com/v1/images/generations';

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $apiKey,
        private readonly string $model = 'gpt-image-1',
        private readonly string $endpoint = self::DEFAULT_ENDPOINT,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function generate(string $prompt): string
    {
        if (!$this->isConfigured()) {
            throw new AiException('err.ai_not_configured');
        }
        $res = $this->http->postJson(
            $this->endpoint,
            ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $this->apiKey],
            json_encode(['model' => $this->model, 'prompt' => mb_substr($prompt, 0, 3500), 'size' => '1536x1024', 'quality' => 'medium', 'n' => 1], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            150,
        );
        if ($res['status'] === 0) {
            throw new AiException('err.ai_unreachable');
        }
        $json = json_decode($res['body'], true);
        $b64 = is_array($json) ? (string) ($json['data'][0]['b64_json'] ?? '') : '';
        if ($res['status'] !== 200 || $b64 === '') {
            $msg = is_array($json) ? strtolower((string) ($json['error']['message'] ?? '') . ' ' . (string) ($json['error']['code'] ?? '')) : '';
            error_log('openai image: HTTP ' . $res['status'] . ' ' . substr((string) preg_replace('/\s+/', ' ', $msg !== '' ? $msg : $res['body']), 0, 300));
            throw new AiException(match (true) {
                $res['status'] === 401 => 'err.ai_key_rejected',
                $res['status'] === 429 => 'err.ai_busy',
                str_contains($msg, 'moderation') || str_contains($msg, 'safety') => 'err.ai_image_declined',
                default => 'err.ai_failed',
            });
        }
        $raw = base64_decode($b64, true);
        if ($raw === false || $raw === '') {
            throw new AiException('err.ai_unreadable');
        }
        return $raw;
    }
}
