<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Service;

use OmniWrite\Ai\AiException;
use OmniWrite\Ai\ClaudeClient;
use OmniWrite\Ai\ContentAi;
use OmniWrite\Ai\OpenAiImageClient;
use OmniWrite\Domain\AccessDeniedException;
use OmniWrite\Domain\NotFoundException;
use OmniWrite\Domain\Role;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Service\AiService;
use OmniWrite\Service\PostService;
use OmniWrite\Service\ResolvedSite;
use OmniWrite\Service\SiteSettingsService;
use OmniWrite\Service\TopicService;
use OmniWrite\Service\UsageLimitException;
use OmniWrite\Service\UsageService;
use OmniWrite\Support\ImageStore;
use OmniWrite\Tests\Support\DbTestCase;
use OmniWrite\Tests\Support\FakeHttpClient;
use OmniWrite\Tests\Support\Fixtures;

final class AiServiceTest extends DbTestCase
{
    private Fixtures $fx;
    private FakeHttpClient $http;
    private AiService $ai;
    private PostService $posts;
    private TopicService $topics;
    private UsageService $usage;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fx = new Fixtures($this->pdo);
        $this->http = new FakeHttpClient();
        $this->dir = sys_get_temp_dir() . '/omniwrite-ai-' . bin2hex(random_bytes(4));
        $this->posts = new PostService($this->pdo, $this->fx->audit);
        $this->topics = new TopicService($this->pdo, $this->fx->audit);
        $this->usage = new UsageService($this->pdo, $this->fx->accounts);
        $this->ai = $this->service('k', 'k');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/images/*/*') ?: [] as $f) {
            unlink($f);
        }
        foreach (glob($this->dir . '/images/*') ?: [] as $d) {
            rmdir($d);
        }
        @rmdir($this->dir . '/images');
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function service(string $anthropicKey, string $openaiKey): AiService
    {
        return new AiService(
            $this->pdo, $this->fx->audit,
            new ContentAi(new ClaudeClient($this->http, $anthropicKey)),
            new OpenAiImageClient($this->http, $openaiKey),
            new ImageStore($this->dir), $this->posts, $this->topics, new SiteSettingsService($this->pdo, $this->fx->audit), $this->usage,
        );
    }

    /** @return array{0: \OmniWrite\Domain\User, 1: ResolvedSite} */
    private function owner(string $slug = 'alpha', string $email = 'o@example.com'): array
    {
        [$acc, $slug] = $this->fx->accountWithSite($slug);
        $user = $this->fx->user($email);
        $this->fx->member($acc, $user, Role::Owner);
        return [$user, $this->fx->resolve($user, $slug)];
    }

    private function usageCount(ResolvedSite $s, string $kind): int
    {
        return (int) $this->pdo->query("SELECT COALESCE(SUM(quantity),0) FROM usage_events WHERE site_id = {$s->id()} AND kind = '$kind'")->fetchColumn();
    }

    public function testGenerateDraftCreatesUnpublishedPostAndCountsUsage(): void
    {
        [$u, $s] = $this->owner();
        $topicId = $this->topics->add($s, $u, ['title' => 'Thema A', 'category' => 'Technik']);
        $this->http->pushToolUse(FakeHttpClient::post());
        $id = $this->ai->generateDraft($s, $u, ['topic_id' => $topicId]);
        $post = $this->posts->get($s, $id);
        self::assertSame('draft', $post['status']);
        self::assertSame('KI Titel', $post['title']);
        self::assertStringNotContainsString('script', (string) $post['body_html']);
        self::assertSame('Ein Büro bei Tageslicht', $post['image_prompt']);
        self::assertSame(1, $this->usageCount($s, 'ai_post'));
        self::assertSame(1, (int) $this->topics->get($s, $topicId)['times_used']);
        self::assertStringContainsString('Thema A', $this->http->lastPayload()['messages'][0]['content']);
    }

    public function testFailedCallIsNotChargedAndTopicNotConsumed(): void
    {
        [$u, $s] = $this->owner();
        $topicId = $this->topics->add($s, $u, ['title' => 'Thema A']);
        $this->http->push(500, 'boom');
        try {
            $this->ai->generateDraft($s, $u, ['topic_id' => $topicId]);
            self::fail('AiException erwartet');
        } catch (AiException) {
        }
        self::assertSame(0, $this->usageCount($s, 'ai_post'));
        self::assertSame(0, (int) $this->topics->get($s, $topicId)['times_used']);
        self::assertSame([], $this->posts->list($s)['items']);
    }

