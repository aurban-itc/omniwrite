<?php
declare(strict_types=1);

namespace OmniWrite\Repository;

/** Push-Ziele (Webhook, GitHub) einer Site. config_enc ist mit SecretBox verschlüsselt. */
final class PushTargetRepository extends SiteScopedRepository
{
    protected function table(): string
    {
        return 'push_targets';
    }

    protected function columns(): array
    {
        return ['kind', 'name', 'config_enc', 'active'];
    }

    /** @return list<array<string, mixed>> */
    public function listAll(bool $activeOnly = false): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM push_targets WHERE ' . $this->scopedWhere($activeOnly ? 'active = 1' : '1 = 1') . ' ORDER BY id');
        $stmt->execute(['site_id' => $this->site->siteId]);
        return $stmt->fetchAll();
    }

    /** Sperre für einen Abgleich setzen (verhindert doppelte Zustellung bei überlappendem Cron/„Jetzt senden“). false = läuft bereits. */
    public function claim(int $id, \DateTimeImmutable $now, int $seconds = 900): bool
    {
        $stmt = $this->pdo->prepare('UPDATE push_targets SET locked_until = :until WHERE id = :id AND site_id = :site_id AND (locked_until IS NULL OR locked_until < :now)');
        $stmt->execute([
            'until' => $now->modify("+$seconds seconds")->format('Y-m-d H:i:s'),
            'now' => $now->format('Y-m-d H:i:s'),
            'id' => $id, 'site_id' => $this->site->siteId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function release(int $id): void
    {
        $this->pdo->prepare('UPDATE push_targets SET locked_until = NULL WHERE id = :id AND site_id = :site_id')->execute(['id' => $id, 'site_id' => $this->site->siteId]);
    }
}
