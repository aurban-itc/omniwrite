<?php
declare(strict_types=1);

namespace OmniWrite\Repository;

/** Protokoll der Wochenläufe einer Site. */
final class RunRepository extends SiteScopedRepository
{
    protected function table(): string
    {
        return 'runs';
    }

    protected function columns(): array
    {
        return ['status', 'post_id', 'message', 'created_at'];
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 10): array
    {
        return $this->all($limit);
    }
}
