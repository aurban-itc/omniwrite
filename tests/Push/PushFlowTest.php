<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Push;

use DateTimeImmutable;
use OmniWrite\App;
use OmniWrite\Core\Config;
use OmniWrite\Domain\Role;
use OmniWrite\Domain\SiteContext;
use OmniWrite\Push\PushService;
use OmniWrite\Repository\PostRepository;
use OmniWrite\Repository\SiteRepository;
use OmniWrite\Tests\Support\KernelTestCase;

final class PushFlowTest extends KernelTestCase
{
    private int $siteId;
    private PushService $push;
    /** @var array<string, mixed> */
    private array $site;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = $this->createUser('owner@example.com');
        $accountId = $this->accounts->membershipsOf($owner)[0]['id'];
        $this->siteId = (new SiteRepository($this->pdo))->create($accountId, 'alpha', 'Alpha Blog', 'de', 'Europe/Berlin');
        $this->site = (new SiteRepository($this->pdo))->find($this->siteId);
        $this->dns->addresses['hooks.example.org'] = ['93.184.216.34'];
        $this->call('POST', '/login', ['email' => 'owner@example.com', 'password' => 'some long password']);
        $this->push = App::pushService($this->pdo, new Config([
            'security' => ['master_key' => base64_encode(str_repeat('k', 32))], 'app' => ['base_url' => 'https://omniwrite.example'],
        ]), $this->http, $this->dns);
    }

    private function posts(): PostRepository
    {
        return new PostRepository($this->pdo, new SiteContext($this->siteId));
    }

    private function newPost(string $slug = 'hello', string $status = 'published'): int
    {
        return $this->posts()->insert([
            'title' => 'Hallo', 'slug' => $slug, 'body_html' => '<p>Text <a href="/x">x</a></p>', 'status' => $status, 'excerpt' => 'e', 'category' => 'c',
            'keywords' => 'k', 'meta_description' => 'm', 'cover_image' => '/media/' . $this->siteId . '/0123456789abcdef', 'cover_alt' => 'a', 'image_prompt' => '',
            'read_minutes' => 3, 'published_at' => $status === 'published' ? '2026-09-01 10:00:00' : null, 'updated_at' => '2026-09-01 10:00:00',
        ]);
    }

    private function addWebhook(): int
    {
        $res = $this->call('POST', '/sites/alpha/connectors', ['kind' => 'webhook', 'url' => 'https://hooks.example.org/in', 'secret' => 'a-long-enough-secret', 'name' => 'Hook']);
        self::assertSame(303, $res->status);
        return (int) $this->pdo->query('SELECT MAX(id) FROM push_targets')->fetchColumn();
    }

    private function now(string $at = '2026-11-12 10:00:00'): DateTimeImmutable
    {
        return new DateTimeImmutable($at, new \DateTimeZone('UTC'));
    }

    public function testWebhookValidationRejectsUnsafeTargets(): void
    {
        $this->dns->addresses['internal.example.org'] = ['10.0.0.5'];
        $this->dns->addresses['meta.example.org'] = ['169.254.169.254'];
        foreach ([
            ['http://hooks.example.org/in', 'err.push_url_invalid'], ['https://127.0.0.1/in', 'err.push_url_host'], ['https://localhost/in', 'err.push_url_host'],
            ['https://user:pw@hooks.example.org/in', 'err.push_url_invalid'], ['https://internal.example.org/in', 'err.push_url_private'],
            ['https://meta.example.org/in', 'err.push_url_private'], ['https://unknown.example.org/in', 'err.push_url_dns'], ['https://hooks.example.org:8443/in', 'err.push_url_invalid'],
        ] as [$url, $key]) {
            $res = $this->call('POST', '/sites/alpha/connectors', ['kind' => 'webhook', 'url' => $url, 'secret' => 'a-long-enough-secret']);
            self::assertSame(422, $res->status, $url);
            self::assertStringContainsString($this->t($key), $res->body, $url);
        }
        $res = $this->call('POST', '/sites/alpha/connectors', ['kind' => 'webhook', 'url' => 'https://hooks.example.org/in', 'secret' => 'short']);
        self::assertSame(422, $res->status);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM push_targets')->fetchColumn());
    }

    private function t(string $key): string
    {
        return htmlspecialchars((string) (require dirname(__DIR__, 2) . '/lang/de.php')[$key], ENT_QUOTES);
    }

    public function testSecretsAreEncryptedAndNeverShown(): void
    {
        $this->addWebhook();
        $this->call('POST', '/sites/alpha/connectors', ['kind' => 'github', 'repo' => 'acme/site', 'token' => 'ghp_' . str_repeat('a', 30), 'dir' => 'content/posts']);
        foreach ($this->pdo->query('SELECT config_enc FROM push_targets')->fetchAll(\PDO::FETCH_COLUMN) as $enc) {
            self::assertStringNotContainsString('a-long-enough-secret', (string) $enc);
            self::assertStringNotContainsString('ghp_', (string) $enc);
        }
        $page = $this->call('GET', '/sites/alpha/connectors')->body;
        self::assertStringContainsString('hooks.example.org', $page);
        self::assertStringContainsString('acme/site@main:content/posts/*.md', $page);
        self::assertStringNotContainsString('a-long-enough-secret', $page);
        self::assertStringNotContainsString('ghp_', $page);
    }

    public function testEditorsMayNotChangeConnectors(): void
    {
        $editor = $this->createUser('editor@example.com');
        $this->accounts->addMember($this->accounts->membershipsOf($this->createUser('owner2@example.com'))[0]['id'], $editor, Role::Editor);
        $this->sessionData = [];
        $this->call('POST', '/login', ['email' => 'editor@example.com', 'password' => 'some long password']);
        self::assertSame(404, $this->call('GET', '/sites/alpha/connectors')->status);
    }

    public function testWebhookDeliversNewChangedAndWithdrawnPostsOnce(): void
    {
        $id = $this->addWebhook();
        $pid = $this->newPost();
        $this->http->push(200, 'ok');
        $lines = $this->push->syncSite($this->site, $this->now());
        self::assertCount(1, $lines);
        self::assertCount(1, $this->http->calls);
        $call = $this->http->calls[0];
        self::assertSame('POST', $call['method']);
        self::assertSame('https://hooks.example.org/in', $call['url']);
        self::assertSame('93.184.216.34', $call['pin'], 'geprüfte Adresse wird festgehalten');
        $payload = json_decode((string) $call['body'], true);
        self::assertSame('post.published', $payload['event']);
        self::assertSame('hello', $payload['post']['slug']);
        self::assertSame('https://omniwrite.example/b/alpha/hello', $payload['post']['url']);
        self::assertStringContainsString('href="https://omniwrite.example/x"', $payload['post']['body_html'], 'Links sind absolut');
        self::assertSame('https://omniwrite.example/media/' . $this->siteId . '/0123456789abcdef', $payload['post']['cover_image']);
        self::assertSame('sha256=' . hash_hmac('sha256', (string) $call['body'], 'a-long-enough-secret'), $call['headers']['X-OmniWrite-Signature']);
        self::assertSame('post.published', $call['headers']['X-OmniWrite-Event']);

        self::assertSame([], $this->push->syncSite($this->site, $this->now('2026-11-12 11:00:00')), 'unverändert: nichts zu tun');
        self::assertCount(1, $this->http->calls);

        $this->posts()->update($pid, ['title' => 'Neu']);
        $this->http->push(204, '');
        $this->push->syncSite($this->site, $this->now('2026-11-12 12:00:00'));
        self::assertCount(2, $this->http->calls);
        self::assertSame('post.updated', json_decode((string) $this->http->calls[1]['body'], true)['event']);

        $this->posts()->update($pid, ['status' => 'draft']);
        $this->http->push(200, '');
        $this->push->syncSite($this->site, $this->now('2026-11-12 13:00:00'));
        self::assertCount(3, $this->http->calls);
        $gone = json_decode((string) $this->http->calls[2]['body'], true);
        self::assertSame('post.unpublished', $gone['event']);
        self::assertSame('hello', $gone['post']['slug']);
        self::assertSame([], $this->push->syncSite($this->site, $this->now('2026-11-12 14:00:00')));
        self::assertCount(3, $this->http->calls);
        self::assertSame($id, (int) $this->pdo->query('SELECT target_id FROM push_state')->fetchColumn());
    }

    public function testFailuresAreRetriedWithGrowingDelayAndPermanentErrorsStop(): void
    {
        $this->addWebhook();
        $this->newPost();
        $this->http->push(500, 'boom');
        $this->push->syncSite($this->site, $this->now('2026-11-12 10:00:00'));
        $row = $this->pdo->query('SELECT * FROM push_state')->fetch();
        self::assertSame('failed', $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertSame('2026-11-12 10:05:00', $row['next_attempt_at']);
        self::assertStringContainsString('HTTP 500', (string) $row['last_error']);

        self::assertSame([], $this->push->syncSite($this->site, $this->now('2026-11-12 10:04:00')), 'noch nicht fällig');
        self::assertCount(1, $this->http->calls);

        $this->http->push(0, '');
        $this->push->syncSite($this->site, $this->now('2026-11-12 10:05:00'));
        $row = $this->pdo->query('SELECT * FROM push_state')->fetch();
        self::assertSame(2, (int) $row['attempts']);
        self::assertSame('2026-11-12 10:15:00', $row['next_attempt_at']);

        $this->http->push(200, 'ok');
        $this->push->syncSite($this->site, $this->now('2026-11-12 10:15:00'));
        $row = $this->pdo->query('SELECT * FROM push_state')->fetch();
        self::assertSame('ok', $row['status']);
        self::assertSame(0, (int) $row['attempts']);
        self::assertNull($row['next_attempt_at']);

        // Dauerhafter Fehler (4xx): kein automatischer Wiederholversuch
        $this->newPost('second');
        $this->http->push(404, 'nope');
        $this->push->syncSite($this->site, $this->now('2026-11-12 11:00:00'));
        $n = count($this->http->calls);
        $this->push->syncSite($this->site, $this->now('2026-11-13 11:00:00'));
        self::assertCount($n, $this->http->calls);
        $failed = $this->pdo->query("SELECT next_attempt_at FROM push_state WHERE status = 'failed'")->fetchColumn();
        self::assertNull($failed);
    }

    public function testSendNowRetriesPermanentFailuresAndReportsLines(): void
    {
        $id = $this->addWebhook();
        $this->newPost();
        $this->http->push(401, 'denied');
        $this->push->syncSite($this->site, $this->now('2026-11-12 10:00:00'));
        $this->http->push(200, 'ok');
        $res = $this->call('POST', "/sites/alpha/connectors/$id/send");
        self::assertSame(200, $res->status);
        self::assertStringContainsString('zugestellt', $res->body);
        self::assertSame('ok', $this->pdo->query('SELECT status FROM push_state')->fetchColumn());
    }

    public function testGithubCreatesUpdatesRenamesAndDeletesFiles(): void
    {
        $this->call('POST', '/sites/alpha/connectors', ['kind' => 'github', 'repo' => 'acme/site', 'token' => 'ghp_' . str_repeat('b', 30), 'dir' => '/content/posts/', 'branch' => 'live']);
        $pid = $this->newPost();

        $this->http->push(404, '{}')->push(201, '{}');
        $this->push->syncSite($this->site, $this->now('2026-11-12 10:00:00'));
        [$get, $put] = $this->http->calls;
        self::assertSame('GET', $get['method']);
        self::assertSame('https://api.github.com/repos/acme/site/contents/content/posts/hello.md?ref=live', $get['url']);
        self::assertSame('Bearer ghp_' . str_repeat('b', 30), $get['headers']['Authorization']);
        self::assertSame('PUT', $put['method']);
        $body = json_decode((string) $put['body'], true);
        self::assertSame('live', $body['branch']);
        self::assertArrayNotHasKey('sha', $body);
        $file = base64_decode($body['content'], true);
        self::assertStringStartsWith("---\ntitle: \"Hallo\"\nslug: \"hello\"\n", (string) $file);
        self::assertStringContainsString('<p>Text <a href="https://omniwrite.example/x">x</a></p>', (string) $file);

        $this->http->calls = [];
        $this->posts()->update($pid, ['title' => 'Neu']);
        $this->http->push(200, '{"sha":"abc123"}')->push(200, '{}');
        $this->push->syncSite($this->site, $this->now('2026-11-12 11:00:00'));
        self::assertSame('abc123', json_decode((string) $this->http->calls[1]['body'], true)['sha'], 'bestehende Datei wird mit sha überschrieben');

        // Slug-Änderung: neue Datei schreiben, alte entfernen
        $this->http->calls = [];
        $this->posts()->update($pid, ['slug' => 'renamed']);
        $this->http->push(404, '{}')->push(201, '{}')->push(200, '{"sha":"old1"}')->push(200, '{}');
        $this->push->syncSite($this->site, $this->now('2026-11-12 12:00:00'));
        $methods = array_map(fn (array $c): string => $c['method'] . ' ' . basename(explode('?', $c['url'])[0]), $this->http->calls);
        self::assertSame(['GET renamed.md', 'PUT renamed.md', 'GET hello.md', 'DELETE hello.md'], $methods);

        // Zurückziehen löscht die Datei
        $this->http->calls = [];
        $this->posts()->update($pid, ['status' => 'draft']);
        $this->http->push(200, '{"sha":"s2"}')->push(200, '{}');
        $this->push->syncSite($this->site, $this->now('2026-11-12 13:00:00'));
        self::assertSame(['GET', 'DELETE'], array_map(fn (array $c): string => $c['method'], $this->http->calls));
        self::assertSame('s2', json_decode((string) $this->http->calls[1]['body'], true)['sha']);
    }

    public function testGithubValidationAndDeletedTargetsAreCleanedUp(): void
    {
        foreach ([['repo' => 'nope', 'token' => 'ghp_' . str_repeat('a', 30)], ['repo' => 'a/b', 'token' => 'x'], ['repo' => 'a/b', 'token' => 'ghp_' . str_repeat('a', 30), 'dir' => '../x'], ['repo' => 'a/b', 'token' => 'ghp_' . str_repeat('a', 30), 'branch' => 'a..b']] as $in) {
            self::assertSame(422, $this->call('POST', '/sites/alpha/connectors', ['kind' => 'github'] + $in)->status, json_encode($in));
        }
        $id = $this->addWebhook();
        $this->newPost();
        $this->http->push(200, 'ok');
        $this->push->syncSite($this->site, $this->now());
        $this->call('POST', "/sites/alpha/connectors/$id/toggle");
        $this->newPost('another');
        $n = count($this->http->calls);
        $this->push->syncSite($this->site, $this->now('2026-11-12 11:00:00'));
        self::assertCount($n, $this->http->calls, 'pausierte Ziele bekommen nichts');
        $this->call('POST', "/sites/alpha/connectors/$id/remove");
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM push_targets')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM push_state')->fetchColumn());
    }

    public function testOtherSitesDoNotSeeEachOthersTargets(): void
    {
        $this->addWebhook();
        $other = $this->createUser('other@example.com');
        $acc = $this->accounts->membershipsOf($other)[0]['id'];
        (new SiteRepository($this->pdo))->create($acc, 'beta', 'Beta', 'de', 'Europe/Berlin');
        $this->sessionData = [];
        $this->call('POST', '/login', ['email' => 'other@example.com', 'password' => 'some long password']);
        self::assertSame(404, $this->call('GET', '/sites/alpha/connectors')->status);
        self::assertStringNotContainsString('hooks.example.org', $this->call('GET', '/sites/beta/connectors')->body);
        $id = (int) $this->pdo->query('SELECT id FROM push_targets')->fetchColumn();
        $this->call('POST', "/sites/beta/connectors/$id/remove");
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM push_targets')->fetchColumn(), 'fremdes Ziel bleibt');
    }
}
