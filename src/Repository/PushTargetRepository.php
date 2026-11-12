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

    /** Sperre für einen Abgleich setzen (verhindert doppelte Zustellung bei überlappendem Cron/„Jetzt senden“). @return string|null Token der Sperre, null = läuft bereits */
    public function claim(int $id, \DateTimeImmutable $now, int $seconds = 120): ?string
    {
        $token = bin2hex(random_bytes(16));
        $stmt = $this->pdo->prepare('UPDATE push_targets SET locked_until = :until, lock_token = :token WHERE id = :id AND site_id = :site_id AND (locked_until IS NULL OR locked_until < :now)');
        $stmt->execute([
            'until' => $now->modify("+$seconds seconds")->format('Y-m-d H:i:s'), 'token' => $token,
            'now' => $now->format('Y-m-d H:i:s'), 'id' => $id, 'site_id' => $this->site->siteId,
        ]);
        return $stmt->rowCount() > 0 ? $token : null;
    }

    /** Sperre verlängern (vor jeder Zustellung). false = Sperre wurde inzwischen von einem anderen Lauf übernommen. */
    public function renew(int $id, string $token, \DateTimeImmutable $now, int $seconds = 120): bool
    {
        $stmt = $this->pdo->prepare('UPDATE push_targets SET locked_until = :until WHERE id = :id AND site_id = :site_id AND lock_token = :token');
        $stmt->execute(['until' => $now->modify("+$seconds seconds")->format('Y-m-d H:i:s'), 'id' => $id, 'site_id' => $this->site->siteId, 'token' => $token]);
        return $stmt->rowCount() > 0 || $this->tokenIs($id, $token);
    }

    public function release(int $id, string $token): void
    {
        $this->pdo->prepare('UPDATE push_targets SET locked_until = NULL, lock_token = NULL WHERE id = :id AND site_id = :site_id AND lock_token = :token')
            ->execute(['id' => $id, 'site_id' => $this->site->siteId, 'token' => $token]);
    }

    private function tokenIs(int $id, string $token): bool
    {
        $stmt = $this->pdo->prepare('SELECT lock_token FROM push_targets WHERE id = :id AND site_id = :site_id');
        $stmt->execute(['id' => $id, 'site_id' => $this->site->siteId]);
        return $stmt->fetchColumn() === $token;
    }
}
