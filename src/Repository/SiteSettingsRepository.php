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

    /**
     * Setzt den Wert nur, wenn er sich ändert; true für genau einen von mehreren gleichzeitigen Aufrufern (Wochenlauf).
     */
    public function claim(string $key, string $value): bool
    {
        $stmt = $this->pdo->prepare('UPDATE site_settings SET v = ? WHERE site_id = ? AND k = ? AND v <> ?');
        $stmt->execute([$value, $this->site->siteId, $key, $value]);
        if ($stmt->rowCount() > 0) {
            return true;
        }
        $exists = $this->pdo->prepare('SELECT 1 FROM site_settings WHERE site_id = ? AND k = ?');
        $exists->execute([$this->site->siteId, $key]);
        if ($exists->fetchColumn() !== false) {
            return false; // hat schon diesen Wert
        }
        try {
            $this->pdo->prepare('INSERT INTO site_settings (site_id, k, v) VALUES (?, ?, ?)')->execute([$this->site->siteId, $key, $value]);
            return true;
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return false; // ein paralleler Lauf war schneller
            }
            throw $e;
        }
    }
}
