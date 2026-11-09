<?php
declare(strict_types=1);

namespace OmniWrite\Http\Controller;

use OmniWrite\Http\Request;
use OmniWrite\Http\Response;
use OmniWrite\Http\View;
use OmniWrite\Service\BlogView;
use OmniWrite\Service\PublicBlogService;

/** Öffentliche Auslieferung: Startseite, Beitrag, Sitemap, RSS, llms.txt, robots.txt. Gleiche Handler am Portal (/b/<slug>) und auf eigenen Domains. */
final class PublicBlogController
{
    public function __construct(private readonly View $view, private readonly PublicBlogService $blog)
    {
    }

    public function index(Request $r): Response
    {
        return $this->withBlog($r, function (array $site, BlogView $b) use ($r): Response {
            $data = $this->blog->page($site, (int) ($r->query['page'] ?? 1));
            $url = $b->abs('/') . ($data['page'] > 1 ? '?page=' . $data['page'] : '');
            return $this->view->renderPublic($r, 'blog_index', [
                'b' => $b, 'posts' => $data['posts'], 'page' => $data['page'], 'pages' => $data['pages'],
                'title' => $b->name(), 'description' => (string) ($b->theme['blog_tagline'] ?? ''), 'canonical' => $url,
                'date' => $this->dateFormatter($r->locale), 'jsonLd' => null,
            ]);
        });
    }

