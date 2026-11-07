<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Http;

use OmniWrite\Auth\Totp;
use OmniWrite\Tests\Support\KernelTestCase;

final class TwoFactorFlowTest extends KernelTestCase
{
    public function testSuperadminWithout2faIsForcedToSetup(): void
    {
        $this->createUser('root@example.com', 'root password long', true);
        $login = $this->call('POST', '/login', ['email' => 'root@example.com', 'password' => 'root password long']);
        self::assertSame('/admin', $login->headers['Location']);

        $admin = $this->call('GET', '/admin');
        self::assertSame('/account/2fa', $admin->headers['Location'], 'ohne 2FA kein Admin-Bereich');
        self::assertSame(200, $this->call('GET', '/account/2fa')->status);
        self::assertSame(200, $this->call('GET', '/health')->status);
    }

    public function testSetupThenLoginWithSecondFactor(): void
    {
        $this->createUser('root@example.com', 'root password long', true);
        $this->call('POST', '/login', ['email' => 'root@example.com', 'password' => 'root password long']);

        $page = $this->call('GET', '/account/2fa');
        preg_match('#<code>([A-Z2-7]{32})</code>#', $page->body, $m);
        self::assertNotEmpty($m, 'Secret wird angezeigt');
        $secret = $m[1];

        $wrong = $this->call('POST', '/account/2fa', ['code' => '000000']);
        self::assertSame(422, $wrong->status);

        $done = $this->call('POST', '/account/2fa', ['code' => Totp::code($secret, intdiv(time(), 30))]);
        self::assertSame(200, $done->status);
        self::assertSame(8, preg_match_all('#<code>[a-z2-7]{10}</code>#', $done->body));
        self::assertSame(200, $this->call('GET', '/admin')->status, 'jetzt ist der Admin-Bereich frei');

        // Neuer Login verlangt den zweiten Faktor; bis dahin ist man nicht angemeldet.
        $this->logout();
        $first = $this->call('POST', '/login', ['email' => 'root@example.com', 'password' => 'root password long']);
        self::assertSame('/login/2fa', $first->headers['Location']);
        self::assertSame('/login', $this->call('GET', '/admin')->headers['Location']);

        self::assertSame(422, $this->call('POST', '/login/2fa', ['code' => '123456'])->status);
        $ok = $this->call('POST', '/login/2fa', ['code' => Totp::code($secret, intdiv(time(), 30) + 1)]);
        self::assertSame('/admin', $ok->headers['Location']);
        self::assertSame(200, $this->call('GET', '/admin')->status);
    }

    public function test2faStepIsUnreachableWithoutPasswordStep(): void
    {
        self::assertSame('/login', $this->call('GET', '/login/2fa')->headers['Location']);
        self::assertSame('/login', $this->call('POST', '/login/2fa', ['code' => '123456'])->headers['Location']);
    }

    public function testPendingSecondFactorExpires(): void
    {
        $id = $this->createUser('root@example.com', 'root password long', true);
        $this->enableTotp($id);
        $this->call('POST', '/login', ['email' => 'root@example.com', 'password' => 'root password long']);
        $this->sessionData['pending_2fa']['at'] = time() - 301;
        self::assertSame('/login', $this->call('GET', '/login/2fa')->headers['Location']);
    }

    public function testNormalUsersAreNotForcedButMayEnable2fa(): void
    {
        $this->createUser('u@example.com');
        $this->call('POST', '/login', ['email' => 'u@example.com', 'password' => 'some long password']);
        self::assertSame(200, $this->call('GET', '/dashboard')->status);
        self::assertSame(200, $this->call('GET', '/account/2fa')->status);
    }

    public function testSecondFactorFailuresAreRateLimited(): void
    {
        $id = $this->createUser('root@example.com', 'root password long', true);
        $this->enableTotp($id);
        $this->call('POST', '/login', ['email' => 'root@example.com', 'password' => 'root password long']);
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(422, $this->call('POST', '/login/2fa', ['code' => '111111'])->status);
        }
        self::assertSame(429, $this->call('POST', '/login/2fa', ['code' => '111111'])->status);
    }

    public function testSessionWithoutPassedSecondFactorIsNotLoggedIn(): void
    {
        $id = $this->createUser('root@example.com', 'root password long', true);
        $this->enableTotp($id);
        // Sitzung aus der Zeit vor der 2FA-Aktivierung: user_id gesetzt, aber kein 'mfa'
        $this->sessionData['user_id'] = $id;
        $res = $this->call('GET', '/admin');
        self::assertSame('/login', $res->headers['Location'] ?? null);
        self::assertArrayNotHasKey('user_id', $this->sessionData);
    }

    public function testStatusPageShownWhen2faEnabled(): void
    {
        $id = $this->createUser('u@example.com');
        $secret = $this->enableTotp($id);
        $this->loginWithTotp('u@example.com', 'some long password', $secret);
        self::assertSame(200, $this->call('GET', '/account/2fa')->status);
    }

    public function testMfaProofOfOneAccountDoesNotCoverAnother(): void
    {
        $a = $this->createUser('a@example.com');
        $secretA = $this->enableTotp($a);
        $b = $this->createUser('b@example.com');
        $this->loginWithTotp('a@example.com', 'some long password', $secretA);
        // Sitzung wird manuell auf B umgestellt, ohne B's zweiten Faktor
        $this->sessionData['user_id'] = $b;
        $this->enableTotp($b);
        self::assertSame('/login', $this->call('GET', '/dashboard')->headers['Location'] ?? null);
    }
}
