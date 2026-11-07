<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Support;

use OmniWrite\Auth\AuditLogger;
use OmniWrite\Domain\Role;
use OmniWrite\Domain\User;
use OmniWrite\Repository\AccountRepository;
use OmniWrite\Repository\SiteRepository;
use OmniWrite\Repository\UserRepository;
use OmniWrite\Service\ResolvedSite;
use OmniWrite\Service\SiteAccess;
use PDO;

/** Legt Mandanten für Service-Tests an: zwei Accounts mit je einer Site und Nutzern. */
final class Fixtures
{
    public AccountRepository $accounts;
    public UserRepository $users;
    public SiteRepository $sites;
    public AuditLogger $audit;
    public SiteAccess $access;

    public function __construct(public readonly PDO $pdo)
    {
        $this->accounts = new AccountRepository($pdo);
        $this->users = new UserRepository($pdo);
        $this->sites = new SiteRepository($pdo);
        $this->audit = new AuditLogger($pdo);
        $this->access = new SiteAccess($this->sites, $this->accounts);
    }

    public function user(string $email, bool $superadmin = false): User
    {
        return $this->users->find($this->users->create($email, 'x', ucfirst(strtok($email, '@')), 'de', 'active', $superadmin));
    }

    /** @return array{0: int, 1: string} Account-ID und Slug der neuen Site */
    public function accountWithSite(string $slug, string $locale = 'de'): array
    {
        $accountId = $this->accounts->create('Account ' . $slug);
        $this->sites->create($accountId, $slug, 'Site ' . $slug, $locale, 'Europe/Berlin');
        return [$accountId, $slug];
    }

    public function member(int $accountId, User $user, Role $role): void
    {
        $this->accounts->addMember($accountId, $user->id, $role);
    }

    public function resolve(User $user, string $slug): ResolvedSite
    {
        return $this->access->resolve($user, $slug);
    }
}