    public function testFreeSubjectNeedsTitle(): void
    {
        [$u, $s] = $this->owner();
        $this->expectException(ValidationException::class);
        $this->ai->generateDraft($s, $u, ['title' => ' ']);
    }

    public function testMonthlyPlanLimitBlocksFurtherPosts(): void
    {
        [$u, $s] = $this->owner();
        $this->pdo->exec("UPDATE plans SET posts_per_month = 2 WHERE code = 'default'");
        $this->http->pushToolUse(FakeHttpClient::post('Eins'))->pushToolUse(FakeHttpClient::post('Zwei'))->pushToolUse(FakeHttpClient::post('Drei'));
        $this->ai->generateDraft($s, $u, ['title' => 'a']);
        $this->ai->generateDraft($s, $u, ['title' => 'b']);
        try {
            $this->ai->generateDraft($s, $u, ['title' => 'c']);
            self::fail('Limit erwartet');
        } catch (UsageLimitException $e) {
            self::assertSame('err.limit_monthly', $e->getMessage());
        }
        self::assertSame(2, $this->usageCount($s, 'ai_post'));
        self::assertCount(2, $this->http->requests);
        self::assertSame(['posts_used' => 2, 'posts_limit' => 2], array_intersect_key($this->usage->summary($s), ['posts_used' => 1, 'posts_limit' => 1]));
    }

    public function testLimitIsPerSiteAndOtherPlansAreUnaffected(): void
    {
        [$u1, $s1] = $this->owner('alpha', 'o1@example.com');
        [$u2, $s2] = $this->owner('beta', 'o2@example.com');
        $this->pdo->exec("UPDATE plans SET posts_per_month = 1 WHERE code = 'default'");
        $this->http->pushToolUse(FakeHttpClient::post('Eins'))->pushToolUse(FakeHttpClient::post('Zwei'));
        $this->ai->generateDraft($s1, $u1, ['title' => 'a']);
        $this->ai->generateDraft($s2, $u2, ['title' => 'b']); // eigene Site, eigenes Kontingent
        self::assertSame(1, $this->usageCount($s1, 'ai_post'));
        self::assertSame(1, $this->usageCount($s2, 'ai_post'));
    }

    public function testUnlimitedPlanAndHourlyCap(): void
    {
        [$u, $s] = $this->owner();
        $now = 1_800_000_000;
        for ($i = 0; $i < UsageService::HOURLY_LIMITS['ai_image']; $i++) {
            $this->usage->reserve($s, 'ai_image', [], $now);
        }
        try {
            $this->usage->reserve($s, 'ai_image', [], $now + 10);
            self::fail('Stundenlimit erwartet');
        } catch (UsageLimitException $e) {
            self::assertSame('err.limit_hourly', $e->getMessage());
        }
        $this->usage->reserve($s, 'ai_image', [], $now + 3700); // eine Stunde später wieder frei
        self::assertSame(UsageService::HOURLY_LIMITS['ai_image'] + 1, $this->usageCount($s, 'ai_image'));
    }

    public function testMonthWindowFollowsSiteTimezone(): void
    {
        [$u, $s] = $this->owner();
        $this->pdo->exec("UPDATE plans SET posts_per_month = 1 WHERE code = 'default'");
        // 31.12. 23:30 UTC ist in Berlin schon der 1.1.: neuer Monat, also wieder ein Beitrag frei.
        $this->usage->reserve($s, 'ai_post', [], gmmktime(12, 0, 0, 12, 15, 2026));
        $this->usage->reserve($s, 'ai_post', [], gmmktime(23, 30, 0, 12, 31, 2026));
        self::assertSame(1, $this->usage->summary($s, gmmktime(23, 45, 0, 12, 31, 2026))['posts_used']);
    }

