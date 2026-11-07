<?php
declare(strict_types=1);

namespace OmniWrite\Service;

use OmniWrite\Auth\AuditLogger;
use OmniWrite\Domain\AccessDeniedException;
use OmniWrite\Domain\NotFoundException;
use OmniWrite\Domain\User;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Repository\SiteSettingsRepository;
use OmniWrite\Repository\TopicRepository;
use PDO;

/**
 * Themenliste einer Site. Die Rotation arbeitet die aktiven Themen von oben nach unten ab und beginnt danach
 * wieder oben; der Cursor (sort_order des zuletzt benutzten Themas) liegt in den Site-Einstellungen.
 */
final class TopicService
{
    public const CURSOR_KEY = 'topic_cursor';

    public function __construct(private readonly PDO $pdo, private readonly AuditLogger $audit)
    {
    }

    /** @return array{rotation: list<array<string, mixed>>, suggestions: list<array<string, mixed>>, next_id: ?int} */
    public function overview(ResolvedSite $s): array
    {
        $repo = $this->repo($s);
        $next = $this->nextFor($s);
        return ['rotation' => $repo->rotation(), 'suggestions' => $repo->suggestions(), 'next_id' => $next === null ? null : (int) $next['id']];
    }

    /** @return array<string, mixed> */
    public function get(ResolvedSite $s, int $id): array
    {
        return $this->repo($s)->find($id) ?? throw new NotFoundException('Thema nicht gefunden');
    }

    /** @param array<string, mixed> $in title, keywords, category, notes */
    public function add(ResolvedSite $s, User $actor, array $in, string $status = 'active', ?string $ip = null): int
    {
        $this->requireWrite($s);
        $data = $this->validated($in);
        $repo = $this->repo($s);
        $id = $repo->insert($data + ['status' => $status === 'suggested' ? 'suggested' : 'active', 'sort_order' => $repo->maxSortOrder() + 1]);
        $this->audit->log('topic.create', $actor->id, $s->accountId(), ['site_id' => $s->id(), 'topic_id' => $id], $ip);
        return $id;
    }

    /** @param array<string, mixed> $in */
    public function update(ResolvedSite $s, User $actor, int $id, array $in, ?string $ip = null): void
    {
        $this->requireWrite($s);
        $this->get($s, $id);
        $this->repo($s)->update($id, $this->validated($in));
        $this->audit->log('topic.update', $actor->id, $s->accountId(), ['site_id' => $s->id(), 'topic_id' => $id], $ip);
    }

    /** @param 'pause'|'resume'|'accept' $action */
    public function setStatus(ResolvedSite $s, int $id, string $action): void
    {
        $this->requireWrite($s);
        $this->get($s, $id);
        $this->repo($s)->update($id, ['status' => $action === 'pause' ? 'paused' : 'active']);
    }

    public function delete(ResolvedSite $s, User $actor, int $id, ?string $ip = null): void
    {
        $this->requireWrite($s);
        $this->get($s, $id);
        $this->repo($s)->delete($id);
        $this->audit->log('topic.delete', $actor->id, $s->accountId(), ['site_id' => $s->id(), 'topic_id' => $id], $ip);
    }

    /** Tauscht die Position mit dem Nachbarn. $dir: -1 nach oben, +1 nach unten. */
    public function move(ResolvedSite $s, int $id, int $dir): void
    {
        $this->requireWrite($s);
        $topic = $this->get($s, $id);
        $repo = $this->repo($s);
        $nb = $repo->neighbour((int) $topic['sort_order'], $dir < 0 ? -1 : 1);
        if ($nb === null) {
            return;
        }
        $this->pdo->beginTransaction();
        try {
            $repo->update($id, ['sort_order' => (int) $nb['sort_order']]);
            $repo->update((int) $nb['id'], ['sort_order' => (int) $topic['sort_order']]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Das Thema, das die Rotation als Nächstes nimmt (null, wenn kein Thema aktiv ist). @return array<string, mixed>|null */
    public function nextFor(ResolvedSite $s): ?array
    {
        $repo = $this->repo($s);
        $cursor = (int) $this->settings($s)->get(self::CURSOR_KEY, '0');
        return $repo->nextActiveAfter($cursor) ?? $repo->firstActive();
    }

    /** Verbucht die Nutzung eines Themas; $advance rückt die Rotation weiter (Wochenlauf), manuelles Schreiben tut das nicht. */
    public function markUsed(ResolvedSite $s, int $id, bool $advance): void
    {
        $topic = $this->get($s, $id);
        $this->repo($s)->update($id, ['times_used' => (int) $topic['times_used'] + 1, 'last_used_at' => gmdate('Y-m-d H:i:s')]);
        if ($advance) {
            $this->settings($s)->set(self::CURSOR_KEY, (string) (int) $topic['sort_order']);
        }
    }

    /**
     * @param array<string, mixed> $in
     * @return array{title: string, keywords: string, category: string, notes: string}
     */
    private function validated(array $in): array
    {
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 255) {
            throw new ValidationException(['title' => 'err.title_required']);
        }
        return [
            'title' => $title,
            'keywords' => mb_substr(trim((string) ($in['keywords'] ?? '')), 0, 400),
            'category' => mb_substr(trim((string) ($in['category'] ?? '')), 0, 80),
            'notes' => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 20000),
        ];
    }

    private function repo(ResolvedSite $s): TopicRepository
    {
        return new TopicRepository($this->pdo, $s->context);
    }

    private function settings(ResolvedSite $s): SiteSettingsRepository
    {
        return new SiteSettingsRepository($this->pdo, $s->context);
    }

    private function requireWrite(ResolvedSite $s): void
    {
        if (!$s->canWrite()) {
            throw new AccessDeniedException('Keine Schreibrechte');
        }
    }
}
