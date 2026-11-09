<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Support;

use OmniWrite\App;
use OmniWrite\Auth\SecretBox;
use OmniWrite\Auth\Totp;
use OmniWrite\Core\Config;
use OmniWrite\Domain\Role;
use OmniWrite\Http\Kernel;
use OmniWrite\Http\Request;
use OmniWrite\Http\Response;
use OmniWrite\Http\Session;
use OmniWrite\Repository\AccountRepository;
use OmniWrite\Repository\TotpRepository;
use OmniWrite\Repository\UserRepository;

/** Gemeinsame Helfer für End-to-End-Tests durch den Kernel (ohne Webserver), Session als Array. */
abstract class KernelTestCase extends DbTestCase
{
    protected Kernel $kernel;
    protected UserRepository $users;
    protected AccountRepository $accounts;
    /** @var array<string, mixed> */
    protected array $sessionData = [];
    /** @var array<string, int> nächster Zeitschritt-Offset pro Secret (Replay-Schutz verlangt steigende Schritte) */
    private array $offsets = [];
    private string $masterKey;
    protected FakeHttpClient $http;
    protected FakeMailer $mailer;
    protected string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->masterKey = base64_encode(str_repeat('k', 32));
        $this->users = new UserRepository($this->pdo);
        $this->accounts = new AccountRepository($this->pdo);
        $this->http = new FakeHttpClient();
        $this->mailer = new FakeMailer();
        $this->storageDir = sys_get_temp_dir() . '/omniwrite-test-' . bin2hex(random_bytes(4));
        $this->kernel = App::kernel($this->pdo, new Config([
            'auth' => ['registration' => 'approval'],
            'security' => ['master_key' => $this->masterKey],
            'ai' => ['anthropic_api_key' => 'test-anthropic', 'openai_api_key' => 'test-openai'],
            'storage' => ['path' => $this->storageDir],
                    'app' => ['base_url' => 'https://omniwrite.example'],
        ]), null, $this->http, $this->mailer);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageDir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->storageDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->storageDir);
        }
        parent::tearDown();
    }

    /** @param array<string, mixed> $post */
    protected function call(string $method, string $path, array $post = [], bool $csrf = true): Response
    {
        $session = new Session($this->sessionData);
        if ($method === 'POST' && $csrf) {
            $post['_csrf'] = $this->token();
        }
        $query = [];
        if (str_contains($path, '?')) {
            [$path, $qs] = explode('?', $path, 2);
            parse_str($qs, $query);
        }
        return $this->kernel->handle(new Request($method, $path, $query, $post, $session, '9.9.9.9'));
    }

    protected function token(): string
    {
        if (!isset($this->sessionData['csrf'])) {
            $this->call('GET', '/login'); // rendert das Formular und legt das Token an
        }
        return (string) $this->sessionData['csrf'];
    }

    protected function logout(): void
    {
        $this->call('POST', '/logout');
    }

    protected function createUser(string $email, string $password = 'some long password', bool $superadmin = false, ?Role $role = null): int
    {
        $id = $this->users->create($email, password_hash($password, PASSWORD_DEFAULT), ucfirst(strtok($email, '@')), 'de', 'active', $superadmin);
        if (!$superadmin) {
            $this->accounts->addMember($this->accounts->create('Account ' . $email), $id, $role ?? Role::Owner);
        }
        return $id;
    }

    /** Schaltet 2FA direkt in der Datenbank frei (wie nach abgeschlossener Einrichtung) und gibt das Secret zurück. */
    protected function enableTotp(int $userId): string
    {
        $secret = Totp::generateSecret();
        $repo = new TotpRepository($this->pdo);
        $repo->begin($userId, (new SecretBox($this->masterKey))->encrypt($secret));
        $repo->confirm($userId, 0, gmdate('Y-m-d H:i:s'));
        return $secret;
    }

    /** Nächster gültiger Code (Schritte steigen von -1 bis +1 relativ zu jetzt, also bis zu drei Logins pro Test). */
    protected function nextCode(string $secret): string
    {
        $offset = $this->offsets[$secret] ?? -1;
        $this->offsets[$secret] = $offset + 1;
        return Totp::code($secret, intdiv(time(), Totp::PERIOD) + $offset);
    }

    protected function loginWithTotp(string $email, string $password, string $secret): Response
    {
        $first = $this->call('POST', '/login', ['email' => $email, 'password' => $password]);
        self::assertSame('/login/2fa', $first->headers['Location'] ?? null);
        return $this->call('POST', '/login/2fa', ['code' => $this->nextCode($secret)]);
    }
}
