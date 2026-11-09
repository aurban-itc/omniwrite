<?php
declare(strict_types=1);

namespace OmniWrite\Repository;

use OmniWrite\Domain\User;
use PDO;

/** Globale Tabelle (nicht mandantengebunden): Nutzer gehören über account_users zu Accounts. */
final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function find(int $id): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? User::fromRow($row) : null;
    }

    /** @return array<string, mixed>|null Rohzeile inkl. password_hash (nur für Login). */
    public function findRowByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    public function emailExists(string $email): bool
    {
        return $this->findRowByEmail($email) !== null;
    }

    public function create(string $email, string $passwordHash, string $name, string $locale, string $status, bool $isSuperadmin = false): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (email, password_hash, name, locale, status, is_superadmin) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$email, $passwordHash, $name, $locale, $status, $isSuperadmin ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Gibt es schon einen Superadmin? Eine noch fehlende users-Tabelle (vor der ersten Migration) zählt als "nein". */
    public function hasSuperadmin(): bool
    {
        try {
            return (int) $this->pdo->query('SELECT COUNT(*) FROM users WHERE is_superadmin = 1')->fetchColumn() > 0;
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), "doesn't exist") || str_contains($e->getMessage(), 'no such table')) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Führt $fn unter einer benannten Datenbanksperre aus (MySQL/MariaDB), damit gleichzeitige Aufrufer nacheinander laufen.
     * Auf anderen Treibern (SQLite in den Tests) gibt es keine parallelen Verbindungen, dort läuft $fn direkt.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     * @throws \RuntimeException wenn die Sperre nicht innerhalb von 10 Sekunden zu bekommen ist
     */
    public function withLock(string $name, callable $fn): mixed
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return $fn();
        }
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, 10)');
        $stmt->execute([$name]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new \RuntimeException('Sperre nicht erhalten: ' . $name);
        }
        try {
            return $fn();
        } finally {
            $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
        }
    }

    public function setStatus(int $id, string $status): bool
    {
        $stmt = $this->pdo->prepare('UPDATE users SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
        return $stmt->rowCount() > 0;
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $id]);
    }

    public function touchLogin(int $id, string $when): void
    {
        $this->pdo->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([$when, $id]);
    }

    /** @return list<User> */
    public function pending(): array
    {
        $rows = $this->pdo->query("SELECT * FROM users WHERE status = 'pending' ORDER BY id")->fetchAll();
        return array_map(User::fromRow(...), $rows);
    }

    /** Aktive Nicht-Superadmins samt Account, für die Impersonation im Admin-Bereich. @return list<array{id: int, email: string, name: string, account: string}> */
    public function listImpersonable(): array
    {
        $rows = $this->pdo->query(
            "SELECT u.id, u.email, u.name, a.name AS account FROM users u
             JOIN account_users au ON au.user_id = u.id JOIN accounts a ON a.id = au.account_id
             WHERE u.is_superadmin = 0 AND u.status = 'active' ORDER BY a.id, u.id"
        )->fetchAll();
        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'email' => (string) $r['email'], 'name' => (string) $r['name'], 'account' => (string) $r['account']],
            $rows
        );
    }
}
