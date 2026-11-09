<?php
declare(strict_types=1);

namespace OmniWrite\Service;

use OmniWrite\Ai\AiException;
use OmniWrite\Ai\ContentAi;
use OmniWrite\Ai\ImageModel;
use OmniWrite\Auth\AuditLogger;
use OmniWrite\Domain\AccessDeniedException;
use OmniWrite\Domain\User;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Repository\PostRepository;
use OmniWrite\Repository\TopicRepository;
use OmniWrite\Support\ImageStore;
use PDO;
use RuntimeException;
use Throwable;

/**
 * KI-Funktionen für eine Site. Jeder Aufruf: Rechte prüfen, Nutzung vorab buchen (Limits), Anbieter fragen,
 * bei Fehler die Buchung zurücknehmen. Ergebnisse sind immer Entwürfe; veröffentlicht wird nur durch einen Owner.
 */
final class AiService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly ContentAi $content,
        private readonly ImageModel $images,
        private readonly ImageStore $store,
        private readonly PostService $posts,
        private readonly TopicService $topics,
        private readonly SiteSettingsService $settings,
        private readonly UsageService $usage,
    ) {
    }

    public function textReady(): bool
    {
        return $this->content->isConfigured();
    }

    public function imageReady(): bool
    {
        return $this->images->isConfigured();
    }

    /**
     * Schreibt einen Entwurf zu einem Thema aus der Liste (topic_id) oder zu einem freien Thema (title, keywords, notes).
     *
     * @param array<string, mixed> $in topic_id | title, keywords, notes; extra
     * @return int Post-ID
     * @throws AiException|UsageLimitException|ValidationException
     */
    public function generateDraft(ResolvedSite $s, User $actor, array $in, ?string $ip = null): int
    {
        $this->requireReady($s, $this->content->isConfigured());
        $topicId = (int) ($in['topic_id'] ?? 0);
        if ($topicId > 0) {
            $topic = $this->topics->get($s, $topicId);
        } else {
            $title = trim((string) ($in['title'] ?? ''));
            if ($title === '' || mb_strlen($title) > 255) {
                throw new ValidationException(['title' => 'err.title_required']);
            }
            $topic = ['title' => $title, 'keywords' => (string) ($in['keywords'] ?? ''), 'category' => '', 'notes' => mb_substr((string) ($in['notes'] ?? ''), 0, 20000)];
        }
        $extra = mb_substr(trim((string) ($in['extra'] ?? '')), 0, 2000);

        $eventId = $this->usage->reserve($s, 'ai_post', ['user_id' => $actor->id, 'topic_id' => $topicId ?: null]);
        try {
            $titles = (new PostRepository($this->pdo, $s->context))->recentTitles();
            $fields = $this->content->generatePost($this->siteInfo($s), $topic, $extra, $titles);
            if ($fields['category'] === '' && trim((string) ($topic['category'] ?? '')) !== '') {
                $fields['category'] = (string) $topic['category'];
            }
            $postId = $this->posts->save($s, $actor, 0, $fields, $ip);
        } catch (Throwable $e) {
            $this->usage->release($s, $eventId);
            throw $e;
        }
        if ($topicId > 0) {
            try {
                $this->topics->markUsed($s, $topicId, false);
            } catch (\OmniWrite\Domain\NotFoundException) {
                // Thema wurde während der Erzeugung gelöscht: der fertige Entwurf bleibt erreichbar.
            }
        }
        $this->audit->log('ai.generate_post', $actor->id, $s->accountId(), ['site_id' => $s->id(), 'post_id' => $postId], $ip);
        return $postId;
    }

    /** Überarbeitet Text laut Anweisung. Speichert nichts; der Editor übernimmt das Ergebnis erst beim Speichern. */
    public function revise(ResolvedSite $s, User $actor, string $title, string $bodyHtml, string $instruction, ?string $ip = null): string
    {
        $this->requireReady($s, $this->content->isConfigured());
        $instruction = trim($instruction);
        if ($instruction === '' || mb_strlen($instruction) > 1000) {
            throw new ValidationException(['instruction' => 'err.instruction_required']);
        }
        $fields = $this->metered($s, $actor, 'ai_text', fn (): array => $this->content->revise($this->siteInfo($s), $title, $bodyHtml, $instruction));
        $this->audit->log('ai.revise', $actor->id, $s->accountId(), ['site_id' => $s->id()], $ip);
        return $fields['body_html'];
    }

    /** @return array<string, string> excerpt, meta_description, keywords, category, cover_alt, image_prompt */
    public function seoFields(ResolvedSite $s, User $actor, string $title, string $bodyHtml, ?string $ip = null): array
    {
        $this->requireReady($s, $this->content->isConfigured());
        if (\OmniWrite\Support\Html::text($bodyHtml) === '') {
            throw new ValidationException(['body_html' => 'err.body_empty']);
        }
        $fields = $this->metered($s, $actor, 'ai_text', fn (): array => $this->content->seoFields($this->siteInfo($s), $title, $bodyHtml));
        $this->audit->log('ai.seo', $actor->id, $s->accountId(), ['site_id' => $s->id()], $ip);
        return $fields;
    }

    /** Legt Themenvorschläge an (Status "suggested"). @return int Anzahl */
    public function suggestTopics(ResolvedSite $s, User $actor, ?string $ip = null): int
    {
        $this->requireReady($s, $this->content->isConfigured());
        $covered = array_merge(
            array_column((new TopicRepository($this->pdo, $s->context))->all(500), 'title'),
            (new PostRepository($this->pdo, $s->context))->recentTitles(120),
        );
        $ideas = $this->metered($s, $actor, 'ai_text', fn (): array => $this->content->suggestTopics($this->siteInfo($s), array_map('strval', $covered)));
        foreach ($ideas as $idea) {
            $this->topics->add($s, $actor, $idea, 'suggested', $ip);
        }
        $this->audit->log('ai.suggest_topics', $actor->id, $s->accountId(), ['site_id' => $s->id(), 'count' => count($ideas)], $ip);
        return count($ideas);
    }

    /** @return string öffentliche Adresse des gespeicherten Bildes */
    public function generateImage(ResolvedSite $s, User $actor, string $description, ?string $ip = null): string
    {
        $this->requireReady($s, $this->images->isConfigured());
        $description = trim($description);
        if ($description === '' || mb_strlen($description) > 1000) {
            throw new ValidationException(['image_prompt' => 'err.image_prompt_required']);
        }
        $style = $this->settings->get($s)['image_style'];
        $url = $this->metered($s, $actor, 'ai_image', function () use ($s, $description, $style): string {
            $raw = $this->images->generate($description . "\n\nStyle: " . $style);
            try {
                return $this->store->save($s->id(), $raw);
            } catch (RuntimeException) {
                throw new AiException('err.ai_unreadable');
            }
        });
        $this->audit->log('ai.image', $actor->id, $s->accountId(), ['site_id' => $s->id()], $ip);
        return $url;
    }

    /** @return array{name: string, locale: string, style_guide: string, company_facts: string} */
    private function siteInfo(ResolvedSite $s): array
    {
        $set = $this->settings->get($s);
        return ['name' => (string) $s->site['name'], 'locale' => $s->locale(), 'style_guide' => $set['style_guide'], 'company_facts' => $set['company_facts']];
    }

    /**
     * @template T
     * @param callable(): T $call
     * @return T
     */
    private function metered(ResolvedSite $s, User $actor, string $kind, callable $call): mixed
    {
        $eventId = $this->usage->reserve($s, $kind, ['user_id' => $actor->id]);
        try {
            return $call();
        } catch (Throwable $e) {
            $this->usage->release($s, $eventId);
            throw $e;
        }
    }

    private function requireReady(ResolvedSite $s, bool $configured): void
    {
        if (!$s->canWrite()) {
            throw new AccessDeniedException('Keine Schreibrechte');
        }
        if (!$configured) {
            throw new AiException('err.ai_not_configured');
        }
    }
}
