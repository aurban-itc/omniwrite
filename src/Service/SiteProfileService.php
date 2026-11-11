<?php
declare(strict_types=1);

namespace OmniWrite\Service;

use OmniWrite\Ai\AiException;
use OmniWrite\Ai\ContentAi;
use OmniWrite\Auth\AuditLogger;
use OmniWrite\Domain\AccessDeniedException;
use OmniWrite\Domain\User;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Net\FetchException;
use OmniWrite\Net\PageFetcher;
use OmniWrite\Repository\PostRepository;
use OmniWrite\Repository\TopicRepository;
use OmniWrite\Support\PageExtractor;
use PDO;
use Throwable;

/**
 * Schlägt aus der Website eines Unternehmens Redaktionseinstellungen und Themen vor.
 * analyze() speichert nichts; erst apply() übernimmt, was der Nutzer ausgewählt (und ggf. bearbeitet) hat.
 */
final class SiteProfileService
{
    private const MAX_SUBPAGES = 3;
    private const MAX_TOTAL_CHARS = 16000;
    private const MAX_TOPICS = 20;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly ContentAi $content,
        private readonly UsageService $usage,
        private readonly SiteSettingsService $settings,
        private readonly TopicService $topics,
        private readonly PageFetcher $fetcher,
    ) {
    }

    public function ready(): bool
    {
        return $this->content->isConfigured();
    }

    /**
     * @return array{url: string, pages: int, settings: array<string, string>, current: array<string, string>, topics: list<array{title: string, keywords: string, category: string, notes: string}>}
     * @throws AiException|FetchException|UsageLimitException|ValidationException|AccessDeniedException
     */
    public function analyze(ResolvedSite $s, User $actor, string $url, ?string $ip = null): array
    {
        if (!$s->canWrite()) {
            throw new AccessDeniedException('Keine Schreibrechte');
        }
        if (!$this->content->isConfigured()) {
            throw new AiException('err.ai_not_configured');
        }
        $url = trim($url);
        if ($url === '') {
            throw new ValidationException(['url' => 'err.fetch_url_invalid']);
        }

        $main = $this->fetcher->fetch($url);
        $first = PageExtractor::extract($main['body'], $main['url']);
        $pages = [['url' => $main['url']] + $first];
        $budget = self::MAX_TOTAL_CHARS - mb_strlen($first['text']);
        foreach (array_slice($first['links'], 0, self::MAX_SUBPAGES) as $link) {
            if ($budget < 500) {
                break;
            }
            try {
                $sub = $this->fetcher->fetch($link);
            } catch (FetchException $e) {
                error_log('site profile subpage skipped: ' . $e->getMessage() . ' ' . $e->detail);
                continue;
            }
            $page = PageExtractor::extract($sub['body'], $sub['url'], min(4000, $budget));
            if ($page['text'] === '') {
                continue;
            }
            $budget -= mb_strlen($page['text']);
            $pages[] = ['url' => $sub['url']] + $page;
        }
        if (mb_strlen(implode('', array_column($pages, 'text'))) < 80) {
            throw new FetchException('err.fetch_no_text'); // z. B. reine JavaScript-Seite
        }

        $covered = array_map('strval', array_merge(
            array_column((new TopicRepository($this->pdo, $s->context))->all(500), 'title'),
            (new PostRepository($this->pdo, $s->context))->recentTitles(60),
        ));
        $eventId = $this->usage->reserve($s, 'ai_text', ['user_id' => $actor->id, 'kind' => 'analyze_site']);
        try {
            $profile = $this->content->analyzeCompany(['name' => (string) $s->site['name'], 'locale' => $s->locale()], $pages, $covered);
        } catch (Throwable $e) {
            $this->usage->release($s, $eventId);
            throw $e;
        }
        $this->audit->log('ai.analyze_site', $actor->id, $s->accountId(), ['site_id' => $s->id(), 'host' => (string) parse_url($main['url'], PHP_URL_HOST), 'pages' => count($pages)], $ip);

        return [
            'url' => $main['url'],
            'pages' => count($pages),
            'settings' => ['style_guide' => $profile['style_guide'], 'company_facts' => $profile['company_facts'], 'image_style' => $profile['image_style']],
            'current' => $this->settings->get($s),
            'topics' => $profile['topics'],
        ];
    }

    /**
     * Übernimmt die ausgewählten Felder und Themen.
     *
     * @param array<string, mixed> $in use_<key> + <key> je Einstellung; topic_use[i], topic_title[i], topic_keywords[i], topic_category[i], topic_notes[i]
     * @return array{settings: int, topics: int}
     */
    public function apply(ResolvedSite $s, User $actor, array $in, ?string $ip = null): array
    {
        if (!$s->canWrite()) {
            throw new AccessDeniedException('Keine Schreibrechte');
        }
        $values = [];
        foreach (SiteSettingsService::KEYS as $key) {
            if (!empty($in['use_' . $key])) {
                $values[$key] = (string) ($in[$key] ?? '');
            }
        }
        $topics = [];
        foreach ((array) ($in['topic_use'] ?? []) as $i => $on) {
            $title = trim((string) ($in['topic_title'][$i] ?? ''));
            if (!$on || $title === '' || count($topics) >= self::MAX_TOPICS) {
                continue;
            }
            $topics[] = [
                'title' => $title,
                'keywords' => (string) ($in['topic_keywords'][$i] ?? ''),
                'category' => (string) ($in['topic_category'][$i] ?? ''),
                'notes' => (string) ($in['topic_notes'][$i] ?? ''),
            ];
        }
        // Erst alles prüfen, dann schreiben: ein Fehler soll nichts halb übernehmen.
        foreach ($topics as $t) {
            if (mb_strlen($t['title']) > 255) {
                throw new ValidationException(['topics' => 'err.title_required']);
            }
        }
        if ($values !== []) {
            $this->settings->update($s, $actor, $values, $ip);
        }
        foreach ($topics as $t) {
            $this->topics->add($s, $actor, $t, 'active', $ip);
        }
        return ['settings' => count($values), 'topics' => count($topics)];
    }
}
