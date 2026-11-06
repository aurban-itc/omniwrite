<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Service;

use OmniWrite\Auth\AuditLogger;
use OmniWrite\Domain\AccessDeniedException;
use OmniWrite\Domain\Role;
use OmniWrite\Domain\User;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Repository\AccountRepository;
use OmniWrite\Repository\SiteRepository;
use OmniWrite\Repository\UserRepository;
use OmniWrite\Service\SiteService;
use OmniWrite\Tests\Support\DbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SiteServiceTest extends DbTestCase
{
    private SiteService $service;
    private AccountRepository $accounts;
    private UserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounts = new AccountRepository($this->pdo);
        $this->users = new UserRepository($this->pdo);
        $this->service = new SiteService(new SiteRepository($this->pdo), $this->accounts, new AuditLogger($this->pdo));
    }

    /** @return array{User, int} Nutzer und Account */
    private function member(string $email, Role $role, ?int $accountId = null): array
    {
        $uid = $this->users->create($email, 'x', 'N', 'de', 'active');
        $accountId ??= $this->accounts->create('Acc ' . $email);
        $this->accounts->addMember($accountId, $uid, $role);
        return [$this->users->find($uid), $accountId];
    }

    /** @return array<string, string> */
    private function site(string $slug = 'my-blog'): array
    {
        return ['name' => 'My Blog', 'slug' => $slug, 'locale' => 'de', 'timezone' => 'Europe/Berlin'];
    }

    public function testOwnerCreatesAndListsSite(): void
    {
        [$owner, $acc] = $this->member('o@example.com', Role::Owner);
        $this->service->create($owner, $acc, $this->site());
        $list = $this->service->listFor($owner, $acc);
        self::assertCount(1, $list);
        self::assertSame('my-blog', $list[0]['slug']);
    }

    public function testEditorCannotCreateButCanList(): void
    {
        [$owner, $acc] = $this->member('o@example.com', Role::Owner);
        [$editor] = $this->member('e@example.com', Role::Editor, $acc);
        $this->service->create($owner, $acc, $this->site());
        self::assertCount(1, $this->service->listFor($editor, $acc));
        $this->expectException(AccessDeniedException::class);
        $this->service->create($editor, $acc, $this->site('other'));
    }

    public function testOtherAccountHasNoAccess(): void
    {
        [$owner, $accA] = $this->member('a@example.com', Role::Owner);
        [$stranger] = $this->member('b@example.com', Role::Owner);
        $this->service->create($owner, $accA, $this->site());
        try {
            $this->service->listFor($stranger, $accA);
            self::fail('Zugriff auf fremden Account muss scheitern');
        } catch (AccessDeniedException) {
        }
        $this->expectException(AccessDeniedException::class);
        $this->service->create($stranger, $accA, $this->site('hijack'));
    }

    public function testSuperadminSeesAnyAccount(): void
    {
        [$owner, $acc] = $this->member('a@example.com', Role::Owner);
        $this->service->create($owner, $acc, $this->site());
        $rootId = $this->users->create('root@example.com', 'x', 'Root', 'de', 'active', true);
        self::assertCount(1, $this->service->listFor($this->users->find($rootId), $acc));
    }

    #[DataProvider('badSlugs')]
    public function testSlugValidation(string $slug, string $error): void
    {
        [$owner, $acc] = $this->member('o@example.com', Role::Owner);
        $this->service->create($owner, $acc, $this->site('taken-one'));
        try {
            $this->service->create($owner, $acc, $this->site($slug));
            self::fail('ValidationException erwartet');
        } catch (ValidationException $e) {
            self::assertSame($error, $e->errors['slug']);
        }
    }

    /** @return array<string, array{string, string}> */
    public static function badSlugs(): array
    {
        return [
            'zu kurz' => ['ab', 'err.slug_invalid'],
            'Sonderzeichen' => ['no_underscore', 'err.slug_invalid'],
            'Bindestrich am Rand' => ['-abc', 'err.slug_invalid'],
            'reserviert' => ['admin', 'err.slug_reserved'],
            'vergeben' => ['taken-one', 'err.slug_taken'],
        ];
    }

    public function testInvalidLocaleAndTimezone(): void
    {
        [$owner, $acc] = $this->member('o@example.com', Role::Owner);
        try {
            $this->service->create($owner, $acc, ['name' => 'x', 'slug' => 'abc', 'locale' => 'fr', 'timezone' => 'Mars/Base']);
            self::fail('ValidationException erwartet');
        } catch (ValidationException $e) {
            self::assertSame(['locale', 'timezone'], array_keys($e->errors));
        }
    }

    public function testRaceOnUniqueSlugBecomesValidationError(): void
    {
        [$owner, $acc] = $this->member('o@example.com', Role::Owner);
        $this->service->create($owner, $acc, $this->site('race-slug'));

        // Simuliert: Vorabprüfung sagt "frei", der Unique-Index schlägt beim Insert trotzdem zu.
        $racing = new class ($this->pdo) extends SiteRepository {
            private int $calls = 0;

            public function slugExists(string $slug): bool
            {
                return $this->calls++ > 0;
            }
        };
        $service = new SiteService($racing, $this->accounts, new AuditLogger($this->pdo));
        try {
            $service->create($owner, $acc, $this->site('race-slug'));
            self::fail('ValidationException erwartet');
        } catch (ValidationException $e) {
            self::assertSame('err.slug_taken', $e->errors['slug']);
        }
    }
}
