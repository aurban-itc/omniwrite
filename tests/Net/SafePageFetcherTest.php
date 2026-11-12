<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Net;

use OmniWrite\Net\FetchException;
use OmniWrite\Net\PageTransport;
use OmniWrite\Net\SafePageFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SafePageFetcherTest extends TestCase
{
    /** @var list<array{url: string, host: string, port: int, ip: string}> */
    private array $calls = [];
    /** @var list<array{status: int, location: string, contentType: string, body: string}> */
    private array $responses = [];

    private function fetcher(array $dns = ['example.com' => ['93.184.216.34']]): SafePageFetcher
    {
        $self = $this;
        $transport = new class ($self) implements PageTransport {
            public function __construct(private readonly SafePageFetcherTest $t)
            {
            }

            public function get(string $url, string $host, int $port, string $ip, int $maxBytes, int $timeoutSeconds): array
            {
                return $this->t->record($url, $host, $port, $ip);
            }
        };
        return new SafePageFetcher($transport, static fn (string $h): array => $dns[$h] ?? []);
    }

    /** @return array{status: int, location: string, contentType: string, body: string} */
    public function record(string $url, string $host, int $port, string $ip): array
    {
        $this->calls[] = ['url' => $url, 'host' => $host, 'port' => $port, 'ip' => $ip];
        return array_shift($this->responses) ?? ['status' => 0, 'location' => '', 'contentType' => '', 'body' => ''];
    }

    private function ok(string $body = '<html><body>Hallo</body></html>'): array
    {
        return ['status' => 200, 'location' => '', 'contentType' => 'text/html; charset=utf-8', 'body' => $body];
    }

    private function redirect(string $to): array
    {
        return ['status' => 302, 'location' => $to, 'contentType' => '', 'body' => ''];
    }

    private function assertBlocked(callable $f, string $key = 'err.fetch_blocked'): void
    {
        try {
            $f();
            self::fail('Abruf hätte abgelehnt werden müssen');
        } catch (FetchException $e) {
            self::assertSame($key, $e->getMessage());
        }
        self::assertSame([], $this->calls, 'es darf keine Verbindung aufgebaut worden sein');
    }

    public function testFetchesPublicHostPinnedToResolvedIp(): void
    {
        $this->responses = [$this->ok()];
        $res = $this->fetcher()->fetch('Example.com/start');
        self::assertSame('https://example.com/start', $res['url']);
        self::assertSame('93.184.216.34', $this->calls[0]['ip']);
        self::assertSame(443, $this->calls[0]['port']);
    }

    /** @return array<string, array{string}> */
    public static function badUrls(): array
    {
        return [
            'localhost' => ['http://localhost/'], 'loopback' => ['http://127.0.0.1/'], 'metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'private ip' => ['https://10.0.0.5/'], 'ipv6 loopback' => ['http://[::1]/'], 'mapped v4' => ['http://[::ffff:7f00:1]/'],
            'decimal ip' => ['http://2130706433/'], 'single label' => ['http://intranet/'], 'internal tld' => ['http://db.internal/'],
            'odd port' => ['http://example.com:8080/'], 'ssh port' => ['http://example.com:22/'],
        ];
    }

    #[DataProvider('badUrls')]
    public function testRejectsInternalTargets(string $url): void
    {
        $this->assertBlocked(fn () => $this->fetcher(['example.com' => ['93.184.216.34']])->fetch($url));
    }

    /** @return array<string, array{string}> */
    public static function invalidUrls(): array
    {
        return ['empty' => [''], 'file' => ['file:///etc/passwd'], 'javascript' => ['javascript:alert(1)'], 'ftp' => ['ftp://example.com/'],
            'userinfo' => ['https://user:pw@example.com/'], 'space' => ['https://exa mple.com/'], 'gopher' => ['gopher://example.com/']];
    }

    #[DataProvider('invalidUrls')]
    public function testRejectsInvalidUrls(string $url): void
    {
        $this->assertBlocked(fn () => $this->fetcher()->fetch($url), 'err.fetch_url_invalid');
    }

    public function testRejectsHostThatResolvesToPrivateAddressEvenIfAnotherIsPublic(): void
    {
        $this->assertBlocked(fn () => $this->fetcher(['evil.example' => ['93.184.216.34', '10.0.0.1']])->fetch('https://evil.example/'));
    }

    public function testRedirectToInternalTargetIsBlockedBeforeConnecting(): void
    {
        $this->responses = [$this->redirect('http://169.254.169.254/latest/meta-data/')];
        try {
            $this->fetcher()->fetch('https://example.com/');
            self::fail('Weiterleitung auf internes Ziel muss scheitern');
        } catch (FetchException $e) {
            self::assertSame('err.fetch_blocked', $e->getMessage());
        }
        self::assertCount(1, $this->calls);
    }

    public function testRedirectToFileSchemeIsRejected(): void
    {
        $this->responses = [$this->redirect('file:///etc/passwd')];
        $this->expectException(FetchException::class);
        $this->fetcher()->fetch('https://example.com/');
    }

    public function testFollowsRelativeRedirectsAndRevalidatesEachHop(): void
    {
        $this->responses = [$this->redirect('/de/'), $this->redirect('https://www.example.com/de/start'), $this->ok()];
        $res = $this->fetcher(['example.com' => ['93.184.216.34'], 'www.example.com' => ['93.184.216.35']])->fetch('https://example.com/');
        self::assertSame('https://www.example.com/de/start', $res['url']);
        self::assertSame(['93.184.216.34', '93.184.216.34', '93.184.216.35'], array_column($this->calls, 'ip'));
        self::assertSame('https://example.com/de/', $this->calls[1]['url']);
    }

    public function testQueryOnlyAndFragmentRedirectsKeepPath(): void
    {
        $this->responses = [$this->redirect('?lang=en'), $this->redirect('#top'), $this->ok()];
        $this->fetcher()->fetch('https://example.com/company/about');
        self::assertSame('https://example.com/company/about?lang=en', $this->calls[1]['url']);
        self::assertSame('https://example.com/company/about?lang=en', $this->calls[2]['url']);
    }

    public function testRedirectLoopIsStopped(): void
    {
        $this->responses = array_fill(0, 10, $this->redirect('/again'));
        try {
            $this->fetcher()->fetch('https://example.com/');
            self::fail();
        } catch (FetchException $e) {
            self::assertSame('err.fetch_failed', $e->getMessage());
        }
        self::assertLessThanOrEqual(5, count($this->calls));
    }

    public function testRejectsNonHtmlAndErrors(): void
    {
        $this->responses = [['status' => 200, 'location' => '', 'contentType' => 'application/pdf', 'body' => '%PDF']];
        try {
            $this->fetcher()->fetch('https://example.com/a.pdf');
            self::fail();
        } catch (FetchException $e) {
            self::assertSame('err.fetch_not_html', $e->getMessage());
        }
        foreach ([404, 500, 0] as $status) {
            $this->responses = [['status' => $status, 'location' => '', 'contentType' => 'text/html', 'body' => 'x']];
            try {
                $this->fetcher()->fetch('https://example.com/');
                self::fail();
            } catch (FetchException $e) {
                self::assertSame('err.fetch_failed', $e->getMessage());
            }
        }
    }

    public function testUnresolvableHostFails(): void
    {
        $this->expectException(FetchException::class);
        $this->fetcher([])->fetch('https://nonexistent.example/');
    }
}
