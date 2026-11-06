<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Http;

use OmniWrite\Http\HttpsPolicy;
use PHPUnit\Framework\TestCase;

final class HttpsPolicyTest extends TestCase
{
    private const BASE = 'https://omniwrite.example';

    public function testPlainHttpIsRedirectedToConfiguredHost(): void
    {
        $server = ['REQUEST_URI' => '/login?x=1', 'HTTP_HOST' => 'evil.example'];
        self::assertSame('https://omniwrite.example/login?x=1', HttpsPolicy::redirectTarget($server, self::BASE, true, false));
    }

    public function testHttpsOnNeedsNoRedirect(): void
    {
        self::assertNull(HttpsPolicy::redirectTarget(['HTTPS' => 'on', 'REQUEST_URI' => '/'], self::BASE, true, false));
    }

    public function testForwardedProtoIsIgnoredUnlessTrusted(): void
    {
        $server = ['HTTP_X_FORWARDED_PROTO' => 'https', 'REQUEST_URI' => '/'];
        self::assertNotNull(HttpsPolicy::redirectTarget($server, self::BASE, true, false)); // gefälscht: weiter umleiten
        self::assertNull(HttpsPolicy::redirectTarget($server, self::BASE, true, true));     // Proxy ist als vertrauenswürdig konfiguriert
    }

    public function testDisabledOrNonHttpsBaseUrlDoesNothing(): void
    {
        self::assertNull(HttpsPolicy::redirectTarget(['REQUEST_URI' => '/'], self::BASE, false, false));
        self::assertNull(HttpsPolicy::redirectTarget(['REQUEST_URI' => '/'], 'http://localhost:8000', true, false));
    }
}
