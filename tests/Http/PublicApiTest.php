<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Http;

use OmniWrite\Domain\SiteContext;
use OmniWrite\Repository\PostRepository;
use OmniWrite\Repository\SiteRepository;
use OmniWrite\Tests\Support\KernelTestCase;

final class PublicApiTest extends KernelTestCase
{
    private int $siteId;
    private PostRepository $posts;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = $this->createUser('owner@example.com');
        $account = $this->accounts->membershipsOf($owner)[0]['id'];
        $this->siteId = (new SiteRepository($this->pdo))->create($account, 'alpha', 'Alpha Blog', 'de', 'Europe/Berlin');
        $this->posts = new PostRepository($this->pdo, new SiteContext($this->siteId));
    }

    private function post(string $title, string $status = 'published', string $cover = ''): void
    {
        $this->posts->insert([
            'title' => $title, 'slug' => strtolower(str_replace(' ', '-', $title)), 'body_html' => '<p>Text ' . $title . '</p>',
            'status' => $status, 'excerpt' => 'Auszug ' . $title, 'meta_description' => 'Meta', 'read_minutes' => 2, 'category' => 'Tipps',
            'cover_image' => $cover, 'published_at' => $status === 'published' ? '2026-03-04 10:00:00' : null, 'updated_at' => '2026-03-05 11:00:00',
        ]);
    }

    /** @return array<string, mixed> */
    private function json(string $path, int $status = 200, string $host = ''): array
    {
        $res = $this->call('GET', $path, [], true, $host);
        self::assertSame($status, $res->status);
        self::assertStringContainsString('application/json', $res->headers['Content-Type']);
        self::assertSame('*', $res->headers['Access-Control-Allow-Origin']);
        self::assertSame('nosniff', $res->headers['X-Content-Type-Options']);
        return json_decode($res->body, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testListReturnsOnlyPublishedPostsWithAbsoluteUrls(): void
    {
        $this->post('Erster Beitrag', 'published', '/media/1/ab12cd34ef567890.webp');
        $this->post('Zweiter Beitrag');
        $this->post('Geheimer Entwurf', 'draft');
        $d = $this->json('/api/v1/sites/alpha/posts');
        self::assertSame(2, $d['total']);
        self::assertSame(['name' => 'Alpha Blog', 'locale' => 'de', 'url' => 'https://omniwrite.example/b/alpha/'], $d['site']);
        $titles = array_column($d['posts'], 'title');
        self::assertContains('Erster Beitrag', $titles);
        self::assertNotContains('Geheimer Entwurf', $titles);
        $first = $d['posts'][array_search('Erster Beitrag', $titles, true)];
        self::assertSame('https://omniwrite.example/b/alpha/erster-beitrag', $first['url']);
        self::assertSame('https://omniwrite.example/media/1/ab12cd34ef567890.webp', $first['cover_image']);
        self::assertSame('2026-03-04T10:00:00+00:00', $first['published_at']);
        self::assertArrayNotHasKey('body_html', $first);
    }

    public function testPaginationAndLimits(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->post('Beitrag Nr ' . $i);
        }
        $d = $this->json('/api/v1/sites/alpha/posts?per_page=3&page=3');
        self::assertCount(1, $d['posts']);
        self::assertSame([3, 3, 7], [$d['page'], $d['pages'], $d['total']]);
        self::assertCount(7, $this->json('/api/v1/sites/alpha/posts?per_page=500')['posts']); // gedeckelt auf 50, hier gibt es nur 7
        self::assertCount(7, $this->json('/api/v1/sites/alpha/posts?per_page=abc&page=-4')['posts']);
    }

    public function testSinglePostIncludesBodyButNeverDrafts(): void
    {
        $this->post('Erster Beitrag');
        $this->post('Geheimer Entwurf', 'draft');
        $d = $this->json('/api/v1/sites/alpha/posts/erster-beitrag');
        self::assertSame('<p>Text Erster Beitrag</p>', $d['post']['body_html']);
        self::assertSame('Meta', $d['post']['meta_description']);
        self::assertSame('not_found', $this->json('/api/v1/sites/alpha/posts/geheimer-entwurf', 404)['error']);
        self::assertSame('not_found', $this->json('/api/v1/sites/alpha/posts/gibt-es-nicht', 404)['error']);
        self::assertSame('not_found', $this->json('/api/v1/sites/nope/posts', 404)['error']);
    }

    public function testWritingIsNotPossible(): void
    {
        self::assertSame(404, $this->call('POST', '/api/v1/sites/alpha/posts', ['title' => 'x'])->status);
    }

    public function testCustomDomainServesTheApiAtTheRoot(): void
    {
        $this->post('Erster Beitrag');
        $this->pdo->exec("INSERT INTO domains (site_id, host, verified_at) VALUES ({$this->siteId}, 'blog.beispiel.de', '2026-01-01 00:00:00')");
        $d = $this->json('/api/v1/posts', 200, 'blog.beispiel.de');
        self::assertSame('https://blog.beispiel.de/erster-beitrag', $d['posts'][0]['url']);
        self::assertSame('https://blog.beispiel.de/', $d['site']['url']);
        self::assertSame('Erster Beitrag', $this->json('/api/v1/posts/erster-beitrag', 200, 'blog.beispiel.de')['post']['title']);
        // Die Portal-Variante gibt es auf der fremden Domain nicht.
        self::assertSame(404, $this->call('GET', '/api/v1/sites/alpha/posts', [], true, 'blog.beispiel.de')->status);
    }

    public function testEmbedScriptExistsAndAvoidsInnerHtml(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/embed.js');
        self::assertStringContainsString('/api/v1/sites/', $js);
        self::assertStringNotContainsString('innerHTML', preg_replace('#/\*.*?\*/#s', '', $js));
    }

    public function testBackendShowsApiAddressAndEmbedCode(): void
    {
        $this->login();
        $page = $this->call('GET', '/sites/alpha/blog')->body;
        self::assertStringContainsString('https://omniwrite.example/api/v1/sites/alpha/posts', $page);
        self::assertStringContainsString('&lt;script src=&quot;https://omniwrite.example/assets/embed.js&quot; data-site=&quot;alpha&quot;', $page);
    }

    private function login(): void
    {
        $this->sessionData = [];
        $this->call('POST', '/login', ['email' => 'owner@example.com', 'password' => 'some long password']);
    }

    public function testBodyLinksAndImagesInTheApiAreAbsolute(): void
    {
        $this->posts->insert([
            'title' => 'Mit Links', 'slug' => 'mit-links', 'status' => 'published', 'published_at' => '2026-03-04 10:00:00', 'read_minutes' => 1,
            'body_html' => '<p><a href="/kontakt">Kontakt</a> <a href="#fussnote">Anker</a> <a href="https://x.example/a">Extern</a> <a href="mailto:a@b.de">Mail</a> <a href="seite-2">Relativ</a><img src="/media/1/ab12cd34ef567890.webp" alt="x"></p>',
        ]);
        $body = $this->json('/api/v1/sites/alpha/posts/mit-links')['post']['body_html'];
        self::assertStringContainsString('href="https://omniwrite.example/kontakt"', $body);
        self::assertStringContainsString('href="https://omniwrite.example/b/alpha/mit-links#fussnote"', $body);
        self::assertStringContainsString('href="https://x.example/a"', $body);
        self::assertStringContainsString('href="mailto:a@b.de"', $body);
        self::assertStringContainsString('href="https://omniwrite.example/b/alpha/seite-2"', $body);
        self::assertStringContainsString('src="https://omniwrite.example/media/1/ab12cd34ef567890.webp"', $body);
    }

    public function testSuperadminWithoutTwoFactorStillGetsPublicContent(): void
    {
        $this->post('Erster Beitrag');
        $this->createUser('root@example.com', 'some long password', true);
        $this->sessionData = [];
        $this->call('POST', '/login', ['email' => 'root@example.com', 'password' => 'some long password']);
        self::assertSame('/account/2fa', $this->call('GET', '/admin')->headers['Location'] ?? null); // Gate greift weiter
        self::assertSame(200, $this->call('GET', '/api/v1/sites/alpha/posts')->status);
        self::assertSame(200, $this->call('GET', '/b/alpha')->status);
        self::assertSame(200, $this->call('GET', '/b/alpha/erster-beitrag')->status);
    }
}
