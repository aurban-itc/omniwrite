<?php
declare(strict_types=1);

namespace OmniWrite\Repository;

use OmniWrite\Domain\SiteContext;
use PDO;

/** Nutzungsereignisse (KI-Aufrufe) einer Site. Die site_id kommt immer aus dem Kontext. */
final class UsageRepository
{
    public function __construct(private readonly PDO $pdo, private readonly SiteContext $site)
    {
    }

    /** @param array<string, mixed> $meta */
    public function insert(string $kind, array $meta, string $at): int
    {
        $this->pdo->prepare('INSERT INTO usage_events (site_id, kind, quantity, meta, created_at) VALUES (?, ?, 1, ?, ?)')
            ->execute([$this->site->siteId, $kind, $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $at]);
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM usage_events WHERE id = ? AND site_id = ?')->execute([$id, $this->site->siteId]);
    }

    public function sumSince(string $kind, string $since): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM usage_events WHERE site_id = ? AND kind = ? AND created_at >= ?');
        $stmt->execute([$this->site->siteId, $kind, $since]);
        return (int) $stmt->fetchColumn();
    }
}
