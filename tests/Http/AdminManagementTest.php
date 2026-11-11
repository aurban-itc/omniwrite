<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Http;

use OmniWrite\Http\Request;
use OmniWrite\Http\Session;
use OmniWrite\Tests\Support\KernelTestCase;

final class AdminManagementTest extends KernelTestCase
{
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $adminId = $this->createUser('root@example.com', 'root password long', true);
        $this->secret = $this->enableTotp($adminId);
        $this->loginWithTotp('root@example.com', 'root password long', $this->secret);
    }

    private function newAccount(string $email = 'inhaber@coastal.test'): int
    {
        $r = $this->call('POST', '/admin/accounts', [
            'account_name' => 'Coastal Copy', 'email' => $email, 'name' => 'Inhaber',
            'password' => 'startpasswort 123', 'locale' => 'en',
        ]);
        self::assertSame(303, $r->status);
        return (int) basename(explode('?', $r->headers['Location'])[0]);
    }

    public function testEmptyAdminPointsToCreatingAnAccount(): void
    {
        $body = $this->call('GET', '/admin')->body;
        self::assertStringContainsString('/admin/accounts/new', $body);
        self::assertStringContainsString('/admin/import', $body);
    }

    public function testCreateAccountWithOwnerWhoCanLogIn(): void
    {
        $id = $this->newAccount();
        self::assertStringContainsString('Coastal Copy', $this->call('GET', '/admin')->body);
        $detail = $this->call('GET', "/admin/accounts/$id");
        self::assertSame(200, $detail->status);
        self::assertStringContainsString('inhaber@coastal.test', $detail->body);
        self::assertSame('owner', $this->accounts->members($id)[0]['role']);

        $this->sessionData = [];
        $login = $this->call('POST', '/login', ['email' => 'inhaber@coastal.test', 'password' => 'startpasswort 123']);
        self::assertSame('/dashboard', $login->headers['Location']);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'admin.account_create'")->fetchColumn());
    }

    public function testCreateAccountValidates(): void
    {
        $this->newAccount();
        $r = $this->call('POST', '/admin/accounts', ['account_name' => '', 'email' => 'inhaber@coastal.test', 'name' => '', 'password' => 'kurz']);
        self::assertSame(422, $r->status);
        foreach (['Bitte Firma', 'bereits registriert', 'mindestens 10', 'Bitte einen Namen'] as $msg) {
            self::assertStringContainsString($msg, $r->body);
        }
        self::assertStringNotContainsString('kurz', $r->body, 'Passwort wird nicht zurückgespielt');
    }

    public function testRenameAddMemberAndRoles(): void
    {
        $id = $this->newAccount();
        $this->call('POST', "/admin/accounts/$id", ['account_name' => 'Coastal Copy GmbH']);
        self::assertSame('Coastal Copy GmbH', $this->accounts->find($id)['name']);

        $add = $this->call('POST', "/admin/accounts/$id/members", ['email' => 'lektor@coastal.test', 'name' => 'Lektor', 'password' => 'lektorpasswort1', 'role' => 'editor']);
        self::assertSame(303, $add->status);
        $members = $this->accounts->members($id);
        self::assertCount(2, $members);
        $editor = array_values(array_filter($members, fn ($m) => $m['email'] === 'lektor@coastal.test'))[0];
        self::assertSame('editor', $editor['role']);

        $dup = $this->call('POST', "/admin/accounts/$id/members", ['email' => 'lektor@coastal.test', 'role' => 'editor']);
        self::assertSame(422, $dup->status);
        self::assertStringContainsString('gehört schon', $dup->body);

        $this->call('POST', "/admin/accounts/$id/members/{$editor['user_id']}/role", ['role' => 'owner']);
        self::assertSame(2, $this->accounts->ownerCount($id));
        $this->call('POST', "/admin/accounts/$id/members/{$editor['user_id']}/remove");
        self::assertCount(1, $this->accounts->members($id));
    }

    public function testFailedMemberFormKeepsSelectedRole(): void
    {
        $id = $this->newAccount();
        $r = $this->call('POST', "/admin/accounts/$id/members", ['email' => 'neu@x.test', 'name' => 'Neu', 'password' => 'kurz', 'role' => 'owner']);
        self::assertSame(422, $r->status);
        self::assertMatchesRegularExpression('/<option value="owner" selected>/', $r->body);
    }

    public function testLastOwnerIsProtected(): void
    {
        $id = $this->newAccount();
        $owner = $this->accounts->members($id)[0]['user_id'];
        self::assertSame(422, $this->call('POST', "/admin/accounts/$id/members/$owner/remove")->status);
        self::assertSame(422, $this->call('POST', "/admin/accounts/$id/members/$owner/role", ['role' => 'editor'])->status);
        self::assertSame('owner', $this->accounts->members($id)[0]['role']);
    }

    public function testExistingCustomerCanBeAssignedToAnotherAccount(): void
    {
        $other = $this->newAccount('a@x.test');
        $uid = $this->accounts->members($other)[0]['user_id'];
        $second = (int) basename(explode('?', $this->call('POST', '/admin/accounts', ['account_name' => 'Zwei', 'email' => 'b@x.test', 'name' => 'B', 'password' => 'passwort lang 1'])->headers['Location'])[0]);
        $this->call('POST', "/admin/accounts/$second/members", ['email' => 'a@x.test', 'role' => 'editor']);
        self::assertCount(2, $this->accounts->membershipsOf($uid));
    }

    public function testDisableEnableAndPasswordReset(): void
    {
        $id = $this->newAccount();
        $uid = $this->accounts->members($id)[0]['user_id'];
        $this->call('POST', "/admin/users/$uid/disable", ['account_id' => (string) $id]);
        self::assertSame('disabled', $this->users->find($uid)->status);
        $this->call('POST', "/admin/users/$uid/enable", ['account_id' => (string) $id]);
        self::assertSame('active', $this->users->find($uid)->status);

        self::assertSame(422, $this->call('POST', "/admin/users/$uid/password", ['account_id' => (string) $id, 'password' => 'kurz'])->status);
        $this->call('POST', "/admin/users/$uid/password", ['account_id' => (string) $id, 'password' => 'ganz neues passwort']);
        $this->sessionData = [];
        self::assertSame('/dashboard', $this->call('POST', '/login', ['email' => 'inhaber@coastal.test', 'password' => 'ganz neues passwort'])->headers['Location']);
    }

    public function testRejectPendingRegistration(): void
    {
        $pending = $this->users->create('neu@example.com', 'x', 'Neu', 'de', 'pending');
        self::assertStringContainsString('neu@example.com', $this->call('GET', '/admin')->body);
        $this->call('POST', "/admin/users/$pending/reject");
        self::assertSame('disabled', $this->users->find($pending)->status);
        self::assertStringNotContainsString('neu@example.com', $this->call('GET', '/admin')->body);
    }

    public function testSuperadminCannotBeDisabledThroughAdminRoutes(): void
    {
        $root = (int) $this->pdo->query('SELECT id FROM users WHERE is_superadmin = 1')->fetchColumn();
        self::assertSame(404, $this->call('POST', "/admin/users/$root/disable")->status);
    }

    public function testCreateSiteListSitesAndImportCoastalExport(): void
    {
        $id = $this->newAccount();
        $bad = $this->call('POST', "/admin/accounts/$id/sites", ['name' => 'Insights', 'slug' => 'x', 'locale' => 'en', 'timezone' => 'Europe/Berlin']);
        self::assertSame(422, $bad->status);
        $this->call('POST', "/admin/accounts/$id/sites", ['name' => 'Coastal Insights', 'slug' => 'coastal', 'locale' => 'en', 'timezone' => 'Europe/Berlin']);
        $sites = $this->call('GET', '/admin/sites');
        self::assertStringContainsString('Coastal Insights', $sites->body);
        self::assertStringContainsString('Coastal Copy', $sites->body);
        $siteId = (int) $this->pdo->query("SELECT id FROM sites WHERE slug = 'coastal'")->fetchColumn();

        self::assertStringContainsString('Coastal Insights', $this->call('GET', '/admin/import')->body);

        $bundle = json_encode([
            'format' => 'coastal-export/1', 'origin' => 'https://www.coastalcopy.com',
            'topics' => [['id' => 1, 'title' => 'Thema Eins', 'sort_order' => 1]],
            'posts' => [['slug' => 'hallo', 'title' => 'Hallo', 'body_html' => '<p>Text</p>', 'status' => 'published', 'published_at' => '2026-01-02 10:00:00']],
            'recipients' => [['email' => 'r@coastal.test']], 'settings' => [], 'images' => [],
        ]);
        $tmp = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($tmp, $bundle);
        $import = fn (array $files) => $this->kernel->handle(new Request('POST', '/admin/import', [], ['_csrf' => $this->token(), 'site_id' => (string) $siteId], new Session($this->sessionData), '9.9.9.9', false, '', $files));

        $r = $import(['export' => ['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'name' => 'export.json', 'size' => strlen($bundle)]]);
        self::assertSame(200, $r->status);
        self::assertStringContainsString('Beiträge: 1 neu', $r->body);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM posts WHERE site_id = $siteId AND slug = 'hallo'")->fetchColumn());

        $again = $import(['export' => ['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'name' => 'export.json', 'size' => strlen($bundle)]]);
        self::assertStringContainsString('Beiträge: 0 neu, 1 vorhanden', $again->body);

        file_put_contents($tmp, 'kein json');
        self::assertSame(422, $import(['export' => ['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'name' => 'x', 'size' => 9]])->status);
        self::assertSame(422, $import([])->status);
        unlink($tmp);
    }

    public function testCustomerCannotUseAdminManagement(): void
    {
        $this->logout();
        $this->createUser('kunde@example.com');
        $this->call('POST', '/login', ['email' => 'kunde@example.com', 'password' => 'some long password']);
        foreach (['/admin/accounts/new', '/admin/sites', '/admin/import', '/admin/accounts/1'] as $path) {
            self::assertSame(403, $this->call('GET', $path)->status, $path);
        }
        self::assertSame(403, $this->call('POST', '/admin/accounts', ['account_name' => 'X'])->status);
    }
}
