<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Http;

use OmniWrite\Domain\Role;
use OmniWrite\Repository\SiteRepository;
use OmniWrite\Tests\Support\FakeHttpClient;
use OmniWrite\Tests\Support\KernelTestCase;

final class AiFlowTest extends KernelTestCase
{
    private int $accountId;
    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = $this->createUser('owner@example.com');
        $this->accountId = $this->accounts->membershipsOf($owner)[0]['id'];
        $this->siteId = (new SiteRepository($this->pdo))->create($this->accountId, 'alpha', 'Alpha Blog', 'de', 'Europe/Berlin');
    }

    private function login(string $email): void
    {
        $this->call('POST', '/login', ['email' => $email, 'password' => 'some long password']);
    }

    public function testGenerateCreatesDraftAndRedirects(): void
    {
        $this->login('owner@example.com');
        self::assertSame(200, $this->call('GET', '/sites/alpha/generate')->status);
        $this->http->pushToolUse(FakeHttpClient::post('Mein KI Beitrag'));
        $res = $this->call('POST', '/sites/alpha/generate', ['title' => 'Thema', 'keywords' => 'a']);
        self::assertSame(303, $res->status);
        $loc = (string) $res->headers['Location'];
        self::assertMatchesRegularExpression('#^/sites/alpha/posts/\d+\?notice=generated$#', $loc);
        $edit = $this->call('GET', strtok($loc, '?'));
        self::assertStringContainsString('Mein KI Beitrag', $edit->body);
        self::assertStringNotContainsString('<script>alert', $edit->body);
    }

    public function testProviderFailureShowsTranslatedErrorWithoutLeakingDetails(): void
    {
        $this->login('owner@example.com');
        $this->http->push(500, 'secret provider detail');
        $res = $this->call('POST', '/sites/alpha/generate', ['title' => 'Thema']);
        self::assertSame(502, $res->status);
        self::assertStringNotContainsString('secret provider detail', $res->body);
    }

    public function testJsonEndpointsReviseSeoAndImage(): void
    {
        $this->login('owner@example.com');
        $this->http->pushToolUse(FakeHttpClient::post());
        $rev = $this->call('POST', '/sites/alpha/ai/revise', ['title' => 'T', 'body_html' => '<p>alt</p>', 'instruction' => 'kürzer']);
        self::assertSame(200, $rev->status);
        self::assertStringContainsString('Erster Absatz', json_decode($rev->body, true)['body_html']);

        $this->http->pushToolUse(['excerpt' => 'E', 'meta_description' => 'M', 'keywords' => 'k', 'category' => 'c', 'cover_alt' => 'a', 'image_prompt' => 'p']);
        $seo = $this->call('POST', '/sites/alpha/ai/seo', ['title' => 'T', 'body_html' => '<p>x</p>']);
        self::assertSame('M', json_decode($seo->body, true)['meta_description']);

        $this->http->pushImage(FakeHttpClient::png());
        $img = $this->call('POST', '/sites/alpha/ai/image', ['prompt' => 'Ein Büro']);
        self::assertSame(200, $img->status);
        $path = json_decode($img->body, true)['path'];
        self::assertMatchesRegularExpression('#^/media/' . $this->siteId . '/[a-f0-9]{16}$#', $path);

        $media = $this->call('GET', $path);
        self::assertSame(200, $media->status);
        self::assertSame('nosniff', $media->headers['X-Content-Type-Options'] ?? null);
    }

    public function testValidationAndCsrfOnJson(): void
    {
        $this->login('owner@example.com');
        self::assertSame(422, $this->call('POST', '/sites/alpha/ai/revise', ['title' => 'T', 'body_html' => '<p>a</p>', 'instruction' => ''])->status);
        self::assertSame(419, $this->call('POST', '/sites/alpha/ai/seo', ['body_html' => '<p>a</p>'], false)->status);
    }

    public function testSuggestTopics(): void
    {
        $this->login('owner@example.com');
        $this->http->pushToolUse(['topics' => [['title' => 'Idee 1', 'keywords' => 'k', 'category' => 'c', 'notes' => 'n']]]);
        $res = $this->call('POST', '/sites/alpha/topics/suggest');
        self::assertSame('/sites/alpha/topics?suggested=1', $res->headers['Location'] ?? null);
        self::assertStringContainsString('Idee 1', $this->call('GET', '/sites/alpha/topics')->body);
    }

    public function testEditorButtonsAreDisabledWithoutKeys(): void
    {
        $this->login('owner@example.com');
        $res = $this->call('POST', '/sites/alpha/posts', ['title' => 'X', 'body_html' => '<p>a</p>']);
        $path = strtok((string) $res->headers['Location'], '?');
        $with = $this->call('GET', $path)->body;
        self::assertDoesNotMatchRegularExpression('/id="ai-run"[^>]*disabled/', $with);

        $kernel = \OmniWrite\App::kernel($this->pdo, new \OmniWrite\Core\Config([
            'security' => ['master_key' => base64_encode(str_repeat('k', 32))], 'storage' => ['path' => $this->storageDir],
        ]), null, $this->http);
        $this->kernel = $kernel;
        $this->sessionData = [];
        $this->login('owner@example.com');
        $without = $this->call('GET', $path)->body;
        foreach (['ai-run', 'ai-seo', 'img-gen'] as $id) {
            self::assertMatchesRegularExpression('/id="' . $id . '"[^>]*disabled/', $without, $id);
        }
    }

    public function testMediaRejectsBadPathsAndUnknownFiles(): void
    {
        foreach (['/media/1/..%2Fx.webp', '/media/1/notahash.webp', '/media/1/0123456789abcdef.webp', '/media/abc/0123456789abcdef.png'] as $p) {
            self::assertSame(404, $this->call('GET', $p)->status, $p);
        }
    }

    public function testStrangerGetsNotFoundAndEditorMayGenerate(): void
    {
        $other = $this->createUser('other@example.com');
        $this->login('other@example.com');
        self::assertSame(404, $this->call('GET', '/sites/alpha/generate')->status);
        self::assertSame(404, $this->call('POST', '/sites/alpha/ai/seo', ['body_html' => '<p>a</p>'])->status);
        self::assertSame(0, count($this->http->requests));

        $this->logout();
        $editor = $this->createUser('editor@example.com');
        $this->accounts->addMember($this->accountId, $editor, Role::Editor);
        $this->login('editor@example.com');
        $this->http->pushToolUse(FakeHttpClient::post());
        self::assertSame(303, $this->call('POST', '/sites/alpha/generate', ['title' => 'Thema'])->status);
    }
}
