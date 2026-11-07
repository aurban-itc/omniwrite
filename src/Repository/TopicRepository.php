<?php
declare(strict_types=1);

namespace OmniWrite\Repository;

use PDO;

final class TopicRepository extends SiteScopedRepository
{
    public const STATUSES = ['active', 'paused', 'suggested'];

    protected function table(): string
    {
        return 'topics';
    }

    protected function columns(): array
    {
        return ['title', 'keywords', 'category', 'notes', 'status', 'sort_order', 'times_used', 'last_used_at'];
    }

    /** @return list<array<string, mixed>> Rotation (ohne Vorschläge) in Reihenfolge. */
    public function rotation(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM topics WHERE ' . $this->scopedWhere("status <> 'suggested'") . ' ORDER BY sort_order, id');
        $stmt->execute(['site_id' => $this->site->siteId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function suggestions(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM topics WHERE ' . $this->scopedWhere("status = 'suggested'") . ' ORDER BY id');
        $stmt->execute(['site_id' => $this->site->siteId]);
        return $stmt->fetchAll();
    }

    public function maxSortOrder(): int
    {
        $stmt = $this->pdo->prepare('SELECT MAX(sort_order) FROM topics WHERE ' . $this->scopedWhere());
        $stmt->execute(['site_id' => $this->site->siteId]);
        return (int) $stmt->fetchColumn();
    }

    /** Nächster aktiver Eintrag nach dem Cursor (sort_order des zuletzt benutzten); null, wenn keiner aktiv ist. @return array<string, mixed>|null */
    public function nextActiveAfter(int $cursor): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM topics WHERE ' . $this->scopedWhere("status = 'active' AND sort_order > :cursor") . ' ORDER BY sort_order, id LIMIT 1'
        );
        $stmt->execute(['site_id' => $this->site->siteId, 'cursor' => $cursor]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    public function firstActive(): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM topics WHERE ' . $this->scopedWhere("status = 'active'") . ' ORDER BY sort_order, id LIMIT 1');
        $stmt->execute(['site_id' => $this->site->siteId]);
        return $stmt->fetch() ?: null;
    }

    /** Nachbar in Richtung $dir (-1 oben, +1 unten) innerhalb der Rotation. @return array<string, mixed>|null */
    public function neighbour(int $sortOrder, int $dir): ?array
    {
        $cmp = $dir < 0 ? '<' : '>';
        $order = $dir < 0 ? 'DESC' : 'ASC';
        $stmt = $this->pdo->prepare(
            'SELECT * FROM topics WHERE ' . $this->scopedWhere("status <> 'suggested' AND sort_order $cmp :so") . " ORDER BY sort_order $order, id $order LIMIT 1"
        );
        $stmt->execute(['site_id' => $this->site->siteId, 'so' => $sortOrder]);
        return $stmt->fetch() ?: null;
    }
}
