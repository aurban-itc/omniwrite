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

    /** Beiträge pro Monat laut Plan des Accounts; null = unbegrenzt (auch ohne Plan). */
    public function postsPerMonth(int $accountId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.posts_per_month FROM accounts a LEFT JOIN plans p ON p.id = a.plan_id WHERE a.id = ?'
        );
        $stmt->execute([$accountId]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? null : (int) $value;
    }

    /** @return array{id: int, name: string}|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name FROM accounts WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? ['id' => (int) $row['id'], 'name' => (string) $row['name']] : null;
    }

    public function rename(int $id, string $name): void
    {
        $this->pdo->prepare('UPDATE accounts SET name = ? WHERE id = ?')->execute([$name, $id]);
    }

    /** @return list<array{user_id: int, email: string, name: string, status: string, role: string}> */
    public function members(int $accountId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.email, u.name, u.status, au.role FROM account_users au
             JOIN users u ON u.id = au.user_id WHERE au.account_id = ? ORDER BY au.role, u.id'
        );
        $stmt->execute([$accountId]);
        return array_map(
            static fn (array $r): array => ['user_id' => (int) $r['id'], 'email' => (string) $r['email'], 'name' => (string) $r['name'], 'status' => (string) $r['status'], 'role' => (string) $r['role']],
            $stmt->fetchAll()
        );
    }

    public function ownerCount(int $accountId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM account_users WHERE account_id = ? AND role = 'owner'");
        $stmt->execute([$accountId]);
        return (int) $stmt->fetchColumn();
    }

    public function setRole(int $accountId, int $userId, Role $role): void
    {
        $this->pdo->prepare('UPDATE account_users SET role = ? WHERE account_id = ? AND user_id = ?')
            ->execute([$role->value, $accountId, $userId]);
    }

    public function removeMember(int $accountId, int $userId): void
    {
        $this->pdo->prepare('DELETE FROM account_users WHERE account_id = ? AND user_id = ?')->execute([$accountId, $userId]);
    }
}
