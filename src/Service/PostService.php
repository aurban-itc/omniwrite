<?php
declare(strict_types=1);

namespace OmniWrite\Service;

use OmniWrite\Auth\AuditLogger;
use OmniWrite\Domain\AccessDeniedException;
use OmniWrite\Domain\NotFoundException;
use OmniWrite\Domain\User;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Repository\PostRepository;
use OmniWrite\Support\Html;
use OmniWrite\Support\Slugger;
use PDO;
use PDOException;

/** Beiträge einer Site: Anlegen, Bearbeiten, Statuswechsel. Veröffentlicht wird nur durch eine bewusste Aktion eines Owners. */
final class PostService
{
    /** Erlaubte Übergänge: Aktion => [von-Status..., nach-Status, braucht Veröffentlichungsrecht] */
    private const TRANSITIONS = [
        'submit' => [['draft', 'declined'], 'in_review', false],
        'publish' => [['draft', 'in_review', 'declined'], 'published', true],
        'decline' => [['draft', 'in_review'], 'declined', true],
        'unpublish' => [['published'], 'draft', true],
    ];

    public function __construct(private readonly PDO $pdo, private readonly AuditLogger $audit)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(ResolvedSite $s, ?string $status = null): array
    {
        return $this->repo($s)->listSummaries($status !== null && in_array($status, PostRepository::STATUSES, true) ? $status : null);
    }

    /** @return array<string, mixed> */
    public function get(ResolvedSite $s, int $id): array
    {
        return $this->repo($s)->find($id) ?? throw new NotFoundException('Beitrag nicht gefunden');
    }

    /**
     * Legt an (id = 0) oder aktualisiert. Status ändert sich dabei nie.
     *
     * @param array<string, mixed> $in title, body_html, slug, excerpt, category, keywords, meta_description, cover_image, cover_alt
     * @return int Post-ID
     */
    public function save(ResolvedSite $s, User $actor, int $id, array $in, ?string $ip = null): int
    {
        $this->requireWrite($s);
        $repo = $this->repo($s);
        $existing = $id > 0 ? ($repo->find($id) ?? throw new NotFoundException('Beitrag nicht gefunden')) : null;

        $title = trim((string) ($in['title'] ?? ''));
        $body = Html::sanitize((string) ($in['body_html'] ?? ''));
        $errors = [];
        if ($title === '' || mb_strlen($title) > 255) {
            $errors['title'] = 'err.title_required';
        }
        if (Html::text($body) === '') {
            $errors['body_html'] = 'err.body_empty';
        }
        $cover = trim((string) ($in['cover_image'] ?? ''));
        if ($cover !== '' && (mb_strlen($cover) > 500 || !Html::safeUrl($cover, true))) {
            $errors['cover_image'] = 'err.cover_invalid';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $excerpt = trim((string) ($in['excerpt'] ?? ''));
        if ($excerpt === '') {
            $excerpt = mb_substr(Html::text($body), 0, 300);
        }
        $meta = trim((string) ($in['meta_description'] ?? ''));
        if ($meta === '') {
            $meta = mb_substr($excerpt, 0, 155);
        }
        $data = [
            'title' => $title,
            'body_html' => $body,
            'excerpt' => mb_substr($excerpt, 0, 600),
            'category' => mb_substr(trim((string) ($in['category'] ?? '')), 0, 80),
            'keywords' => mb_substr(trim((string) ($in['keywords'] ?? '')), 0, 400),
            'meta_description' => mb_substr($meta, 0, 320),
            'cover_image' => $cover,
            'cover_alt' => mb_substr(trim((string) ($in['cover_alt'] ?? '')), 0, 255),
            'read_minutes' => Html::readMinutes($body),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
        $wanted = trim((string) ($in['slug'] ?? ''));
        // Bestehende Beiträge behalten ihre Adresse, solange sie nicht ausdrücklich geändert wird.
        $baseSlug = Slugger::slugify($wanted !== '' ? $wanted : ($existing['slug'] ?? $title));

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $data['slug'] = $this->uniqueSlug($repo, $baseSlug, $id);
            try {
                if ($existing === null) {
                    $id = $repo->insert($data + ['status' => 'draft']);
                } else {
                    $repo->update($id, $data);
                }
                break;
            } catch (PDOException $e) {
                // Parallele Anfrage hat denselben Slug zuerst belegt: neu versuchen.
                if ($e->getCode() !== '23000' || $attempt === 4) {
                    throw $e;
                }
            }
        }
        $this->audit->log($existing === null ? 'post.create' : 'post.update', $actor->id, $s->accountId(), ['site_id' => $s->id(), 'post_id' => $id], $ip);
        return $id;
    }

    public function transition(ResolvedSite $s, User $actor, int $id, string $action, ?string $ip = null): string
    {
        $rule = self::TRANSITIONS[$action] ?? throw new ValidationException(['status' => 'err.status_transition']);
        [$from, $to, $needsPublish] = $rule;
        $needsPublish ? $this->requirePublish($s) : $this->requireWrite($s);
        $post = $this->get($s, $id);
        if (!in_array($post['status'], $from, true)) {
            throw new ValidationException(['status' => 'err.status_transition']);
        }
        $data = ['status' => $to, 'updated_at' => gmdate('Y-m-d H:i:s')];
        if ($to === 'published' && empty($post['published_at'])) {
            $data['published_at'] = gmdate('Y-m-d H:i:s');
        }
        $this->repo($s)->update($id, $data);
        $this->audit->log('post.' . $action, $actor->id, $s->accountId(), ['site_id' => $s->id(), 'post_id' => $id], $ip);
        return $to;
    }

    public function delete(ResolvedSite $s, User $actor, int $id, ?string $ip = null): void
    {
        $this->requirePublish($s);
        $post = $this->get($s, $id);
        if ($post['status'] === 'published') {
            throw new ValidationException(['status' => 'err.delete_published']);
        }
        $this->repo($s)->delete($id);
        $this->audit->log('post.delete', $actor->id, $s->accountId(), ['site_id' => $s->id(), 'post_id' => $id], $ip);
    }

    private function uniqueSlug(PostRepository $repo, string $base, int $exceptId): string
    {
        $slug = $base;
        for ($n = 2; $repo->slugTaken($slug, $exceptId); $n++) {
            $slug = substr($base, 0, 80 - strlen((string) $n) - 1) . '-' . $n;
        }
        return $slug;
    }

    private function repo(ResolvedSite $s): PostRepository
    {
        return new PostRepository($this->pdo, $s->context);
    }

    private function requireWrite(ResolvedSite $s): void
    {
        if (!$s->canWrite()) {
            throw new AccessDeniedException('Keine Schreibrechte');
        }
    }

    private function requirePublish(ResolvedSite $s): void
    {
        if (!$s->canPublish()) {
            throw new AccessDeniedException('Nur Owner dürfen freigeben, ablehnen oder löschen');
        }
    }
}
