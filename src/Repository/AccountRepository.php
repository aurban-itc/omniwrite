<?php
declare(strict_types=1);

namespace OmniWrite\Repository;

use OmniWrite\Domain\Role;
use PDO;

/** Accounts (Kunden) und ihre Mitglieder. Mitgliedschaft entscheidet, welche Sites ein Nutzer sieht. */
final class AccountRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(string $name): int
    {
        $planId = $this->pdo->query("SELECT id FROM plans WHERE code = 'default'")->fetchColumn();
        $this->pdo->prepare('INSERT INTO accounts (name, plan_id) VALUES (?, ?)')
            ->execute([$name, $planId === false ? null : (int) $planId]);
        return (int) $this->pdo->lastInsertId();
    }

    public function addMember(int $accountId, int $userId, Role $role): void
    {
        $this->pdo->prepare('INSERT INTO account_users (account_id, user_id, role) VALUES (?, ?, ?)')
            ->execute([$accountId, $userId, $role->value]);
    }

    public function roleOf(int $accountId, int $userId): ?Role
    {
        $stmt = $this->pdo->prepare('SELECT role FROM account_users WHERE account_id = ? AND user_id = ?');
        $stmt->execute([$accountId, $userId]);
        $role = $stmt->fetchColumn();
        return $role === false ? null : Role::from((string) $role);
    }

    /** @return list<array{id: int, name: string, role: string}> */
    public function membershipsOf(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.name, au.role FROM account_users au
             JOIN accounts a ON a.id = au.account_id WHERE au.user_id = ? ORDER BY a.id'
        );
        $stmt->execute([$userId]);
        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'role' => (string) $r['role']],
            $stmt->fetchAll()
        );
    }

    /** Für das Superadmin-Dashboard. @return list<array{id: int, name: string, sites: int}> */
    public function allWithSiteCount(): array
    {
        $rows = $this->pdo->query(
            'SELECT a.id, a.name, (SELECT COUNT(*) FROM sites s WHERE s.account_id = a.id) AS sites
             FROM accounts a ORDER BY a.id'
        )->fetchAll();
        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'sites' => (int) $r['sites']],
            $rows
        );
    }
}
