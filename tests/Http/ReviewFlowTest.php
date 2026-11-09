<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Http;

use OmniWrite\Domain\Role;
use OmniWrite\Repository\PostRepository;
use OmniWrite\Repository\SiteRepository;
use OmniWrite\Domain\SiteContext;
use OmniWrite\Tests\Support\KernelTestCase;

final class ReviewFlowTest extends KernelTestCase
{
    private int $accountId;
    private int $siteId;
    private int $postId;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = $this->createUser('owner@example.com');
        $this->accountId = $this->accounts->membershipsOf($owner)[0]['id'];
        $this->siteId = (new SiteRepository($this->pdo))->create($this->accountId, 'alpha', 'Alpha Blog', 'de', 'Europe/Berlin');
        $this->login('owner@example.com');
        $res = $this->call('POST', '/sites/alpha/posts', ['title' => 'Mein Entwurf', 'body_html' => '<p>Hallo Welt</p>']);
        $this->postId = (int) basename((string) strtok((string) $res->headers['Location'], '?'));
    }

    private function login(string $email): void
    {
        $this->sessionData = [];
        $this->call('POST', '/login', ['email' => $email, 'password' => 'some long password']);
    }

    private function postStatus(): string
    {
        return (string) (new PostRepository($this->pdo, new SiteContext($this->siteId)))->find($this->postId)['status'];
    }

    private function addRecipient(string $email = 'rev@example.com', string $name = 'Rita Review'): void
    {
        self::assertSame(303, $this->call('POST', '/sites/alpha/recipients', ['email' => $email, 'name' => $name])->status);
    }

    public function testSendNeedsRecipients(): void
    {
        $res = $this->call('POST', "/sites/alpha/posts/{$this->postId}/send-review");
        self::assertStringContainsString('err.no_recipients', (string) $res->headers['Location']);
        self::assertSame([], $this->mailer->sent);
        self::assertSame('draft', $this->postStatus());
    }

    public function testSendMailsEveryActiveRecipientWithOwnLinks(): void
    {
        $this->addRecipient();
        $this->addRecipient('other@example.com', '');
        $res = $this->call('POST', "/sites/alpha/posts/{$this->postId}/send-review");
        self::assertSame("/sites/alpha/posts/{$this->postId}?notice=review_sent", $res->headers['Location']);
        self::assertCount(2, $this->mailer->sent);
        self::assertSame('in_review', $this->postStatus());
        self::assertStringContainsString('Mein Entwurf', $this->mailer->sent[0]['subject']);
        $a = $this->mailer->sent[0]['text'];
        $b = $this->mailer->sent[1]['text'];
        preg_match('#/review/([a-f0-9]{64})/publish#', $a, $ma);
        preg_match('#/review/([a-f0-9]{64})/publish#', $b, $mb);
        self::assertNotSame($ma[1], $mb[1]);
        self::assertStringContainsString('https://omniwrite.example/review/', $a);
        // Token liegt nur gehasht in der DB
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM review_tokens WHERE token_hash = '" . $ma[1] . "'")->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM usage_events WHERE kind = \'mail\'')->fetchColumn());
    }

    public function testPausedRecipientGetsNothingAndFailedSendRestoresStatus(): void
    {
        $this->addRecipient();
        $id = (int) $this->pdo->query('SELECT id FROM recipients')->fetchColumn();
        $this->call('POST', "/sites/alpha/recipients/$id/toggle");
        self::assertStringContainsString('err.no_recipients', (string) $this->call('POST', "/sites/alpha/posts/{$this->postId}/send-review")->headers['Location']);
        $this->call('POST', "/sites/alpha/recipients/$id/toggle");
        $this->mailer->fail = true;
        $res = $this->call('POST', "/sites/alpha/posts/{$this->postId}/send-review");
        self::assertStringContainsString('err.mail_failed', (string) $res->headers['Location']);
        self::assertSame('draft', $this->postStatus());
    }

    public function testMailNotConfigured(): void
    {
        $this->addRecipient();
        $this->mailer->configured = false;
        self::assertStringContainsString('err.mail_not_configured', (string) $this->call('POST', "/sites/alpha/posts/{$this->postId}/send-review")->headers['Location']);
    }

    public function testGetNeverChangesAnythingAndPostPublishes(): void
    {
        $this->addRecipient();
        $this->call('POST', "/sites/alpha/posts/{$this->postId}/send-review");
        $token = $this->mailer->lastToken();
        $this->sessionData = []; // Reviewer ohne Konto

        $page = $this->call('GET', "/review/$token/publish");
        self::assertSame(200, $page->status);
        self::assertSame('no-referrer', $page->headers['Referrer-Policy'] ?? null);
        self::assertStringContainsString('Mein Entwurf', $page->body);
        self::assertSame('in_review', $this->postStatus());

        self::assertSame(419, $this->call('POST', "/review/$token/publish", [], false)->status);
        self::assertSame('in_review', $this->postStatus());

        $done = $this->call('POST', "/review/$token/publish");
        self::assertSame(200, $done->status);
        self::assertSame('published', $this->postStatus());
        $row = (new PostRepository($this->pdo, new SiteContext($this->siteId)))->find($this->postId);
        self::assertSame('rev@example.com', $row['reviewed_by']);
        self::assertNotEmpty($row['published_at']);

        // zweiter Klick ändert nichts mehr
        $again = $this->call('POST', "/review/$token/decline", ['note' => 'zu spät']);
        self::assertSame('published', $this->postStatus());
        self::assertStringContainsString('bereits', $again->body);
    }

    public function testDeclineStoresNote(): void
    {
        $this->addRecipient();
        $this->call('POST', "/sites/alpha/posts/{$this->postId}/send-review");
        $token = $this->mailer->lastToken('decline');
        $this->sessionData = [];
        $this->call('GET', '/login');
        $this->call('POST', "/review/$token/decline", ['note' => 'Ton passt nicht <b>x</b>']);
        self::assertSame('declined', $this->postStatus());
        $row = (new PostRepository($this->pdo, new SiteContext($this->siteId)))->find($this->postId);
        self::assertSame('Ton passt nicht <b>x</b>', $row['review_note']);
        // Ausgabe wird escaped
        self::assertStringNotContainsString('<b>x</b>', $this->call('GET', "/review/$token/decline")->body);
    }

    public function testReviewerCanEditButNotAfterDecision(): void
    {
        $this->addRecipient();
        $this->call('POST', "/sites/alpha/posts/{$this->postId}/send-review");
        $token = $this->mailer->lastToken('edit');
        $this->sessionData = [];
        self::assertSame(200, $this->call('GET', "/review/$token/edit")->status);
        $bad = $this->call('POST', "/review/$token/edit", ['title' => '', 'body_html' => '']);
        self::assertSame(422, $bad->status);
        $ok = $this->call('POST', "/review/$token/edit", ['title' => 'Neu', 'body_html' => '<p>Neuer Text</p><script>x()</script>']);
        self::assertSame("/review/$token/edit?saved=1", $ok->headers['Location']);
        $row = (new PostRepository($this->pdo, new SiteContext($this->siteId)))->find($this->postId);
        self::assertSame('Neu', $row['title']);
        self::assertStringNotContainsString('<script>', (string) $row['body_html']);
        self::assertSame('in_review', $row['status']);

        $this->call('POST', "/review/$token/decline");
        $this->call('POST', "/review/$token/edit", ['title' => 'Nochmal', 'body_html' => '<p>x</p>']);
        self::assertSame('Neu', (new PostRepository($this->pdo, new SiteContext($this->siteId)))->find($this->postId)['title']);
    }

    public function testBadAndExpiredTokensLookTheSame(): void
    {
        foreach (['abc', str_repeat('a', 64), str_repeat('G', 64)] as $t) {
            self::assertSame(404, $this->call('GET', "/review/$t/publish")->status);
            self::assertSame(404, $this->call('POST', "/review/$t/publish")->status);
        }
        $this->addRecipient();
        $this->call('POST', "/sites/alpha/posts/{$this->postId}/send-review");
        $token = $this->mailer->lastToken();
        $this->pdo->exec("UPDATE review_tokens SET expires_at = '2000-01-01 00:00:00'");
        self::assertSame(404, $this->call('GET', "/review/$token/publish")->status);
        self::assertSame(404, $this->call('POST', "/review/$token/publish")->status);
        self::assertSame('in_review', $this->postStatus());
        self::assertSame(404, $this->call('GET', "/review/$token/hack")->status);
    }

    public function testRolesAndTenantIsolation(): void
    {
        $editor = $this->createUser('editor@example.com');
        $this->accounts->addMember($this->accountId, $editor, Role::Editor);
        $this->login('editor@example.com');
        self::assertSame(200, $this->call('GET', '/sites/alpha/recipients')->status);
        self::assertSame(403, $this->call('POST', '/sites/alpha/recipients', ['email' => 'x@example.com'])->status);
        self::assertSame(403, $this->call('POST', '/sites/alpha/schedule', ['enabled' => '1'])->status);
        self::assertSame(403, $this->call('POST', '/sites/alpha/schedule/run')->status);

        $this->createUser('stranger@example.com');
        $this->login('stranger@example.com');
        foreach (['/sites/alpha/recipients', '/sites/alpha/schedule'] as $p) {
            self::assertSame(404, $this->call('GET', $p)->status, $p);
        }
        self::assertSame(404, $this->call('POST', "/sites/alpha/posts/{$this->postId}/send-review")->status);
        self::assertSame([], $this->mailer->sent);
    }

    public function testRecipientValidation(): void
    {
        self::assertSame(422, $this->call('POST', '/sites/alpha/recipients', ['email' => 'kaputt'])->status);
        $this->addRecipient('Dup@Example.com');
        self::assertSame(422, $this->call('POST', '/sites/alpha/recipients', ['email' => 'dup@example.com'])->status);
        self::assertSame(200, $this->call('GET', '/sites/alpha/recipients')->status);
    }

    public function testScheduleSaveAndPage(): void
    {
        $this->call('POST', '/sites/alpha/schedule', ['enabled' => '1', 'weekday' => '3', 'hour' => '9']);
        $page = $this->call('GET', '/sites/alpha/schedule?saved=1');
        self::assertSame(200, $page->status);
        self::assertMatchesRegularExpression('/value="3" selected/', $page->body);
        self::assertMatchesRegularExpression('/value="9" selected/', $page->body);
    }
}
