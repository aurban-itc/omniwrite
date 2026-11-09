<?php
declare(strict_types=1);

namespace OmniWrite\Repository;

use PDO;

/**
 * Eigene Domains der Sites. Der Host ist global eindeutig und wird beim Aufruf nachgeschlagen, bevor die Site feststeht,
 * deshalb ist dieses Repository bewusst nicht an einen SiteContext gebunden; Schreibzugriffe nennen die site_id ausdrücklich.
 */
final class DomainRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function forSite(int $siteId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM domains WHERE site_id = ? ORDER BY id LIMIT 1');
        $stmt->execute([$siteId]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    public function findByHost(string $host): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM domains WHERE host = ?');
        $stmt->execute([$host]);
        return $stmt->fetch() ?: null;
    }

    /** Die Site hinter einem verifizierten Host (nur verifizierte Hosts liefern aus). @return array<string, mixed>|null */
    public function verifiedSiteByHost(string $host): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.* FROM domains d JOIN sites s ON s.id = d.site_id WHERE d.host = ? AND d.verified_at IS NOT NULL'
        );
        $stmt->execute([$host]);
        return $stmt->fetch() ?: null;
    }

    /** Ersetzt die Domain der Site durch einen neuen, unverifizierten Eintrag; ein unverifizierter Anspruch einer anderen Site auf denselben Host weicht. */
    public function replaceForSite(int $siteId, string $host): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM domains WHERE site_id = ?')->execute([$siteId]);
            $this->pdo->prepare('DELETE FROM domains WHERE host = ? AND verified_at IS NULL')->execute([$host]);
            $this->pdo->prepare('INSERT INTO domains (site_id, host) VALUES (?, ?)')->execute([$siteId, $host]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function markVerified(int $siteId, string $now): void
    {
        $this->pdo->prepare('UPDATE domains SET verified_at = ? WHERE site_id = ?')->execute([$now, $siteId]);
    }

    public function deleteForSite(int $siteId): void
    {
        $this->pdo->prepare('DELETE FROM domains WHERE site_id = ?')->execute([$siteId]);
    }
}
