<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Http;

use OmniWrite\Tests\Support\KernelTestCase;

/** End-to-End durch den Kernel (ohne Webserver), Session als Array. */
final class KernelTest extends KernelTestCase
{
    public function testHealth(): void
    {
        self::assertSame(200, $this->call('GET', '/health')->status);
    }

    public function testDashboardRequiresLogin(): void
    {
        $r = $this->call('GET', '/dashboard');
        self::assertSame(303, $r->status);
        self::assertSame('/login', $r->headers['Location']);
    }

    public function testPostWithoutCsrfIsRejected(): void
    {
        self::assertSame(419, $this->call('POST', '/login', ['email' => 'a@b.de', 'password' => 'x'], false)->status);
    }

    public function testRegisterApproveLoginCreateSiteFlow(): void
    {
        $reg = $this->call('POST', '/register', [
            'email' => 'anna@example.com', 'password' => 'correct horse battery', 'name' => 'Anna', 'account_name' => 'Anna <b>GmbH</b>',
        ]);
        self::assertSame(200, $reg->status);
        self::assertStringContainsString('Freischaltung', $reg->body);

        // Noch nicht freigeschaltet: Login scheitert.
        self::assertSame(422, $this->call('POST', '/login', ['email' => 'anna@example.com', 'password' => 'correct horse battery'])->status);

        // Superadmin (mit eingerichteter 2FA) schaltet frei.
        $rootId = $this->createUser('root@example.com', 'root password long', true);
        $secret = $this->enableTotp($rootId);
        $this->logout();
        self::assertSame('/admin', $this->loginWithTotp('root@example.com', 'root password long', $secret)->headers['Location']);
        $admin = $this->call('GET', '/admin');
        self::assertStringContainsString('anna@example.com', $admin->body);
        $annaId = (int) $this->pdo->query("SELECT id FROM users WHERE email = 'anna@example.com'")->fetchColumn();
        $this->call('POST', "/admin/users/$annaId/activate");
        $this->logout();

        // Anna meldet sich an, legt eine Site an; HTML-Eingaben werden escaped.
        self::assertSame('/dashboard', $this->call('POST', '/login', ['email' => 'anna@example.com', 'password' => 'correct horse battery'])->headers['Location']);
        $dash = $this->call('GET', '/dashboard');
        self::assertStringContainsString('Anna &lt;b&gt;GmbH&lt;/b&gt;', $dash->body);
        $created = $this->call('POST', '/sites', ['name' => 'Blog', 'slug' => 'anna-blog', 'locale' => 'de', 'timezone' => 'Europe/Berlin']);
        self::assertSame(303, $created->status);
        self::assertStringContainsString('/b/anna-blog/', $this->call('GET', '/dashboard')->body);

        // Kein Admin-Zugriff für normale Nutzer.
        self::assertSame(403, $this->call('GET', '/admin')->status);
        self::assertSame($rootId, (int) $this->pdo->query("SELECT id FROM users WHERE is_superadmin = 1")->fetchColumn());
    }

    public function testDisabledUserIsLoggedOutImmediately(): void
    {
        $id = $this->createUser('u@example.com');
        $this->call('POST', '/login', ['email' => 'u@example.com', 'password' => 'some long password']);
        self::assertSame(200, $this->call('GET', '/dashboard')->status);
        $this->users->setStatus($id, 'disabled');
        self::assertSame('/login', $this->call('GET', '/dashboard')->headers['Location']);
    }
}
