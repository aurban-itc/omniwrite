<?php
declare(strict_types=1);

namespace OmniWrite\Service;

use OmniWrite\Domain\SiteContext;
use OmniWrite\Repository\DomainRepository;
use OmniWrite\Repository\PostRepository;
use OmniWrite\Repository\SiteRepository;
use PDO;

/** Lesezugriff auf das öffentliche Blog: nur veröffentlichte Beiträge, nie Entwürfe. */
final class PublicBlogService
{
    public const PER_PAGE = 10;
    public const FEED_ITEMS = 20;
    public const SITEMAP_MAX = 5000;

    public function __construct(
        private readonly PDO $pdo,
        private readonly BlogSettingsService $settings,
        private readonly string $baseUrl,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function siteBySlug(string $slug): ?array
    {
        return $slug === '' ? null : (new SiteRepository($this->pdo))->findBySlug($slug);
    }

    /** @return array<string, mixed>|null Site hinter einer verifizierten eigenen Domain */
    public function siteByHost(string $host): ?array
    {
        return $host === '' ? null : (new DomainRepository($this->pdo))->verifiedSiteByHost($host);
    }

    /** @param array<string, mixed> $site @param bool $viaDomain Aufruf kam über die eigene Domain */
    public function view(array $site, bool $viaDomain): BlogView
    {
        $ctx = new SiteContext((int) $site['id']);
        $domain = (new DomainRepository($this->pdo))->forSite((int) $site['id']);
        $portal = rtrim($this->baseUrl, '/');
        $own = $domain !== null && $domain['verified_at'] !== null;
        $prefix = '/b/' . rawurlencode((string) $site['slug']);
        return new BlogView(
            $site,
            $this->settings->theme($ctx),
            $viaDomain ? '' : $prefix,
            $own ? 'https://' . $domain['host'] : $portal,
            $own ? '' : $prefix,
        );
    }

    /** @param array<string, mixed> $site @return array{posts: list<array<string, mixed>>, page: int, pages: int} */
    public function page(array $site, int $page): array
    {
        $repo = new PostRepository($this->pdo, new SiteContext((int) $site['id']));
        $pages = max(1, (int) ceil($repo->countPublished() / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        return ['posts' => $repo->listPublished(self::PER_PAGE, ($page - 1) * self::PER_PAGE), 'page' => $page, 'pages' => $pages];
    }

    /** @param array<string, mixed> $site @return array<string, mixed>|null */
    public function post(array $site, string $slug): ?array
    {
        return (new PostRepository($this->pdo, new SiteContext((int) $site['id'])))->findPublishedBySlug($slug);
    }

    /** @param array<string, mixed> $site @return list<array<string, mixed>> */
    public function recent(array $site, int $limit): array
    {
        return (new PostRepository($this->pdo, new SiteContext((int) $site['id'])))->listPublished($limit);
    }
}
