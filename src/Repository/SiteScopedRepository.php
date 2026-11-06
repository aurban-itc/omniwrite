<?php
declare(strict_types=1);

namespace OmniWrite\Repository;

use OmniWrite\Domain\SiteContext;
use PDO;

/**
 * Basis für alle mandantengebundenen Repositories. Erzwingt site_id in jeder Abfrage:
 * Unterklassen nutzen nur find/all/insert/update/delete dieser Klasse bzw. ergänzen
 * eigene Abfragen über scopedWhere(). Kein Roh-SQL in Controllern.
 */
abstract class SiteScopedRepository
{
    public function __construct(protected readonly PDO $pdo, protected readonly SiteContext $site)
    {
    }

    /** Tabellenname (Konstante der Unterklasse, nie Nutzereingabe). */
    abstract protected function table(): string;

    /** Erlaubte Spalten für insert/update (Whitelist). @return list<string> */
    abstract protected function columns(): array;

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE ' . $this->scopedWhere('id = :id')
        );
        $stmt->execute(['id' => $id, 'site_id' => $this->site->siteId]);
        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function all(int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE ' . $this->scopedWhere() .
            ' ORDER BY id DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('site_id', $this->site->siteId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @param array<string, mixed> $data @return int neue ID */
    public function insert(array $data): int
    {
        $data = $this->whitelist($data);
        $data['site_id'] = $this->site->siteId; // immer der Kontext, nie Eingabe
        $cols = array_keys($data);
        $sql = 'INSERT INTO ' . $this->table() . ' (' . implode(',', $cols) . ') VALUES (:' .
            implode(',:', $cols) . ')';
        $this->pdo->prepare($sql)->execute($data);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $data @return bool true, wenn eine Zeile dieser Site geändert wurde */
    public function update(int $id, array $data): bool
    {
        $data = $this->whitelist($data);
        if ($data === []) {
            return false;
        }
        $sets = array_map(static fn (string $c): string => "$c = :set_$c", array_keys($data));
        $params = ['id' => $id, 'site_id' => $this->site->siteId];
        foreach ($data as $col => $value) {
            $params["set_$col"] = $value;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE ' . $this->table() . ' SET ' . implode(', ', $sets) . ' WHERE ' . $this->scopedWhere('id = :id')
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM ' . $this->table() . ' WHERE ' . $this->scopedWhere('id = :id')
        );
        $stmt->execute(['id' => $id, 'site_id' => $this->site->siteId]);
        return $stmt->rowCount() > 0;
    }

    /** Hängt die Mandantenbedingung an (Parameter :site_id muss gebunden werden). */
    protected function scopedWhere(string $condition = '1 = 1'): string
    {
        return "site_id = :site_id AND ($condition)";
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function whitelist(array $data): array
    {
        return array_intersect_key($data, array_flip($this->columns()));
    }
}