    public function post(Request $r): Response
    {
        return $this->withBlog($r, function (array $site, BlogView $b) use ($r): Response {
            $post = $this->blog->post($site, (string) ($r->params['post'] ?? ''));
            if ($post === null) {
                return Response::text('Not found', 404);
            }
            $slug = rawurlencode((string) $post['slug']);
            $image = (string) $post['cover_image'] !== '' ? $b->absMedia((string) $post['cover_image']) : null;
            $ld = array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'BlogPosting',
                'headline' => (string) $post['title'],
                'description' => (string) $post['meta_description'],
                'image' => $image,
                'datePublished' => $this->iso($post['published_at'] ?? null),
                'dateModified' => $this->iso($post['updated_at'] ?? $post['published_at'] ?? null),
                'mainEntityOfPage' => $b->abs('/' . $slug),
                'inLanguage' => (string) $site['locale'],
                'keywords' => (string) $post['keywords'],
                'publisher' => ['@type' => 'Organization', 'name' => $b->name()],
            ], static fn (mixed $v): bool => $v !== null && $v !== '');
            return $this->view->renderPublic($r, 'blog_post', [
                'b' => $b, 'post' => $post, 'title' => (string) $post['title'] . ' – ' . $b->name(),
                'description' => (string) $post['meta_description'], 'canonical' => $b->abs('/' . $slug), 'image' => $image,
                'date' => $this->dateFormatter($r->locale),
                'jsonLd' => json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR),
            ]);
        });
    }

    public function sitemap(Request $r): Response
    {
        return $this->withBlog($r, function (array $site, BlogView $b): Response {
            $posts = $this->blog->recent($site, \OmniWrite\Service\PublicBlogService::SITEMAP_MAX);
            $urls = '<url><loc>' . $this->x($b->abs('/')) . '</loc></url>';
            foreach ($posts as $p) {
                $urls .= '<url><loc>' . $this->x($b->abs('/' . rawurlencode((string) $p['slug']))) . '</loc>'
                    . '<lastmod>' . $this->x(substr((string) $this->iso($p['updated_at'] ?? $p['published_at'] ?? null), 0, 10)) . '</lastmod></url>';
            }
            return Response::xml('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . $urls . '</urlset>');
        });
    }

    public function feed(Request $r): Response
    {
        return $this->withBlog($r, function (array $site, BlogView $b): Response {
            $items = '';
            $latest = null;
            foreach ($this->blog->recent($site, \OmniWrite\Service\PublicBlogService::FEED_ITEMS) as $summary) {
                $p = $this->blog->post($site, (string) $summary['slug']);
                if ($p === null) {
                    continue;
                }
                $link = $b->abs('/' . rawurlencode((string) $p['slug']));
                $ts = strtotime((string) $p['published_at'] . ' UTC') ?: time();
                $latest ??= $ts;
                $body = str_replace(']]>', ']]]]><![CDATA[>', (string) $p['body_html']);
                $items .= '<item><title>' . $this->x((string) $p['title']) . '</title><link>' . $this->x($link) . '</link>'
                    . '<guid isPermaLink="true">' . $this->x($link) . '</guid><pubDate>' . gmdate(DATE_RSS, $ts) . '</pubDate>'
                    . ((string) $p['category'] !== '' ? '<category>' . $this->x((string) $p['category']) . '</category>' : '')
                    . '<description>' . $this->x((string) $p['excerpt']) . '</description>'
                    . '<content:encoded><![CDATA[' . $body . ']]></content:encoded></item>';
            }
            $xml = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel>'
                . '<title>' . $this->x($b->name()) . '</title><link>' . $this->x($b->abs('/')) . '</link>'
                . '<description>' . $this->x((string) ($b->theme['blog_tagline'] ?? '') !== '' ? (string) $b->theme['blog_tagline'] : $b->name()) . '</description>'
                . '<language>' . $this->x((string) $site['locale']) . '</language>'
                . '<atom:link href="' . $this->x($b->abs('/feed.xml')) . '" rel="self" type="application/rss+xml"/>'
                . ($latest !== null ? '<lastBuildDate>' . gmdate(DATE_RSS, $latest) . '</lastBuildDate>' : '')
                . $items . '</channel></rss>';
            return Response::xml($xml, 200, 'application/rss+xml');
        });
    }

    public function llms(Request $r): Response
    {
        return $this->withBlog($r, function (array $site, BlogView $b): Response {
            $out = '# ' . $this->line($b->name()) . "\n";
            $tagline = (string) ($b->theme['blog_tagline'] ?? '');
            if ($tagline !== '') {
                $out .= '> ' . $this->line($tagline) . "\n";
            }
            $out .= "\n## Posts\n";
            foreach ($this->blog->recent($site, \OmniWrite\Service\PublicBlogService::SITEMAP_MAX) as $p) {
                $out .= '- [' . $this->line((string) $p['title']) . '](' . $b->abs('/' . rawurlencode((string) $p['slug'])) . ')'
                    . ((string) $p['excerpt'] !== '' ? ': ' . $this->line(mb_substr((string) $p['excerpt'], 0, 200)) : '') . "\n";
            }
            return new Response(200, [
                'Content-Type' => 'text/plain; charset=utf-8', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=300',
            ], $out);
        });
    }

    /** robots.txt: auf der eigenen Domain mit Sitemap-Verweis, am Portal schirmt sie das Backend ab. */
    public function robots(Request $r): Response
    {
        $body = "User-agent: *\nDisallow: /\nAllow: /b/\nAllow: /media/\n";
        if ($r->publicSite !== null) {
            $b = $this->blog->view($r->publicSite, true);
            $body = "User-agent: *\nAllow: /\nSitemap: " . $b->abs('/sitemap.xml') . "\n";
        }
        return new Response(200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=3600'], $body);
    }

    /** @param callable(array<string, mixed>, BlogView): Response $handler */
    private function withBlog(Request $r, callable $handler): Response
    {
        $viaDomain = $r->publicSite !== null;
        $site = $r->publicSite ?? $this->blog->siteBySlug((string) ($r->params['site'] ?? ''));
        if ($site === null) {
            return Response::text('Not found', 404);
        }
        $r->locale = (string) $site['locale'] === 'en' ? 'en' : 'de';
        return $handler($site, $this->blog->view($site, $viaDomain));
    }

    private function dateFormatter(string $locale): \Closure
    {
        return static function (mixed $ts) use ($locale): string {
            $t = $ts ? strtotime((string) $ts . ' UTC') : false;
            return $t === false ? '' : gmdate($locale === 'de' ? 'd.m.Y' : 'M j, Y', $t);
        };
    }

    private function iso(mixed $ts): ?string
    {
        $t = $ts ? strtotime((string) $ts . ' UTC') : false;
        return $t === false ? null : gmdate('c', $t);
    }

    private function x(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function line(string $s): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $s));
    }
}
