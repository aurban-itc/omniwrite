<?php
declare(strict_types=1);

namespace OmniWrite\Repository;

use PDO;

final class PostRepository extends SiteScopedRepository
{
    public const STATUSES = ['draft', 'in_review', 'published', 'declined'];

    protected function table(): string
    {
        return 'posts';
    }

    protected function columns(): array
    {
        return [
            'title', 'slug', 'body_html', 'status', 'excerpt', 'category', 'keywords', 'meta_description',
            'cover_image', 'cover_alt', 'read_minutes', 'topic_id', 'published_at', 'updated_at',
        ];
    }

    /** @return list<array<string, mixed>> Liste ohne Volltext, neueste zuerst. */
    public function listSummaries(?string $status = null, int $limit = 100, int $offset = 0): array
    {
        $where = $this->scopedWhere($status === null ? '1 = 1' : 'status = :status');
        $stmt = $this->pdo->prepare(
            'SELECT id, title, slug, status, category, read_minutes, published_at, updated_at, created_at FROM posts WHERE ' . $where .
            ' ORDER BY id DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('site_id', $this->site->siteId, PDO::PARAM_INT);
        if ($status !== null) {
            $stmt->bindValue('status', $status);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function slugTaken(string $slug, int $exceptId = 0): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM posts WHERE ' . $this->scopedWhere('slug = :slug AND id <> :id'));
        $stmt->execute(['site_id' => $this->site->siteId, 'slug' => $slug, 'id' => $exceptId]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return list<string> Titel bereits vorhandener Posts (für spätere KI-Prompts, Dubletten vermeiden). */
    public function recentTitles(int $limit = 40): array
    {
        $stmt = $this->pdo->prepare('SELECT title FROM posts WHERE ' . $this->scopedWhere() . ' ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue('site_id', $this->site->siteId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
