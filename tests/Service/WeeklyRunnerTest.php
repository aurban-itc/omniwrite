<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Service;

use DateTimeImmutable;
use DateTimeZone;
use OmniWrite\App;
use OmniWrite\Core\Config;
use OmniWrite\Domain\Role;
use OmniWrite\Domain\SiteContext;
use OmniWrite\Repository\PostRepository;
use OmniWrite\Repository\RecipientRepository;
use OmniWrite\Repository\SiteSettingsRepository;
use OmniWrite\Service\TopicService;
use OmniWrite\Tests\Support\DbTestCase;
use OmniWrite\Tests\Support\FakeHttpClient;
use OmniWrite\Tests\Support\FakeMailer;
use OmniWrite\Tests\Support\Fixtures;

final class WeeklyRunnerTest extends DbTestCase
{
    private FakeHttpClient $http;
    private FakeMailer $mailer;
    private Fixtures $fx;
    private int $accountId;
    private int $siteId;
    private SiteContext $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fx = new Fixtures($this->pdo);
        $this->http = new FakeHttpClient();
        $this->mailer = new FakeMailer();
        $owner = $this->fx->user('owner@example.com');
        [$this->accountId, $slug] = $this->fx->accountWithSite('alpha');
        $this->fx->member($this->accountId, $owner, Role::Owner);
        $site = $this->fx->sites->findBySlug($slug);
        $this->siteId = (int) $site['id'];
        $this->ctx = new SiteContext($this->siteId);
        $rs = $this->fx->resolve($owner, $slug);
        $topics = new TopicService($this->pdo, $this->fx->audit);
        $topics->add($rs, $owner, ['title' => 'Thema Eins']);
        $topics->add($rs, $owner, ['title' => 'Thema Zwei']);
        (new RecipientRepository($this->pdo, $this->ctx))->insert(['email' => 'rev@example.com', 'name' => 'Rev', 'active' => 1]);
        $settings = new SiteSettingsRepository($this->pdo, $this->ctx);
        $settings->set('schedule_enabled', '1');
        $settings->set('schedule_weekday', '1');  // Montag
        $settings->set('schedule_hour', '6');
    }

    private function runner(string $anthropicKey = 'k'): \OmniWrite\Service\WeeklyRunner
    {
        return App::weeklyRunner($this->pdo, new Config([
            'ai' => ['anthropic_api_key' => $anthropicKey], 'storage' => ['path' => sys_get_temp_dir() . '/omniwrite-wr'],
            'app' => ['base_url' => 'https://omniwrite.example'],
        ]), null, $this->http, $this->mailer);
    }

    /** Montag 2026-11-09 06:30 in Berlin = 05:30 UTC. */
    private function mondayMorning(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-09 05:30:00', new DateTimeZone('UTC'));
    }

    public function testDueSiteGetsDraftAndMailOnceAWeek(): void
    {
        $this->http->pushToolUse(FakeHttpClient::post('Wochenbeitrag'));
        $first = $this->runner()->runDue($this->mondayMorning());
        self::assertCount(1, $first);
        self::assertSame('ok', $first[0]['status']);
        self::assertCount(1, $this->mailer->sent);
        $posts = (new PostRepository($this->pdo, $this->ctx))->all();
        self::assertSame('in_review', $posts[0]['status']);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM runs WHERE status = 'ok'")->fetchColumn());

        // Rotation rückt weiter: nächste Woche Thema Zwei
        self::assertSame([], $this->runner()->runDue($this->mondayMorning()->modify('+10 minutes')), 'gleiche Woche läuft nicht noch einmal');
        $this->http->pushToolUse(FakeHttpClient::post('Beitrag zwei'));
        $next = $this->runner()->runDue($this->mondayMorning()->modify('+7 days'));
        self::assertSame('ok', $next[0]['status']);
        self::assertStringContainsString('Thema Zwei', $next[0]['message']);
    }

    public function testNotDueOutsideTheConfiguredLocalHour(): void
    {
        // 06:30 UTC ist 07:30 in Berlin, nicht 06:xx
        self::assertSame([], $this->runner()->runDue(new DateTimeImmutable('2026-11-09 06:30:00', new DateTimeZone('UTC'))));
        // Dienstag
        self::assertSame([], $this->runner()->runDue(new DateTimeImmutable('2026-11-10 05:30:00', new DateTimeZone('UTC'))));
        self::assertSame([], $this->mailer->sent);
    }

    public function testDisabledScheduleNeverRuns(): void
    {
        (new SiteSettingsRepository($this->pdo, $this->ctx))->set('schedule_enabled', '0');
        self::assertSame([], $this->runner()->runDue($this->mondayMorning()));
    }

    public function testSummerTimeUsesSiteTimezone(): void
    {
        // Juli: 06:30 Berlin = 04:30 UTC
        $this->http->pushToolUse(FakeHttpClient::post());
        $res = $this->runner()->runDue(new DateTimeImmutable('2026-07-06 04:30:00', new DateTimeZone('UTC')));
        self::assertCount(1, $res);
    }

    public function testFailureIsLoggedAndOwnersAreAlerted(): void
    {
        $res = $this->runner('')->runDue($this->mondayMorning()); // kein KI-Schlüssel
        self::assertSame('failed', $res[0]['status']);
        self::assertCount(1, $this->mailer->sent);
        self::assertSame('owner@example.com', $this->mailer->sent[0]['to']);
        self::assertStringContainsString('Weekly', $this->mailer->sent[0]['subject'] . 'Weekly'); // Betreff vorhanden
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM runs WHERE status = 'failed'")->fetchColumn());
    }

    public function testNoRecipientsKeepsDraftButReportsFailure(): void
    {
        $this->pdo->exec('DELETE FROM recipients');
        $this->http->pushToolUse(FakeHttpClient::post());
        $res = $this->runner()->runDue($this->mondayMorning());
        self::assertSame('failed', $res[0]['status']);
        $posts = (new PostRepository($this->pdo, $this->ctx))->all();
        self::assertSame('draft', $posts[0]['status']);
    }

    public function testClaimIsAtomic(): void
    {
        $settings = new SiteSettingsRepository($this->pdo, $this->ctx);
        self::assertTrue($settings->claim('last_run_week', '2026-46'));
        self::assertFalse($settings->claim('last_run_week', '2026-46'));
        self::assertTrue($settings->claim('last_run_week', '2026-47'));
    }

    public function testOtherSitesAreIndependent(): void
    {
        [$acc2, $slug2] = $this->fx->accountWithSite('beta');
        $this->fx->member($acc2, $this->fx->user('o2@example.com'), Role::Owner);
        // beta hat keinen Zeitplan: nur alpha läuft
        $this->http->pushToolUse(FakeHttpClient::post());
        $res = $this->runner()->runDue($this->mondayMorning());
        self::assertCount(1, $res);
        self::assertSame('alpha', $res[0]['site']);
    }
}
