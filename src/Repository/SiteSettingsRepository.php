<?php
declare(strict_types=1);

namespace OmniWrite\Repository;

use OmniWrite\Domain\SiteContext;
use PDO;

/** Schlüssel/Wert-Einstellungen einer Site. Die site_id kommt immer aus dem Kontext. */
final class SiteSettingsRepository
{
    public function __construct(private readonly PDO $pdo, private readonly SiteContext $site)
    {
    }

    public function get(string $key, string $default = ''): string
    {
        $stmt = $this->pdo->prepare('SELECT v FROM site_settings WHERE site_id = ? AND k = ?');
        $stmt->execute([$this->site->siteId, $key]);
        $v = $stmt->fetchColumn();
        return $v === false ? $default : (string) $v;
    }

    public function set(string $key, string $value): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM site_settings WHERE site_id = ? AND k = ?')->execute([$this->site->siteId, $key]);
            $this->pdo->prepare('INSERT INTO site_settings (site_id, k, v) VALUES (?, ?, ?)')->execute([$this->site->siteId, $key, $value]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function delete(string $key): void
    {
        $this->pdo->prepare('DELETE FROM site_settings WHERE site_id = ? AND k = ?')->execute([$this->site->siteId, $key]);
    }
}
