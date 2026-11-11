<?php
declare(strict_types=1);

namespace OmniWrite\Repository;

use PDO;

/** Zustellstand je Ziel und Beitrag. Aufrufer prüfen vorher, dass das Ziel zur Site gehört; jede Abfrage trägt zusätzlich site_id. */
final class PushStateRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int, array<string, mixed>> post_id => Zeile */
    public function forTarget(int $siteId, int $targetId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM push_state WHERE site_id = :s AND target_id = :t');
        $stmt->execute(['s' => $siteId, 't' => $targetId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['post_id']] = $row;
        }
        return $out;
    }

    /** @param array<string, mixed> $data */
    public function save(int $siteId, int $targetId, int $postId, array $data): void
    {
        $cols = ['delivered_hash', 'attempt_hash', 'status', 'attempts', 'next_attempt_at', 'last_error', 'remote_ref', 'delivered_at'];
        $data = array_intersect_key($data, array_flip($cols));
        $params = ['s' => $siteId, 't' => $targetId, 'p' => $postId];
        $sets = [];
        foreach ($data as $c => $v) {
            $params["v_$c"] = $v;
            $sets[] = "$c = :v_$c";
        }
        $upd = $this->pdo->prepare('UPDATE push_state SET ' . implode(', ', $sets) . ' WHERE site_id = :s AND target_id = :t AND post_id = :p');
        $upd->execute($params);
        if ($upd->rowCount() > 0) {
            return;
        }
        $exists = $this->pdo->prepare('SELECT 1 FROM push_state WHERE site_id = :s AND target_id = :t AND post_id = :p');
        $exists->execute(['s' => $siteId, 't' => $targetId, 'p' => $postId]);
        if ($exists->fetchColumn() !== false) {
            return;   // Zeile vorhanden, nur nichts geändert
        }
        $ins = ['site_id' => $siteId, 'target_id' => $targetId, 'post_id' => $postId] + $data;
        $this->pdo->prepare('INSERT INTO push_state (' . implode(',', array_keys($ins)) . ') VALUES (:' . implode(',:', array_keys($ins)) . ')')->execute($ins);
    }

    /** Alle fehlgeschlagenen Zeilen eines Ziels sofort wieder zur Zustellung freigeben. */
    public function retryFailed(int $siteId, int $targetId): void
    {
        $this->pdo->prepare("UPDATE push_state SET attempt_hash = NULL, attempts = 0, next_attempt_at = NULL WHERE site_id = :s AND target_id = :t AND status = 'failed'")
            ->execute(['s' => $siteId, 't' => $targetId]);
    }

    /** @return array{ok: int, failed: int, pending: int} */
    public function counts(int $siteId, int $targetId): array
    {
        $stmt = $this->pdo->prepare('SELECT status, COUNT(*) AS n FROM push_state WHERE site_id = :s AND target_id = :t GROUP BY status');
        $stmt->execute(['s' => $siteId, 't' => $targetId]);
        $out = ['ok' => 0, 'failed' => 0, 'pending' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $k = $row['status'] === 'removed' ? 'ok' : $row['status'];
            if (isset($out[$k])) {
                $out[$k] += (int) $row['n'];
            }
        }
        return $out;
    }

    /** @return string letzte Fehlermeldung des Ziels ('' wenn keine offen) */
    public function lastError(int $siteId, int $targetId): string
    {
        $stmt = $this->pdo->prepare("SELECT last_error FROM push_state WHERE site_id = :s AND target_id = :t AND status = 'failed' AND last_error <> '' ORDER BY post_id DESC LIMIT 1");
        $stmt->execute(['s' => $siteId, 't' => $targetId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }
}