    public function testReviseSeoAndSuggestTopicsAreMeteredAndSideEffectFree(): void
    {
        [$u, $s] = $this->owner();
        $this->http->pushToolUse(FakeHttpClient::post());
        $html = $this->ai->revise($s, $u, 'T', '<p>alt</p>', 'kürzer');
        self::assertStringContainsString('Erster Absatz', $html);

        $this->http->pushToolUse(['excerpt' => 'E', 'meta_description' => 'M', 'keywords' => 'k', 'category' => 'c', 'cover_alt' => 'a', 'image_prompt' => 'p']);
        self::assertSame('M', $this->ai->seoFields($s, $u, 'T', '<p>x</p>')['meta_description']);

        $this->http->pushToolUse(['topics' => [['title' => 'Idee 1', 'keywords' => 'k', 'category' => 'c', 'notes' => 'n'], ['title' => '', 'keywords' => '', 'category' => '', 'notes' => '']]]);
        self::assertSame(1, $this->ai->suggestTopics($s, $u));
        self::assertSame('suggested', $this->topics->overview($s)['suggestions'][0]['status']);

        self::assertSame(3, $this->usageCount($s, 'ai_text'));
        self::assertSame([], $this->posts->list($s)['items']);
    }

    public function testImageIsStoredReencodedOutsideWebroot(): void
    {
        [$u, $s] = $this->owner();
        $this->http->pushImage(FakeHttpClient::png());
        $url = $this->ai->generateImage($s, $u, 'Ein Bild');
        self::assertMatchesRegularExpression('#^/media/' . $s->id() . '/[a-f0-9]{16}\.(webp|png)$#', $url);
        $file = basename($url);
        $path = (new ImageStore($this->dir))->pathFor($s->id(), $file);
        self::assertNotNull($path);
        self::assertStringStartsWith($this->dir, $path);
        self::assertSame(1, $this->usageCount($s, 'ai_image'));
        self::assertStringContainsString('Style:', $this->http->lastPayload()['prompt']);
    }

    public function testBrokenImageDataIsRejectedAndNotCharged(): void
    {
        [$u, $s] = $this->owner();
        $this->http->pushImage('<?php echo 1;');
        $this->expectException(AiException::class);
        try {
            $this->ai->generateImage($s, $u, 'Bild');
        } finally {
            self::assertSame(0, $this->usageCount($s, 'ai_image'));
        }
    }

    public function testImageStoreRejectsPathTricks(): void
    {
        $store = new ImageStore($this->dir);
        foreach (['../../etc/passwd', '..%2f', 'a.webp', 'ABCDEF0123456789.webp', "0123456789abcdef.webp\0.php", '0123456789abcdef.php'] as $name) {
            self::assertNull($store->pathFor(1, $name), $name);
        }
        self::assertNull($store->pathFor(0, '0123456789abcdef.webp'));
    }

    public function testNotConfiguredFailsWithoutChargingOrRequest(): void
    {
        [$u, $s] = $this->owner();
        $ai = $this->service('', '');
        foreach ([fn () => $ai->generateDraft($s, $u, ['title' => 'x']), fn () => $ai->generateImage($s, $u, 'x'), fn () => $ai->suggestTopics($s, $u)] as $call) {
            try {
                $call();
                self::fail('erwartet');
            } catch (AiException $e) {
                self::assertSame('err.ai_not_configured', $e->getMessage());
            }
        }
        self::assertSame([], $this->http->requests);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM usage_events')->fetchColumn());
    }

    public function testStrangersGetNotFoundAndTopicsOfOtherSitesAreUnusable(): void
    {
        [$u1, $s1] = $this->owner('alpha', 'o1@example.com');
        [$u2, $s2] = $this->owner('beta', 'o2@example.com');
        $foreignTopic = $this->topics->add($s1, $u1, ['title' => 'Nur Alpha']);
        $this->http->pushToolUse(FakeHttpClient::post());
        try {
            $this->ai->generateDraft($s2, $u2, ['topic_id' => $foreignTopic]);
            self::fail('NotFound erwartet');
        } catch (NotFoundException) {
        }
        self::assertSame([], $this->http->requests);
        self::assertSame(0, $this->usageCount($s2, 'ai_post'));
    }

    public function testAuditRecordsAiActions(): void
    {
        [$u, $s] = $this->owner();
        $this->http->pushToolUse(FakeHttpClient::post());
        $this->ai->generateDraft($s, $u, ['title' => 'x']);
        $actions = $this->pdo->query("SELECT action FROM audit_log WHERE action LIKE 'ai.%'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['ai.generate_post'], $actions);
    }
}
