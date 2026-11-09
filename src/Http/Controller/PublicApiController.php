<?php
declare(strict_types=1);

namespace OmniWrite\Http\Controller;

use OmniWrite\Http\Request;
use OmniWrite\Http\Response;
use OmniWrite\Service\BlogView;
use OmniWrite\Service\PublicBlogService;
use OmniWrite\Support\Html;

/**
 * Öffentliche, schreibgeschützte JSON-API: nur veröffentlichte Beiträge, für jede Herkunft lesbar (CORS *, keine Cookies).
 * Am Portal unter /api/v1/sites/<kürzel>/posts, auf einer eigenen Domain unter /api/v1/posts.
 */
final class PublicApiController
{
    public function __construct(private readonly PublicBlogService $blog)
    {
    }

    public function posts(Request $r): Response
    {
        return $this->withBlog($r, function (array $site, BlogView $b) use ($r): Response {
            $perPage = (int) ($r->query['per_page'] ?? 10);
            $data = $this->blog->page($site, (int) ($r->query['page'] ?? 1), $perPage > 0 ? $perPage : 10);
            return $this->json([
                'site' => $this->siteInfo($site, $b),
                'page' => $data['page'], 'pages' => $data['pages'], 'total' => $data['total'],
                'posts' => array_map(fn (array $p): array => $this->summary($p, $b), $data['posts']),
            ]);
        });
    }

    public function post(Request $r): Response
    {
        return $this->withBlog($r, function (array $site, BlogView $b) use ($r): Response {
            $post = $this->blog->post($site, (string) ($r->params['post'] ?? ''));
            if ($post === null) {
                return $this->json(['error' => 'not_found'], 404);
            }
            return $this->json([
                'site' => $this->siteInfo($site, $b),
                'post' => $this->summary($post, $b) + [
                    'meta_description' => (string) $post['meta_description'],
                    'keywords' => (string) $post['keywords'],
                    'body_html' => Html::absolutize((string) $post['body_html'], $b->origin, $b->abs('/' . rawurlencode((string) $post['slug'])), $b->abs('/')), // gesäubert, mit absoluten Adressen
                ],
            ]);
        });
    }

    /** @param callable(array<string, mixed>, BlogView): Response $handler */
    private function withBlog(Request $r, callable $handler): Response
    {
        $site = $r->publicSite ?? $this->blog->siteBySlug((string) ($r->params['site'] ?? ''));
        if ($site === null) {
            return $this->json(['error' => 'not_found'], 404);
        }
        return $handler($site, $this->blog->view($site, $r->publicSite !== null));
    }

    /** @param array<string, mixed> $site @return array<string, string> */
    private function siteInfo(array $site, BlogView $b): array
    {
        return ['name' => (string) $site['name'], 'locale' => (string) $site['locale'], 'url' => $b->abs('/')];
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    private function summary(array $p, BlogView $b): array
    {
        $cover = (string) $p['cover_image'];
        return [
            'title' => (string) $p['title'],
            'slug' => (string) $p['slug'],
            'url' => $b->abs('/' . rawurlencode((string) $p['slug'])),
            'excerpt' => (string) $p['excerpt'],
            'category' => (string) $p['category'],
            'cover_image' => $cover !== '' ? $b->absMedia($cover) : null,
            'cover_alt' => (string) $p['cover_alt'],
            'read_minutes' => (int) $p['read_minutes'],
            'published_at' => $this->iso($p['published_at'] ?? $p['created_at'] ?? null),
            'updated_at' => $this->iso($p['updated_at'] ?? $p['published_at'] ?? $p['created_at'] ?? null),
        ];
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = 200): Response
    {
        return new Response($status, [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => $status === 200 ? 'public, max-age=300' : 'no-store',
        ], json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function iso(mixed $ts): ?string
    {
        $t = $ts ? strtotime((string) $ts . ' UTC') : false;
        return $t === false ? null : gmdate('c', $t);
    }
}
