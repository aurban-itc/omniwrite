<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Http;

use OmniWrite\Repository\SiteRepository;
use OmniWrite\Service\UsageReport;
use OmniWrite\Tests\Support\KernelTestCase;

final class AdminUsageTest extends KernelTestCase
{
    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();
        $secret = $this->enableTotp($this->createUser('root@example.com', 'root password long', true));
        $owner = $this->createUser('owner@example.com');
        $accountId = $this->accounts->membershipsOf($owner)[0]['id'];
        $this->pdo->prepare('UPDATE accounts SET name = ? WHERE id = ?')->execute(['=Evil Account', $accountId]);
        $this->siteId = (new SiteRepository($this->pdo))->create($accountId, 'alpha', 'Alpha Blog', 'de', 'Europe/Berlin');
        $this->loginWithTotp('root@example.com', 'root password long', $secret);
    }

    private function event(string $kind, string $at, int $qty = 1): void
    {
        $this->pdo->prepare('INSERT INTO usage_events (site_id, kind, quantity, created_at) VALUES (?, ?, ?, ?)')->execute([$this->siteId, $kind, $qty, $at]);
    }

    public function testMonthFollowsSiteTimezoneAndSumsPerKind(): void
    {
        $this->event('ai_post', '2026-10-31 22:59:59');   // 23:59:59 Berlin = Oktober
        $this->event('ai_post', '2026-10-31 23:00:00');   // 00:00 Berlin = November
        $this->event('ai_post', '2026-11-10 12:00:00');
        $this->event('ai_text', '2026-11-11 12:00:00', 2);
        $this->event('ai_image', '2026-11-30 22:59:59');
        $this->event('ai_image', '2026-11-30 23:00:00');   // Dezember
        $this->event('other', '2026-11-11 12:00:00');

        $nov = (new UsageReport($this->pdo))->forMonth('2026-11')[0];
        self::assertSame(2, $nov['ai_post']);
        self::assertSame(2, $nov['ai_text']);
        self::assertSame(1, $nov['ai_image']);
        $oct = (new UsageReport($this->pdo))->forMonth('2026-10')[0];
        self::assertSame(1, $oct['ai_post']);
    }

    public function testPageListsUsageAndLinksCsv(): void
    {
        $this->event('ai_post', '2026-11-10 12:00:00');
        $res = $this->call('GET', '/admin/usage?month=2026-11');
        self::assertSame(200, $res->status);
        self::assertStringContainsString('Alpha Blog', $res->body);
        self::assertStringContainsString('format=csv', $res->body);
        self::assertStringContainsString('/admin/usage?month=2026-10', $res->body);
        self::assertStringContainsString('/admin/usage', $this->call('GET', '/admin')->body);
    }

    public function testInvalidMonthFallsBackToCurrent(): void
    {
        self::assertSame(gmdate('Y-m'), UsageReport::normalizeMonth('2026-13'));
        self::assertSame(gmdate('Y-m'), UsageReport::normalizeMonth('<script>'));
        self::assertSame('2026-02', UsageReport::normalizeMonth('2026-02'));
        self::assertStringContainsString(gmdate('Y-m'), $this->call('GET', '/admin/usage?month=%3Cx%3E')->body);
    }

    public function testCsvIsSafeAndSemicolonSeparated(): void
    {
        $this->event('ai_post', '2026-11-10 12:00:00');
        $res = $this->call('GET', '/admin/usage?month=2026-11&format=csv');
        self::assertSame(200, $res->status);
        self::assertStringStartsWith('text/csv', $res->headers['Content-Type']);
        self::assertStringContainsString('omniwrite-nutzung-2026-11.csv', $res->headers['Content-Disposition']);
        self::assertStringContainsString('"2026-11";"\'=Evil Account";"Standard";"alpha";"1";"0";"0"', $res->body, 'Zellen mit = am Anfang werden entschärft');
    }

    public function testOnlySuperadminMaySee(): void
    {
        $this->sessionData = [];
        $this->call('POST', '/login', ['email' => 'owner@example.com', 'password' => 'some long password']);
        self::assertContains($this->call('GET', '/admin/usage')->status, [403, 404]);
    }
}
