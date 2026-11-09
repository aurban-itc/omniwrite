<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Http;

use OmniWrite\Domain\SiteContext;
use OmniWrite\Repository\DomainRepository;
use OmniWrite\Repository\PostRepository;
use OmniWrite\Repository\SiteRepository;
use OmniWrite\Tests\Support\KernelTestCase;

final class PublicBlogTest extends KernelTestCase
{
    private int $accountId;
    private int $siteId;
    private PostRepository $posts;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = $this->createUser('owner@example.com');
        $this->accountId = $this->accounts->membershipsOf($owner)[0]['id'];
        $this->siteId = (new SiteRepository($this->pdo))->create($this->accountId, 'alpha', 'Alpha Blog', 'de', 'Europe/Berlin');
        $this->posts = new PostRepository($this->pdo, new SiteContext($this->siteId));
        $this->login('owner@example.com');
    }

    private function login(string $email): void
    {
        $this->sessionData = [];
        $this->call('POST', '/login', ['email' => $email, 'password' => 'some long password']);
    }

    private function post(string $title, string $status = 'published', string $slug = '', string $extra = ''): int
    {
        return $this->posts->insert([
            'title' => $title, 'slug' => $slug ?: strtolower(str_replace(' ', '-', $title)), 'body_html' => '<p>Text von ' . $title . '</p>' . $extra,
            'status' => $status, 'excerpt' => 'Auszug ' . $title, 'meta_description' => 'Meta ' . $title, 'read_minutes' => 3,
            'published_at' => $status === 'published' ? '2026-03-04 10:00:00' : null, 'updated_at' => '2026-03-05 11:00:00',
        ]);
    }

    public function testIndexListsOnlyPublishedPosts(): void
    {
        $this->post('Erster Beitrag');
        $this->post('Geheimer Entwurf', 'draft');
        $this->post('In Pruefung', 'in_review');
        $res = $this->call('GET', '/b/alpha');
        self::assertSame(200, $res->status);
        self::assertStringContainsString('Erster Beitrag', $res->body);
        self::assertStringNotContainsString('Geheimer Entwurf', $res->body);
        self::assertStringNotContainsString('In Pruefung', $res->body);
        self::assertStringContainsString('04.03.2026', $res->body);
        self::assertStringContainsString('<link rel="canonical" href="https://omniwrite.example/b/alpha/">', $res->body);
        self::assertStringContainsString('href="/b/alpha/erster-beitrag"', $res->body);
        self::assertStringNotContainsString('Anmelden', $res->body);
        self::assertStringContainsString('img-src', $res->headers['Content-Security-Policy']);
    }

    public function testPublicPagesNeedNoLoginAndUnknownSitesAre404(): void
    {
        $this->post('Erster Beitrag');
        $this->sessionData = [];
        self::assertSame(200, $this->call('GET', '/b/alpha')->status);
        self::assertSame(200, $this->call('GET', '/b/alpha/')->status);
        self::assertSame(404, $this->call('GET', '/b/nope')->status);
        self::assertSame(404, $this->call('GET', '/b/nope/sitemap.xml')->status);
    }

    public function testPostPageHasJsonLdCanonicalAndNoDraftAccess(): void
    {
        $this->post('Erster Beitrag', 'published', 'erster-beitrag', '<img src="/media/1/ab12cd34ef567890.webp" alt="x">');
        $this->post('Geheimer Entwurf', 'draft', 'geheim');
        $res = $this->call('GET', '/b/alpha/erster-beitrag');
        self::assertSame(200, $res->status);
        self::assertStringContainsString('<title>Erster Beitrag – Alpha Blog</title>', $res->body);
        self::assertStringContainsString('application/ld+json', $res->body);
        self::assertStringContainsString('"@type":"BlogPosting"', $res->body);
        self::assertStringContainsString('"datePublished":"2026-03-04T10:00:00+00:00"', $res->body);
        self::assertStringContainsString('<link rel="canonical" href="https://omniwrite.example/b/alpha/erster-beitrag">', $res->body);
        self::assertStringContainsString('Text von Erster Beitrag', $res->body);
        self::assertSame(404, $this->call('GET', '/b/alpha/geheim')->status);
        self::assertSame(404, $this->call('GET', '/b/alpha/gibt-es-nicht')->status);
    }

    public function testJsonLdCannotBreakOutOfTheScriptTag(): void
    {
        $this->post('</script><script>alert(1)</script>', 'published', 'xss');
        $res = $this->call('GET', '/b/alpha/xss');
        self::assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $res->body, $m));
        self::assertStringNotContainsString('<', $m[1]);
        self::assertStringContainsString('&lt;script&gt;alert(1)', $res->body); // Titel im HTML maskiert
    }

    public function testSitemapFeedAndLlmsTxt(): void
    {
        $this->post('Erster Beitrag');
        $this->post('Geheimer Entwurf', 'draft');
        $map = $this->call('GET', '/b/alpha/sitemap.xml');
        self::assertStringContainsString('application/xml', $map->headers['Content-Type']);
        self::assertStringContainsString('<loc>https://omniwrite.example/b/alpha/erster-beitrag</loc>', $map->body);
        self::assertStringContainsString('<lastmod>2026-03-05</lastmod>', $map->body);
        self::assertStringNotContainsString('geheimer', $map->body);
        self::assertNotFalse(simplexml_load_string($map->body));

        $feed = $this->call('GET', '/b/alpha/feed.xml');
        self::assertStringContainsString('rss+xml', $feed->headers['Content-Type']);
        self::assertStringContainsString('<title>Erster Beitrag</title>', $feed->body);
        self::assertStringContainsString('<![CDATA[<p>Text von Erster Beitrag</p>]]>', $feed->body);
        self::assertStringNotContainsString('Geheimer', $feed->body);
        self::assertNotFalse(simplexml_load_string($feed->body));

        $llms = $this->call('GET', '/b/alpha/llms.txt');
        self::assertStringContainsString("# Alpha Blog\n", $llms->body);
        self::assertStringContainsString('- [Erster Beitrag](https://omniwrite.example/b/alpha/erster-beitrag): Auszug Erster Beitrag', $llms->body);
        self::assertStringNotContainsString('Geheimer', $llms->body);
    }

    public function testPaginationAndEmptyBlog(): void
    {
        self::assertStringContainsString('Noch keine Beiträge', $this->call('GET', '/b/alpha')->body);
        for ($i = 1; $i <= 12; $i++) {
            $this->post('Beitrag Nr ' . $i);
        }
        $first = $this->call('GET', '/b/alpha');
        self::assertStringContainsString('?page=2', $first->body);
        $second = $this->call('GET', '/b/alpha?page=2');
        self::assertSame(200, $second->status);
        self::assertSame(2, preg_match_all('/<article class="card">/', $second->body));
        self::assertSame(200, $this->call('GET', '/b/alpha?page=99')->status);
    }

    public function testThemeSettingsAreValidatedAndApplied(): void
    {
        $this->post('Erster Beitrag');
        $bad = $this->call('POST', '/sites/alpha/blog', ['theme_accent' => 'red;}</style>', 'home_url' => 'javascript:alert(1)']);
        self::assertSame(422, $bad->status);
        self::assertStringContainsString('Farbe', $bad->body);
        self::assertSame(303, $this->call('POST', '/sites/alpha/blog', [
            'blog_tagline' => 'Wissen & mehr', 'theme_accent' => '#aa3300', 'theme_font' => 'serif',
            'blog_footer' => 'Fußzeile', 'home_url' => 'https://www.beispiel.de',
        ])->status);
        $res = $this->call('GET', '/b/alpha');
        self::assertStringContainsString('--accent:#aa3300', $res->body);
        self::assertStringContainsString('Georgia', $res->body);
        self::assertStringContainsString('Wissen &amp; mehr', $res->body);
        self::assertStringContainsString('href="https://www.beispiel.de"', $res->body);
    }

    public function testOnlyOwnersChangeTheBlogSettings(): void
    {
        $editor = $this->createUser('editor@example.com');
        $this->accounts->addMember($this->accountId, $editor, \OmniWrite\Domain\Role::Editor);
        $this->login('editor@example.com');
        self::assertSame(200, $this->call('GET', '/sites/alpha/blog')->status);
        self::assertSame(403, $this->call('POST', '/sites/alpha/blog', ['blog_tagline' => 'x'])->status);
        self::assertSame(403, $this->call('POST', '/sites/alpha/blog/domain', ['domain' => 'blog.beispiel.de'])->status);
    }

    public function testCustomDomainIsServedOnlyAfterVerification(): void
    {
        $this->post('Erster Beitrag');
        self::assertSame(303, $this->call('POST', '/sites/alpha/blog/domain', ['domain' => 'https://Blog.Beispiel.de/'])->status);
        self::assertSame('blog.beispiel.de', (new DomainRepository($this->pdo))->forSite($this->siteId)['host']);

        // Unverifiziert: Der Host gehört (noch) nicht zur Site, es gilt das Portal.
        $this->sessionData = [];
        self::assertSame(303, $this->call('GET', '/', [], true, 'blog.beispiel.de')->status);

        $this->login('owner@example.com');
        $fail = $this->call('POST', '/sites/alpha/blog/domain/verify');
        self::assertStringContainsString('notice=domain_dns', (string) $fail->headers['Location']);

        $this->dns->cnames['blog.beispiel.de'] = ['omniwrite.example'];
        $ok = $this->call('POST', '/sites/alpha/blog/domain/verify');
        self::assertStringContainsString('notice=domain_verified', (string) $ok->headers['Location']);

        $this->sessionData = [];
        $home = $this->call('GET', '/', [], true, 'blog.beispiel.de');
        self::assertSame(200, $home->status);
        self::assertStringContainsString('Erster Beitrag', $home->body);
        self::assertStringContainsString('<link rel="canonical" href="https://blog.beispiel.de/">', $home->body);
        self::assertStringContainsString('href="/erster-beitrag"', $home->body);
        self::assertSame(200, $this->call('GET', '/erster-beitrag', [], true, 'blog.beispiel.de')->status);
        self::assertStringContainsString('https://blog.beispiel.de/erster-beitrag', $this->call('GET', '/sitemap.xml', [], true, 'blog.beispiel.de')->body);
        self::assertStringContainsString('Sitemap: https://blog.beispiel.de/sitemap.xml', $this->call('GET', '/robots.txt', [], true, 'blog.beispiel.de')->body);

        // Das Backend gibt es auf der fremden Domain nicht.
        self::assertSame(404, $this->call('GET', '/login', [], true, 'blog.beispiel.de')->status);
        self::assertSame(404, $this->call('GET', '/dashboard', [], true, 'blog.beispiel.de')->status);
        self::assertSame(404, $this->call('POST', '/login', ['email' => 'a'], true, 'blog.beispiel.de')->status);
        // Das Portal verweist ab jetzt auf die eigene Domain.
        self::assertStringContainsString('<link rel="canonical" href="https://blog.beispiel.de/erster-beitrag">', $this->call('GET', '/b/alpha/erster-beitrag')->body);
        self::assertSame(200, $this->call('GET', '/b/alpha')->status);
    }

    public function testDomainValidationAndOwnership(): void
    {
        foreach (['', 'localhost', '192.168.0.1', 'a_b.example.com', 'omniwrite.example', 'blog.omniwrite.example', '-x.example.com', 'https://'] as $bad) {
            $res = $this->call('POST', '/sites/alpha/blog/domain', ['domain' => $bad]);
            self::assertSame(422, $res->status, $bad);
            self::assertStringContainsString('keine gültige', $res->body);
        }
        // Zweite Site mit eigenem Owner
        $other = $this->createUser('other@example.com');
        $otherAccount = $this->accounts->membershipsOf($other)[0]['id'];
        $otherSite = (new SiteRepository($this->pdo))->create($otherAccount, 'beta', 'Beta', 'en', 'UTC');

        $this->call('POST', '/sites/alpha/blog/domain', ['domain' => 'blog.beispiel.de']);
        $this->dns->cnames['blog.beispiel.de'] = ['omniwrite.example'];
        $this->call('POST', '/sites/alpha/blog/domain/verify');

        $this->login('other@example.com');
        $taken = $this->call('POST', '/sites/beta/blog/domain', ['domain' => 'blog.beispiel.de']);
        self::assertSame(422, $taken->status);
        self::assertStringContainsString('bereits zu einer anderen', $taken->body);
        self::assertNull((new DomainRepository($this->pdo))->forSite($otherSite));
        // Fremde Site: kein Zugriff
        self::assertSame(404, $this->call('POST', '/sites/alpha/blog/domain/remove')->status);
    }

    public function testUnverifiedClaimDoesNotBlockTheRealOwner(): void
    {
        $other = $this->createUser('other@example.com');
        $otherAccount = $this->accounts->membershipsOf($other)[0]['id'];
        $otherSite = (new SiteRepository($this->pdo))->create($otherAccount, 'beta', 'Beta', 'en', 'UTC');
        $this->call('POST', '/sites/alpha/blog/domain', ['domain' => 'blog.beispiel.de']); // Anspruch ohne DNS
        $this->login('other@example.com');
        $this->dns->cnames['blog.beispiel.de'] = ['omniwrite.example'];
        self::assertSame(303, $this->call('POST', '/sites/beta/blog/domain', ['domain' => 'blog.beispiel.de'])->status);
        self::assertStringContainsString('domain_verified', (string) $this->call('POST', '/sites/beta/blog/domain/verify')->headers['Location']);
        self::assertSame($otherSite, (int) (new DomainRepository($this->pdo))->findByHost('blog.beispiel.de')['site_id']);
        self::assertNull((new DomainRepository($this->pdo))->forSite($this->siteId));
    }

    public function testAddressMatchVerifiesApexDomains(): void
    {
        $this->call('POST', '/sites/alpha/blog/domain', ['domain' => 'beispiel.de']);
        $this->dns->addresses['omniwrite.example'] = ['203.0.113.7'];
        $this->dns->addresses['beispiel.de'] = ['198.51.100.9'];
        self::assertStringContainsString('domain_dns', (string) $this->call('POST', '/sites/alpha/blog/domain/verify')->headers['Location']);
        $this->dns->addresses['beispiel.de'] = ['203.0.113.7'];
        self::assertStringContainsString('domain_verified', (string) $this->call('POST', '/sites/alpha/blog/domain/verify')->headers['Location']);
    }

    public function testRemovingTheDomainStopsServingIt(): void
    {
        $this->post('Erster Beitrag');
        $this->call('POST', '/sites/alpha/blog/domain', ['domain' => 'blog.beispiel.de']);
        $this->dns->cnames['blog.beispiel.de'] = ['omniwrite.example'];
        $this->call('POST', '/sites/alpha/blog/domain/verify');
        $this->call('POST', '/sites/alpha/blog/domain/remove');
        self::assertNull((new DomainRepository($this->pdo))->forSite($this->siteId));
        $this->sessionData = [];
        self::assertSame(303, $this->call('GET', '/', [], true, 'blog.beispiel.de')->status); // wieder das Portal (Weiterleitung)
        self::assertStringContainsString('<link rel="canonical" href="https://omniwrite.example/b/alpha/erster-beitrag">', $this->call('GET', '/b/alpha/erster-beitrag')->body);
    }

    public function testPortalRobotsKeepsTheBackendOut(): void
    {
        $res = $this->call('GET', '/robots.txt');
        self::assertStringContainsString("Disallow: /\nAllow: /b/", $res->body);
    }
}
