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
        // Modellnamen ändern sich bei OpenAI; ist das konfigurierte unbekannt, werden Ersatzmodelle probiert.
        $models = array_values(array_unique([$this->model, 'gpt-image-1.5', 'gpt-image-1-mini', 'dall-e-3']));
        $b64 = '';
        $last = ['status' => 0, 'body' => ''];
        foreach ($models as $model) {
            $legacy = str_starts_with($model, 'dall-e');
            $body = $legacy
                ? ['model' => $model, 'prompt' => mb_substr($prompt, 0, 3500), 'size' => '1792x1024', 'response_format' => 'b64_json', 'n' => 1]
                : ['model' => $model, 'prompt' => mb_substr($prompt, 0, 3500), 'size' => '1536x1024', 'quality' => 'medium', 'n' => 1];
            $res = $this->http->postJson(
                $this->endpoint,
                ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $this->apiKey],
                json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                150,
            );
            if ($res['status'] === 0) {
                throw new AiException('err.ai_unreachable');
            }
            $last = $res;
            $json = json_decode($res['body'], true);
            $b64 = is_array($json) ? (string) ($json['data'][0]['b64_json'] ?? '') : '';
            if ($res['status'] === 200 && $b64 !== '') {
                break;
            }
            $msg = is_array($json) ? strtolower((string) ($json['error']['message'] ?? '') . ' ' . (string) ($json['error']['code'] ?? '')) : '';
            $unknownModel = in_array($res['status'], [400, 403, 404], true) && str_contains($msg, 'model') && (str_contains($msg, 'does not exist') || str_contains($msg, 'not found') || str_contains($msg, 'access'));
            if (!$unknownModel) {
                break;
            }
            error_log('openai image: Modell ' . $model . ' nicht nutzbar: ' . substr($msg, 0, 200));
        }
        if ($last['status'] !== 200 || $b64 === '') {
            $json = json_decode($last['body'], true);
            $msg = is_array($json) ? strtolower((string) ($json['error']['message'] ?? '') . ' ' . (string) ($json['error']['code'] ?? '')) : '';
            $detail = 'OpenAI HTTP ' . $last['status'] . ': ' . substr((string) preg_replace('/\s+/', ' ', $msg !== '' ? $msg : $last['body']), 0, 300);
            error_log('openai image: ' . $detail);
            throw new AiException(match (true) {
                $last['status'] === 401 || $last['status'] === 403 => 'err.ai_key_rejected',
                $last['status'] === 429 => 'err.ai_busy',
                str_contains($msg, 'moderation') || str_contains($msg, 'safety') => 'err.ai_image_declined',
                default => 'err.ai_failed',
            }, $detail);
        }
        $raw = base64_decode($b64, true);
        if ($raw === false || $raw === '') {
            throw new AiException('err.ai_unreadable');
        }
        return $raw;
    }
}
