<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Http;

use OmniWrite\App;
use OmniWrite\Core\Config;
use OmniWrite\Http\Kernel;
use OmniWrite\Http\Request;
use OmniWrite\Http\Response;
use OmniWrite\Http\Session;
use OmniWrite\Repository\UserRepository;
use OmniWrite\Tests\Support\DbTestCase;

/** End-to-End durch den Kernel (ohne Webserver), Session als Array. */
final class KernelTest extends DbTestCase
{
    private Kernel $kernel;
    /** @var array<string, mixed> */
    private array $sessionData = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->kernel = App::kernel($this->pdo, new Config(['auth' => ['registration' => 'approval']]));
    }

    /** @param array<string, mixed> $post */
    private function call(string $method, string $path, array $post = [], bool $csrf = true): Response
    {
        $session = new Session($this->sessionData);
        if ($method === 'POST' && $csrf) {
            $post['_csrf'] = $this->token();
        }
        return $this->kernel->handle(new Request($method, $path, [], $post, $session, '9.9.9.9'));
    }

    private function token(): string
    {
        if (!isset($this->sessionData['csrf'])) {
            $this->call('GET', '/login'); // rendert das Formular und legt das Token an
        }
        return (string) $this->sessionData['csrf'];
    }

    private function logout(): void
    {
        $this->call('POST', '/logout');
    }

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

        // Superadmin schaltet frei.
        $users = new UserRepository($this->pdo);
        $rootId = $users->create('root@example.com', password_hash('root password long', PASSWORD_DEFAULT), 'Root', 'de', 'active', true);
        $this->logout();
        self::assertSame('/admin', $this->call('POST', '/login', ['email' => 'root@example.com', 'password' => 'root password long'])->headers['Location']);
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
        $users = new UserRepository($this->pdo);
        $id = $users->create('u@example.com', password_hash('some long password', PASSWORD_DEFAULT), 'U', 'de', 'active');
        $accId = (new \OmniWrite\Repository\AccountRepository($this->pdo))->create('A');
        (new \OmniWrite\Repository\AccountRepository($this->pdo))->addMember($accId, $id, \OmniWrite\Domain\Role::Owner);
        $this->call('POST', '/login', ['email' => 'u@example.com', 'password' => 'some long password']);
        self::assertSame(200, $this->call('GET', '/dashboard')->status);
        $users->setStatus($id, 'disabled');
        self::assertSame('/login', $this->call('GET', '/dashboard')->headers['Location']);
    }
}
