<?php
declare(strict_types=1);

namespace OmniWrite\Repository;

use PDO;

/** Empfänger der Review-Mail einer Site. */
final class RecipientRepository extends SiteScopedRepository
{
    protected function table(): string
    {
        return 'recipients';
    }

    protected function columns(): array
    {
        return ['email', 'name', 'active'];
    }

    /** @return list<array<string, mixed>> */
    public function listAll(bool $activeOnly = false): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM recipients WHERE ' . $this->scopedWhere($activeOnly ? 'active = 1' : '1 = 1') . ' ORDER BY email');
        $stmt->execute(['site_id' => $this->site->siteId]);
        return $stmt->fetchAll();
    }

    public function emailTaken(string $email): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM recipients WHERE ' . $this->scopedWhere('email = :email'));
        $stmt->execute(['site_id' => $this->site->siteId, 'email' => $email]);
        return $stmt->fetchColumn() !== false;
    }
}
