<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Support;

use OmniWrite\Ai\HttpClient;

/** Antwortet der Reihe nach mit vorbereiteten Antworten und merkt sich die Anfragen. */
final class FakeHttpClient implements HttpClient
{
    /** @var list<array{status: int, body: string}> */
    private array $queue = [];
    /** @var list<array{url: string, headers: array<string, string>, json: string}> */
    public array $requests = [];
    /** Wird bei jeder Anfrage aufgerufen (z. B. um Daten „während des Aufrufs“ zu ändern). */
    public ?\Closure $onRequest = null;

    public function push(int $status, string $body): self
    {
        $this->queue[] = ['status' => $status, 'body' => $body];
        return $this;
    }

    /** @param array<string, mixed> $input */
    public function pushToolUse(array $input): self
    {
        return $this->push(200, json_encode(['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'name' => 'x', 'input' => $input]]], JSON_THROW_ON_ERROR));
    }

    public function pushImage(string $binary): self
    {
        return $this->push(200, json_encode(['data' => [['b64_json' => base64_encode($binary)]]], JSON_THROW_ON_ERROR));
    }

    public function postJson(string $url, array $headers, string $json, int $timeoutSeconds): array
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'json' => $json];
        if ($this->onRequest !== null) {
            ($this->onRequest)();
        }
        return array_shift($this->queue) ?? ['status' => 0, 'body' => ''];
    }

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, pin: ?string}> */
    public array $calls = [];

    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds, ?string $pinIp = null): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'pin' => $pinIp];
        if ($this->onRequest !== null) {
            ($this->onRequest)();
        }
        return array_shift($this->queue) ?? ['status' => 0, 'body' => ''];
    }

    /** @return array<string, mixed> JSON-Body der letzten Anfrage */
    public function lastPayload(): array
    {
        return json_decode(end($this->requests)['json'], true, 512, JSON_THROW_ON_ERROR);
    }

    public static function png(): string
    {
        $im = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($im);
        return (string) ob_get_clean();
    }

    /** @return array<string, string> */
    public static function post(string $title = 'KI Titel'): array
    {
        return [
            'title' => $title, 'slug' => 'ki-titel', 'excerpt' => 'Kurz.', 'meta_description' => 'Meta.', 'keywords' => 'a, b',
            'category' => 'Technik', 'body_html' => '<p>Erster Absatz.</p><script>alert(1)</script><h2>FAQ</h2><p>Antwort.</p>',
            'image_prompt' => 'Ein Büro bei Tageslicht', 'cover_alt' => 'Büro',
        ];
    }
}
