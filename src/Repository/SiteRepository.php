<?php
declare(strict_types=1);

namespace OmniWrite\Repository;

use PDO;

/**
 * Sites gehören zu einem Account. Zugriff immer über accountId; die Berechtigung
 * (Mitgliedschaft) prüft der aufrufende Service. Alles unterhalb einer Site
 * (Posts, Themen, ...) läuft über SiteScopedRepository.
 */
class SiteRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function forAccount(int $accountId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sites WHERE account_id = ? ORDER BY id');
        $stmt->execute([$accountId]);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sites WHERE slug = ?');
        $stmt->execute([$slug]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sites WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function slugExists(string $slug): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM sites WHERE slug = ?');
        $stmt->execute([$slug]);
        return $stmt->fetchColumn() !== false;
    }

    public function create(int $accountId, string $slug, string $name, string $locale, string $timezone): int
    {
        $this->pdo->prepare(
            'INSERT INTO sites (account_id, slug, name, locale, timezone) VALUES (?, ?, ?, ?, ?)'
        )->execute([$accountId, $slug, $name, $locale, $timezone]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Alle Sites mit Account-Name und Zahl der Beiträge, für den Superadmin. @return list<array<string, mixed>> */
    public function allWithAccount(): array
    {
        return $this->pdo->query(
            'SELECT s.*, a.name AS account_name,
                    (SELECT COUNT(*) FROM posts p WHERE p.site_id = s.id) AS post_count,
                    (SELECT COUNT(*) FROM posts p WHERE p.site_id = s.id AND p.status = \'published\') AS published_count
             FROM sites s JOIN accounts a ON a.id = s.account_id ORDER BY a.id, s.id'
        )->fetchAll();
    }
}
