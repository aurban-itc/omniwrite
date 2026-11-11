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
}
