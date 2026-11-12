<?php
declare(strict_types=1);

namespace OmniWrite\Push;

use OmniWrite\Ai\HttpClient;

/** Schreibt je Beitrag eine Datei <Ordner>/<slug>.<md|html> (Frontmatter + HTML) per GitHub-Contents-API in ein Repository. */
final class GithubConnector implements PushConnector
{
    private const API = 'https://api.github.com';

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function publish(array $cfg, array $site, array $payload, string $prevRef): string
    {
        $path = $this->path($cfg, (string) $payload['slug']);
        $sha = $this->sha($cfg, $path);
        $body = [
            'message' => 'OmniWrite: ' . mb_substr((string) $payload['title'], 0, 120),
            'content' => base64_encode($this->file($payload)),
            'branch' => (string) $cfg['branch'],
        ];
        if ($sha !== null) {
            $body['sha'] = $sha;
        }
        $res = $this->http->request('PUT', $this->contentsUrl($cfg, $path), $this->headers($cfg), json_encode($body, JSON_THROW_ON_ERROR), 20);
        PushHttp::expect($res, [200, 201], 'GitHub');
        if ($prevRef !== '' && $prevRef !== $path) {
            $this->delete($cfg, $prevRef, (string) $payload['title']);   // Slug hat sich geändert: alte Datei entfernen
        }
        return $path;
    }

    public function remove(array $cfg, array $site, array $payload, string $prevRef): void
    {
        $this->delete($cfg, $prevRef !== '' ? $prevRef : $this->path($cfg, (string) $payload['slug']), (string) ($payload['title'] ?? ''));
    }

    /** @param array<string, mixed> $cfg */
    private function delete(array $cfg, string $path, string $title): void
    {
        $sha = $this->sha($cfg, $path);
        if ($sha === null) {
            return;   // schon weg
        }
        $res = $this->http->request('DELETE', $this->contentsUrl($cfg, $path), $this->headers($cfg), json_encode([
            'message' => 'OmniWrite: entfernt' . ($title !== '' ? ': ' . mb_substr($title, 0, 100) : ''), 'sha' => $sha, 'branch' => (string) $cfg['branch'],
        ], JSON_THROW_ON_ERROR), 20);
        PushHttp::expect($res, [200], 'GitHub');
    }

    /** @param array<string, mixed> $cfg */
    private function sha(array $cfg, string $path): ?string
    {
        $res = $this->http->request('GET', $this->contentsUrl($cfg, $path) . '?ref=' . rawurlencode((string) $cfg['branch']), $this->headers($cfg), null, 20);
        if ($res['status'] === 404) {
            return null;
        }
        PushHttp::expect($res, [200], 'GitHub');
        $j = json_decode($res['body'], true);
        $sha = is_array($j) ? ($j['sha'] ?? null) : null;
        if (!is_string($sha) || $sha === '') {
            throw new PushException('GitHub: unerwartete Antwort (keine Datei?)', false);
        }
        return $sha;
    }

    /** @param array<string, mixed> $cfg */
    public function path(array $cfg, string $slug): string
    {
        $dir = trim((string) ($cfg['dir'] ?? ''), '/');
        return ($dir === '' ? '' : $dir . '/') . $slug . '.' . ((string) ($cfg['ext'] ?? 'md') === 'html' ? 'html' : 'md');
    }

    /** @param array<string, mixed> $cfg */
    private function contentsUrl(array $cfg, string $path): string
    {
        return self::API . '/repos/' . (string) $cfg['repo'] . '/contents/' . implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /** @param array<string, mixed> $cfg @return array<string, string> */
    private function headers(array $cfg): array
    {
        return [
            'Authorization' => 'Bearer ' . (string) $cfg['token'],
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'OmniWrite',
            'Content-Type' => 'application/json',
        ];
    }

    /** @param array<string, mixed> $p */
    private function file(array $p): string
    {
        $y = static fn (mixed $v): string => json_encode((string) $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $fm = ["---", 'title: ' . $y($p['title']), 'slug: ' . $y($p['slug']), 'date: ' . $y($p['published_at']), 'updated: ' . $y($p['updated_at']),
            'category: ' . $y($p['category']), 'description: ' . $y($p['meta_description'] !== '' ? $p['meta_description'] : $p['excerpt']), 'excerpt: ' . $y($p['excerpt']),
            'keywords: ' . $y($p['keywords']), 'cover_image: ' . $y($p['cover_image'] ?? ''), 'cover_alt: ' . $y($p['cover_alt']), 'url: ' . $y($p['url']), "---"];
        return implode("\n", $fm) . "\n\n" . (string) $p['body_html'] . "\n";
    }
}
