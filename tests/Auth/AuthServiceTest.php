<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Auth;

use OmniWrite\Auth\AuditLogger;
use OmniWrite\Auth\AuthService;
use OmniWrite\Auth\PasswordHasher;
use OmniWrite\Auth\RateLimiter;
use OmniWrite\Domain\AccessDeniedException;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Repository\AccountRepository;
use OmniWrite\Repository\UserRepository;
use OmniWrite\Tests\Support\DbTestCase;

final class AuthServiceTest extends DbTestCase
{
    private function auth(string $mode = 'open'): AuthService
    {
        return new AuthService(
            $this->pdo, new UserRepository($this->pdo), new AccountRepository($this->pdo),
            new PasswordHasher(), new RateLimiter($this->pdo), new AuditLogger($this->pdo), $mode,
        );
    }

    /** @return array<string, string> */
    private function input(string $email = 'a@example.com'): array
    {
        return ['email' => $email, 'password' => 'correct horse battery', 'name' => 'Anna', 'account_name' => 'Anna GmbH'];
    }

    public function testRegisterCreatesUserAccountAndOwnerMembership(): void
    {
        $user = $this->auth()->register($this->input('  A@Example.com '));
        self::assertSame('a@example.com', $user->email);
        self::assertTrue($user->isActive());
        $m = (new AccountRepository($this->pdo))->membershipsOf($user->id);
        self::assertCount(1, $m);
        self::assertSame('owner', $m[0]['role']);
        self::assertNotNull($this->pdo->query("SELECT 1 FROM audit_log WHERE action = 'user.register'")->fetchColumn());
    }

    public function testRegisterValidatesInput(): void
    {
        $this->auth()->register($this->input());
        try {
            $this->auth()->register(['email' => 'a@example.com', 'password' => 'short', 'name' => '', 'account_name' => '']);
            self::fail('ValidationException erwartet');
        } catch (ValidationException $e) {
            self::assertSame(['email', 'password', 'name', 'account_name'], array_keys($e->errors));
            self::assertSame('err.email_taken', $e->errors['email']);
        }
    }

    public function testClosedRegistrationIsRejected(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->auth('closed')->register($this->input());
    }

    public function testApprovalModeKeepsUserPendingUntilActivated(): void
    {
        $auth = $this->auth('approval');
        $user = $auth->register($this->input());
        self::assertFalse($user->isActive());
        self::assertNull($auth->attempt('a@example.com', 'correct horse battery', '1.1.1.1'));
        (new UserRepository($this->pdo))->setStatus($user->id, 'active');
        self::assertNotNull($auth->attempt('a@example.com', 'correct horse battery', '1.1.1.1'));
    }

    public function testLoginSuccessAndFailure(): void
    {
        $auth = $this->auth();
        $auth->register($this->input());
        self::assertNull($auth->attempt('a@example.com', 'wrong password!!', '1.1.1.1'));
        self::assertNull($auth->attempt('nobody@example.com', 'whatever whatever', '1.1.1.1'));
        self::assertSame('a@example.com', $auth->attempt('A@example.com', 'correct horse battery', '1.1.1.1')?->email);
    }

    public function testRateLimitBlocksEvenWithCorrectPassword(): void
    {
        $auth = $this->auth();
        $auth->register($this->input());
        $now = 1_800_000_000;
        for ($i = 0; $i < 5; $i++) {
            self::assertNull($auth->attempt('a@example.com', 'wrong password!!', '2.2.2.2', $now + $i));
        }
        $this->expectException(AccessDeniedException::class);
        $auth->attempt('a@example.com', 'correct horse battery', '2.2.2.2', $now + 10);
    }

    public function testRateLimitWindowExpires(): void
    {
        $auth = $this->auth();
        $auth->register($this->input());
        $now = 1_800_000_000;
        for ($i = 0; $i < 5; $i++) {
            $auth->attempt('a@example.com', 'wrong password!!', '2.2.2.2', $now);
        }
        self::assertNotNull($auth->attempt('a@example.com', 'correct horse battery', '2.2.2.2', $now + 901));
    }

    public function testCreateSuperadmin(): void
    {
        $u = $this->auth('closed')->createSuperadmin('root@example.com', 'Root', 'a very long password');
        self::assertTrue($u->isSuperadmin);
        self::assertSame([], (new AccountRepository($this->pdo))->membershipsOf($u->id));
    }

    public function testOverlongEmailIsRejectedAndStillRateLimited(): void
    {
        $auth = $this->auth();
        $long = str_repeat('a', 200) . '@example.com';
        $now = 1_800_000_000;
        for ($i = 0; $i < 5; $i++) {
            self::assertNull($auth->attempt($long, 'whatever whatever', '3.3.3.3', $now));
        }
        $this->expectException(AccessDeniedException::class);
        $auth->attempt($long, 'whatever whatever', '3.3.3.3', $now);
    }

    public function testMultibyteEmailCrossingTheLimitDoesNotBreakAuditLog(): void
    {
        $email = str_repeat('a', 189) . 'é@example.com'; // 'é' liegt über der 190-Byte-Grenze
        self::assertNull($this->auth()->attempt($email, 'whatever whatever', '4.4.4.4'));
        self::assertSame('1', (string) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.login_failed'")->fetchColumn());
    }
}
