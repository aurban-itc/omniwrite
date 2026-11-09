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
            'cover_image', 'cover_alt', 'image_prompt', 'read_minutes', 'topic_id', 'published_at', 'updated_at',
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

    /** Wie update(), ändert aber nie einen veröffentlichten Beitrag (atomar, ohne Lücke zwischen Prüfen und Schreiben). */
    public function updateUnlessPublished(int $id, array $data): bool
    {
        $data = array_intersect_key($data, array_flip($this->columns()));
        if ($data === []) {
            return false;
        }
        $sets = array_map(static fn (string $c): string => "$c = :set_$c", array_keys($data));
        $params = ['id' => $id, 'site_id' => $this->site->siteId];
        foreach ($data as $col => $value) {
            $params["set_$col"] = $value;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE posts SET ' . implode(', ', $sets) . ' WHERE ' . $this->scopedWhere("id = :id AND status <> 'published'")
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
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
