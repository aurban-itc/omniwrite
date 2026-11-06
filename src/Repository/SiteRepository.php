<?php
declare(strict_types=1);

namespace OmniWrite\Repository;

use PDO;

/**
 * Sites gehören zu einem Account. Zugriff immer über accountId; die Berechtigung
 * (Mitgliedschaft) prüft der aufrufende Service. Alles unterhalb einer Site
 * (Posts, Themen, ...) läuft über SiteScopedRepository.
 */
final class SiteRepository
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
}
