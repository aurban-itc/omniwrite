<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Auth;

use OmniWrite\Auth\AuditLogger;
use OmniWrite\Auth\RateLimiter;
use OmniWrite\Auth\SecretBox;
use OmniWrite\Auth\Totp;
use OmniWrite\Auth\TwoFactorService;
use OmniWrite\Domain\AccessDeniedException;
use OmniWrite\Domain\User;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Repository\TotpRepository;
use OmniWrite\Repository\UserRepository;
use OmniWrite\Tests\Support\DbTestCase;

final class TwoFactorServiceTest extends DbTestCase
{
    private const NOW = 1_800_000_000;
    private TwoFactorService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TwoFactorService(
            $this->pdo, new TotpRepository($this->pdo), new SecretBox(base64_encode(str_repeat('k', 32))),
            new RateLimiter($this->pdo), new AuditLogger($this->pdo),
        );
        $users = new UserRepository($this->pdo);
        $this->user = $users->find($users->create('root@example.com', 'x', 'Root', 'de', 'active', true));
    }

    private function step(int $offset = 0): int
    {
        return intdiv(self::NOW, 30) + $offset;
    }

    /** @return array{string, list<string>} Secret und Recovery-Codes nach abgeschlossener Einrichtung */
    private function setUp2fa(): array
    {
        $secret = $this->service->beginSetup($this->user)['secret'];
        return [$secret, $this->service->confirmSetup($this->user, Totp::code($secret, $this->step()), self::NOW)];
    }

    public function testSecretIsStoredEncrypted(): void
    {
        $secret = $this->service->beginSetup($this->user)['secret'];
        $stored = (string) $this->pdo->query('SELECT secret_enc FROM user_totp')->fetchColumn();
        self::assertStringNotContainsString($secret, $stored);
        self::assertFalse($this->service->isEnabled($this->user));
    }

    public function testSetupReloadKeepsSameSecretUntilConfirmed(): void
    {
        $first = $this->service->setupInfo($this->user);
        self::assertSame($first['secret'], $this->service->setupInfo($this->user)['secret']);
    }

    public function testConfirmWithWrongCodeFails(): void
    {
        $this->service->beginSetup($this->user);
        $this->expectException(ValidationException::class);
        $this->service->confirmSetup($this->user, '000000', self::NOW);
    }

    public function testConfirmEnablesAndReturnsRecoveryCodesStoredHashed(): void
    {
        [, $codes] = $this->setUp2fa();
        self::assertTrue($this->service->isEnabled($this->user));
        self::assertCount(8, $codes);
        self::assertMatchesRegularExpression('/^[a-z2-7]{10}$/', $codes[0]);
        $hashes = $this->pdo->query('SELECT code_hash FROM recovery_codes')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertNotContains($codes[0], $hashes);
        self::assertContains(hash('sha256', $codes[0]), $hashes);
    }

    public function testSetupCannotBeRestartedOnceEnabled(): void
    {
        $this->setUp2fa();
        $this->expectException(AccessDeniedException::class);
        $this->service->beginSetup($this->user);
    }

    public function testLoginAcceptsCodeOnceAndRejectsReplay(): void
    {
        [$secret] = $this->setUp2fa();
        $later = Totp::code($secret, $this->step(1));
        self::assertTrue($this->service->verifyLogin($this->user, $later, '1.1.1.1', self::NOW));
        self::assertFalse($this->service->verifyLogin($this->user, $later, '1.1.1.1', self::NOW), 'gleicher Zeitschritt darf nicht erneut gelten');
        // Auch der Setup-Schritt ist verbraucht.
        self::assertFalse($this->service->verifyLogin($this->user, Totp::code($secret, $this->step()), '1.1.1.1', self::NOW));
    }

    public function testRecoveryCodeWorksExactlyOnce(): void
    {
        [, $codes] = $this->setUp2fa();
        self::assertTrue($this->service->verifyLogin($this->user, strtoupper(substr($codes[0], 0, 5)) . '-' . substr($codes[0], 5), '1.1.1.1', self::NOW));
        self::assertFalse($this->service->verifyLogin($this->user, $codes[0], '1.1.1.1', self::NOW));
        self::assertTrue($this->service->verifyLogin($this->user, $codes[1], '1.1.1.1', self::NOW));
    }

    public function testBruteForceIsRateLimited(): void
    {
        $this->setUp2fa();
        for ($i = 0; $i < 5; $i++) {
            self::assertFalse($this->service->verifyLogin($this->user, '111111', '2.2.2.2', self::NOW));
        }
        $this->expectException(AccessDeniedException::class);
        $this->service->verifyLogin($this->user, '111111', '2.2.2.2', self::NOW);
    }

    public function testRequiredOnlyForSuperadmins(): void
    {
        $users = new UserRepository($this->pdo);
        $normal = $users->find($users->create('u@example.com', 'x', 'U', 'de', 'active'));
        self::assertTrue($this->service->isRequired($this->user));
        self::assertFalse($this->service->isRequired($normal));
    }
}
