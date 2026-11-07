<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Http;

use OmniWrite\Domain\Role;
use OmniWrite\Repository\SiteRepository;
use OmniWrite\Tests\Support\KernelTestCase;

final class SiteContentFlowTest extends KernelTestCase
{
    private int $accountId;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = $this->createUser('owner@example.com');
        $this->accountId = $this->accounts->membershipsOf($owner)[0]['id'];
        (new SiteRepository($this->pdo))->create($this->accountId, 'alpha', 'Alpha Blog', 'de', 'Europe/Berlin');
    }

    private function login(string $email, string $password = 'some long password'): void
    {
        $this->call('POST', '/login', ['email' => $email, 'password' => $password]);
    }

    public function testAnonymousIsSentToLogin(): void
    {
        self::assertSame('/login', $this->call('GET', '/sites/alpha/posts')->headers['Location'] ?? null);
        self::assertSame('/login', $this->call('POST', '/sites/alpha/posts', ['title' => 'x'])->headers['Location'] ?? null);
    }

    public function testOwnerCreatesEditsAndPublishesPostThroughTheUi(): void
    {
        $this->login('owner@example.com');
        self::assertSame(200, $this->call('GET', '/sites/alpha/posts')->status);
        self::assertSame(200, $this->call('GET', '/sites/alpha/posts/new')->status);

        $res = $this->call('POST', '/sites/alpha/posts', [
            'title' => 'Hallo <b>Welt</b>', 'body_html' => '<p>Text <script>alert(1)</script>ok</p>',
        ]);
        self::assertSame(303, $res->status);
        $location = (string) $res->headers['Location'];
        self::assertMatchesRegularExpression('#^/sites/alpha/posts/\d+\?notice=saved$#', $location);

        $edit = $this->call('GET', strtok($location, '?'));
        self::assertSame(200, $edit->status);
        self::assertStringNotContainsString('<script>alert', $edit->body);
        self::assertStringContainsString('Hallo &lt;b&gt;Welt&lt;/b&gt;', $edit->body);

        $list = $this->call('GET', '/sites/alpha/posts');
        self::assertStringContainsString('Hallo &lt;b&gt;Welt&lt;/b&gt;', $list->body);
        self::assertStringNotContainsString('Hallo <b>', $list->body);

        $path = strtok($location, '?');
        self::assertSame(303, $this->call('POST', "$path/publish")->status);
        self::assertStringContainsString('Veröffentlicht', $this->call('GET', '/sites/alpha/posts?status=published')->body);
    }

    public function testValidationErrorRerendersWith422(): void
    {
        $this->login('owner@example.com');
        $res = $this->call('POST', '/sites/alpha/posts', ['title' => '', 'body_html' => '']);
        self::assertSame(422, $res->status);
        self::assertStringContainsString('Bitte einen Titel angeben', $res->body);
    }

    public function testPostsRequireCsrfToken(): void
    {
        $this->login('owner@example.com');
        self::assertSame(419, $this->call('POST', '/sites/alpha/posts', ['title' => 'x', 'body_html' => '<p>a</p>'], false)->status);
    }

    public function testOtherTenantsGetNotFoundOnEverySiteRoute(): void
    {
        $other = $this->createUser('other@example.com');
        $otherAccount = $this->accounts->membershipsOf($other)[0]['id'];
        (new SiteRepository($this->pdo))->create($otherAccount, 'beta', 'Beta', 'en', 'UTC');
        $this->login('other@example.com');
        foreach (['/sites/alpha/posts', '/sites/alpha/posts/new', '/sites/alpha/topics', '/sites/alpha/settings', '/sites/nope/posts'] as $path) {
            self::assertSame(404, $this->call('GET', $path)->status, $path);
        }
        self::assertSame(404, $this->call('POST', '/sites/alpha/posts', ['title' => 't', 'body_html' => '<p>a</p>'])->status);
        self::assertSame(200, $this->call('GET', '/sites/beta/posts')->status);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn());
    }

    public function testEditorCanWriteButNotPublishOrChangeSettings(): void
    {
        $editor = $this->createUser('editor@example.com', 'some long password', false, Role::Editor);
        $this->accounts->addMember($this->accountId, $editor, Role::Editor);
        $this->login('editor@example.com');
        $res = $this->call('POST', '/sites/alpha/posts', ['title' => 'Entwurf', 'body_html' => '<p>a</p>']);
        $path = strtok((string) $res->headers['Location'], '?');
        self::assertSame(403, $this->call('POST', "$path/publish")->status);
        self::assertSame(303, $this->call('POST', "$path/submit")->status);
        self::assertSame(403, $this->call('POST', '/sites/alpha/settings', ['style_guide' => 'x'])->status);
        self::assertStringNotContainsString('/publish', $this->call('GET', $path)->body);
    }

    public function testTopicsAddMoveAndDelete(): void
    {
        $this->login('owner@example.com');
        $this->call('POST', '/sites/alpha/topics', ['title' => 'Erstes']);
        $this->call('POST', '/sites/alpha/topics', ['title' => 'Zweites']);
        $page = $this->call('GET', '/sites/alpha/topics')->body;
        self::assertLessThan(strpos($page, 'Zweites'), strpos($page, 'Erstes'));
        self::assertStringContainsString('als Nächstes', $page);

        $second = (int) $this->pdo->query("SELECT id FROM topics WHERE title = 'Zweites'")->fetchColumn();
        $this->call('POST', "/sites/alpha/topics/$second/up");
        $page = $this->call('GET', '/sites/alpha/topics')->body;
        self::assertLessThan(strpos($page, 'Erstes'), strpos($page, 'Zweites'));

        $this->call('POST', "/sites/alpha/topics/$second/delete");
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM topics')->fetchColumn());
        self::assertSame(422, $this->call('POST', '/sites/alpha/topics', ['title' => ''])->status);
    }

    public function testSettingsRoundTrip(): void
    {
        $this->login('owner@example.com');
        self::assertSame(303, $this->call('POST', '/sites/alpha/settings', ['style_guide' => 'Mein Ton', 'company_facts' => 'Wir sind Alpha', 'image_style' => ''])->status);
        $body = $this->call('GET', '/sites/alpha/settings?saved=1')->body;
        self::assertStringContainsString('Mein Ton', $body);
        self::assertStringContainsString('Wir sind Alpha', $body);
        self::assertStringContainsString('Einstellungen gespeichert', $body);
    }
}
