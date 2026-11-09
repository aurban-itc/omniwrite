<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Core;

use OmniWrite\Core\Config;
use OmniWrite\Setup\SetupPage;
use OmniWrite\Tests\Support\DbTestCase;

final class SetupPageTest extends DbTestCase
{
    private function page(?string $token = 'geheim-token'): SetupPage
    {
        $cfg = ['security' => ['master_key' => base64_encode(str_repeat('k', 32))]];
        if ($token !== null) {
            $cfg['setup'] = ['token' => $token];
        }
        return new SetupPage(new Config($cfg), fn () => $this->pdo, dirname(__DIR__, 2) . '/migrations');
    }

    public function testDisabledWithoutToken(): void
    {
        self::assertSame(404, $this->page(null)->handle('GET', [])['status']);
        self::assertSame(404, $this->page(null)->handle('POST', ['token' => '', 'action' => 'migrate'])['status']);
    }

    public function testFormIsShownWithToken(): void
    {
        $r = $this->page()->handle('GET', []);
        self::assertSame(200, $r['status']);
        self::assertStringContainsString('Migrationen ausführen', $r['html']);
    }

    public function testWrongTokenIsRejected(): void
    {
        $r = $this->page()->handle('POST', ['token' => 'falsch', 'action' => 'superadmin', 'email' => 'a@b.de', 'password' => 'a very long password']);
        self::assertSame(403, $r['status']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM users WHERE is_superadmin = 1')->fetchColumn());
    }

    public function testSuperadminOnlyOnce(): void
    {
        $page = $this->page();
        $ok = $page->handle('POST', ['token' => 'geheim-token', 'action' => 'superadmin', 'email' => 'root@example.com', 'name' => 'Root', 'password' => 'a very long password']);
        self::assertSame(200, $ok['status']);
        self::assertStringContainsString('root@example.com', $ok['html']);
        $again = $page->handle('POST', ['token' => 'geheim-token', 'action' => 'superadmin', 'email' => 'two@example.com', 'password' => 'a very long password']);
        self::assertSame(409, $again['status']);
    }

    public function testInvalidInputIsEscaped(): void
    {
        $r = $this->page()->handle('POST', ['token' => 'geheim-token', 'action' => 'superadmin', 'email' => '<script>@x.de', 'password' => 'short']);
        self::assertSame(422, $r['status']);
        self::assertStringNotContainsString('<script>', $r['html']);
    }
}
