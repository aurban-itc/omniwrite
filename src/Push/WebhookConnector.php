<?php
declare(strict_types=1);

namespace OmniWrite\Push;

use OmniWrite\Ai\HttpClient;

/**
 * POST mit JSON an eine HTTPS-Adresse des Kunden. Signatur: X-OmniWrite-Signature: sha256=<HMAC-SHA256 des Bodys mit dem Geheimnis>.
 * Ereignisse: post.published (neu), post.updated (geändert), post.unpublished (zurückgezogen).
 */
final class WebhookConnector implements PushConnector
{
    public function __construct(private readonly HttpClient $http, private readonly UrlGuard $guard)
    {
    }

    public function publish(array $cfg, array $site, array $payload, string $prevRef): string
    {
        $this->send($cfg, $site, $prevRef === '' ? 'post.published' : 'post.updated', $payload);
        return 'sent';
    }

    public function remove(array $cfg, array $site, array $payload, string $prevRef): void
    {
        $this->send($cfg, $site, 'post.unpublished', $payload);
    }

    /** @param array<string, mixed> $cfg @param array<string, mixed> $site @param array<string, mixed> $payload */
    private function send(array $cfg, array $site, string $event, array $payload): void
    {
        [$ip, $err] = $this->guard->resolve((string) ($cfg['url'] ?? ''));
        if ($ip === null) {
            throw new PushException('Adresse nicht erlaubt (' . $err . ')', false);
        }
        $body = json_encode([
            'event' => $event,
            'site' => ['slug' => (string) $site['slug'], 'name' => (string) $site['name']],
            'post' => $payload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $res = $this->http->request('POST', (string) $cfg['url'], [
            'Content-Type' => 'application/json',
            'User-Agent' => 'OmniWrite-Webhook/1',
            'X-OmniWrite-Event' => $event,
            'X-OmniWrite-Signature' => 'sha256=' . hash_hmac('sha256', $body, (string) $cfg['secret']),
        ], $body, 15, $ip);
        PushHttp::expect($res, [200, 201, 202, 204], 'Webhook');
    }
}
