<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Http;

use OmniWrite\Tests\Support\KernelTestCase;

final class ImpersonationTest extends KernelTestCase
{
    private int $adminId;
    private int $customerId;
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = $this->createUser('root@example.com', 'root password long', true);
        $this->secret = $this->enableTotp($this->adminId);
        $this->customerId = $this->createUser('kunde@example.com');
    }

    private function loginAdmin(): void
    {
        $this->loginWithTotp('root@example.com', 'root password long', $this->secret);
    }

    /** @return list<array<string, mixed>> */
    private function auditRows(string $action): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM audit_log WHERE action = ? ORDER BY id');
        $stmt->execute([$action]);
        return $stmt->fetchAll();
    }

    public function testAdminSeesCustomersAndCanImpersonateAndStop(): void
    {
        $this->loginAdmin();
        self::assertStringContainsString('kunde@example.com', $this->call('GET', '/admin')->body);

        $start = $this->call('POST', "/admin/impersonate/{$this->customerId}");
        self::assertSame('/dashboard', $start->headers['Location']);
        $dash = $this->call('GET', '/dashboard');
        self::assertSame(200, $dash->status);
        self::assertStringContainsString('Account kunde@example.com', $dash->body);
        self::assertStringContainsString('Support-Modus', $dash->body);

        $stop = $this->call('POST', '/impersonate/stop');
        self::assertSame('/admin', $stop->headers['Location']);
        self::assertSame(200, $this->call('GET', '/admin')->status);
        self::assertStringNotContainsString('Support-Modus', $this->call('GET', '/admin')->body);

        $starts = $this->auditRows('admin.impersonate_start');
        self::assertCount(1, $starts);
        self::assertSame($this->adminId, (int) $starts[0]['user_id']);
        self::assertCount(1, $this->auditRows('admin.impersonate_stop'));
    }

    public function testAdminAreaIsClosedWhileImpersonating(): void
    {
        $this->loginAdmin();
        $this->call('POST', "/admin/impersonate/{$this->customerId}");
        self::assertSame(403, $this->call('GET', '/admin')->status);
        self::assertSame(403, $this->call('POST', "/admin/impersonate/{$this->customerId}")->status);
    }

    public function testActionsWhileImpersonatingCarryTheRealActor(): void
    {
        $this->loginAdmin();
        $this->call('POST', "/admin/impersonate/{$this->customerId}");
        $this->call('POST', '/sites', ['name' => 'Blog', 'slug' => 'kunde-blog', 'locale' => 'de', 'timezone' => 'Europe/Berlin']);
        $rows = $this->auditRows('site.create');
        self::assertCount(1, $rows);
        self::assertSame($this->customerId, (int) $rows[0]['user_id']);
        self::assertSame($this->adminId, json_decode((string) $rows[0]['meta'], true)['impersonator_id']);
    }

    public function testActionsAfterStoppingCarryNoImpersonatorMark(): void
    {
        $pending = $this->users->create('neu@example.com', 'x', 'Neu', 'de', 'pending');
        $this->loginAdmin();
        $this->call('POST', "/admin/impersonate/{$this->customerId}");
        $this->call('POST', '/impersonate/stop');
        $this->call('POST', "/admin/users/$pending/activate");
        $rows = $this->auditRows('user.activate');
        self::assertCount(1, $rows);
        self::assertStringNotContainsString('impersonator_id', (string) $rows[0]['meta']);
    }

    public function testRegularUserCannotImpersonate(): void
    {
        $other = $this->createUser('anderer@example.com');
        $this->call('POST', '/login', ['email' => 'kunde@example.com', 'password' => 'some long password']);
        self::assertSame(403, $this->call('POST', "/admin/impersonate/$other")->status);
        self::assertSame('/dashboard', $this->call('POST', '/impersonate/stop')->headers['Location'], 'Stop ohne Impersonation ist wirkungslos');
        self::assertSame(200, $this->call('GET', '/dashboard')->status);
    }

    public function testSuperadminsAndDisabledUsersCannotBeImpersonated(): void
    {
        $second = $this->createUser('root2@example.com', 'another long password', true);
        $disabled = $this->createUser('weg@example.com');
        $this->users->setStatus($disabled, 'disabled');
        $this->loginAdmin();
        self::assertSame(404, $this->call('POST', "/admin/impersonate/$second")->status);
        self::assertSame(404, $this->call('POST', "/admin/impersonate/$disabled")->status);
        self::assertSame(404, $this->call('POST', '/admin/impersonate/99999')->status);
    }

    public function testImpersonationEndsWhenAdminLosesAccess(): void
    {
        $this->loginAdmin();
        $this->call('POST', "/admin/impersonate/{$this->customerId}");
        self::assertSame(200, $this->call('GET', '/dashboard')->status);
        $this->users->setStatus($this->adminId, 'disabled');
        self::assertSame('/login', $this->call('GET', '/dashboard')->headers['Location']);
        self::assertArrayNotHasKey('user_id', $this->sessionData);
        self::assertArrayNotHasKey('impersonator_id', $this->sessionData);
    }

    public function testLogoutWhileImpersonatingIsAudited(): void
    {
        $this->loginAdmin();
        $this->call('POST', "/admin/impersonate/{$this->customerId}");
        $this->logout();
        self::assertCount(1, $this->auditRows('admin.impersonate_stop'));
    }

    public function testCanImpersonateCustomerWhoHas2faEnabled(): void
    {
        $this->enableTotp($this->customerId);
        $this->loginAdmin();
        $this->call('POST', "/admin/impersonate/{$this->customerId}");
        self::assertSame(200, $this->call('GET', '/dashboard')->status);
    }

    public function testTwoFactorSetupIsClosedWhileImpersonating(): void
    {
        $this->loginAdmin();
        $this->call('POST', "/admin/impersonate/{$this->customerId}");
        self::assertSame('/dashboard', $this->call('GET', '/account/2fa')->headers['Location'] ?? null);
        $this->call('POST', '/account/2fa', ['code' => '123456']);
        self::assertSame(200, $this->call('GET', '/dashboard')->status);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM user_totp WHERE user_id = ' . $this->customerId)->fetchColumn());
    }
}
