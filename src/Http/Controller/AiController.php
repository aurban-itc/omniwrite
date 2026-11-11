<?php
declare(strict_types=1);

namespace OmniWrite\Http\Controller;

use OmniWrite\Ai\AiException;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Http\Request;
use OmniWrite\Http\Response;
use OmniWrite\Http\View;
use OmniWrite\Service\AiService;
use OmniWrite\Service\ResolvedSite;
use OmniWrite\Service\SiteAccess;
use OmniWrite\Service\TopicService;
use OmniWrite\Service\UsageLimitException;
use OmniWrite\Service\UsageService;
use OmniWrite\Support\Translator;

final class AiController extends SiteController
{
    public function __construct(
        SiteAccess $access,
        private readonly View $view,
        private readonly Translator $translator,
        private readonly AiService $ai,
        private readonly TopicService $topics,
        private readonly UsageService $usage,
    ) {
        parent::__construct($access);
    }

    public function generateForm(Request $r): Response
    {
        return $this->withSite($r, fn (ResolvedSite $s): Response => $this->renderForm($r, $s, [], null));
    }

    public function generate(Request $r): Response
    {
        return $this->withSite($r, function (ResolvedSite $s) use ($r): Response {
            $in = [];
            foreach (['topic_id', 'title', 'keywords', 'notes', 'extra'] as $f) {
                $in[$f] = $r->input($f);
            }
            try {
                $id = $this->ai->generateDraft($s, $r->user, $in, $r->ip);
            } catch (ValidationException $e) {
                return $this->renderForm($r, $s, $in, null, $e->errors, 422);
            } catch (AiException | UsageLimitException $e) {
                return $this->renderForm($r, $s, $in, $e->getMessage(), [], $e instanceof UsageLimitException ? 429 : 502);
            }
            return Response::redirect($this->url($s, "/posts/$id?notice=generated"));
        });
    }

    public function suggestTopics(Request $r): Response
    {
        return $this->withSite($r, function (ResolvedSite $s) use ($r): Response {
            try {
                $n = $this->ai->suggestTopics($s, $r->user, $r->ip);
            } catch (AiException | UsageLimitException $e) {
                return $this->view->render($r, 'topics', [
                    'rs' => $s, 'site' => $s->site, 'overview' => $this->topics->overview($s), 'errors' => [], 'old' => [],
                    'aiError' => $e->getMessage(), 'aiReady' => $this->ai->textReady(),
                ], $e instanceof UsageLimitException ? 429 : 502);
            }
            return Response::redirect($this->url($s, '/topics?suggested=' . $n));
        });
    }

    public function revise(Request $r): Response
    {
        return $this->json($r, fn (ResolvedSite $s): array => [
            'body_html' => $this->ai->revise($s, $r->user, $r->input('title'), $r->input('body_html'), $r->input('instruction'), $r->ip),
        ]);
    }

    public function seo(Request $r): Response
    {
        return $this->json($r, fn (ResolvedSite $s): array => $this->ai->seoFields($s, $r->user, $r->input('title'), $r->input('body_html'), $r->ip));
    }

    public function image(Request $r): Response
    {
        return $this->json($r, fn (ResolvedSite $s): array => ['path' => $this->ai->generateImage($s, $r->user, $r->input('prompt'), $r->ip)]);
    }

    /** @param callable(ResolvedSite): array<string, mixed> $handler */
    private function json(Request $r, callable $handler): Response
    {
        return $this->withSite($r, function (ResolvedSite $s) use ($r, $handler): Response {
            // KI-Aufrufe dauern (bis 2 x 150 s mit Wiederholung); PHP darf nicht vorher abbrechen.
            @set_time_limit(400);
            @ignore_user_abort(true);
            try {
                return Response::json($handler($s));
            } catch (ValidationException $e) {
                return Response::json(['error' => $this->translator->t($r->locale, (string) (array_values($e->errors)[0] ?? 'err.ai_failed'))], 422);
            } catch (UsageLimitException $e) {
                return Response::json(['error' => $this->translator->t($r->locale, $e->getMessage())], 429);
            } catch (AiException $e) {
                $msg = $this->translator->t($r->locale, $e->getMessage());
                if ($e->detail !== '' && $r->user?->isSuperadmin === true) {
                    $msg .= ' [' . $e->detail . ']';
                }
                return Response::json(['error' => $msg], 502);
            } catch (\Throwable $e) {
                // Unerwarteter Fehler: Details ins Log, dem Editor trotzdem eine lesbare JSON-Antwort geben.
                error_log((string) $e);
                $msg = $this->translator->t($r->locale, 'err.ai_failed');
                if ($r->user?->isSuperadmin === true) {
                    $msg .= ' [' . get_class($e) . ': ' . substr($e->getMessage(), 0, 200) . ']';
                }
                return Response::json(['error' => $msg], 500);
            }
        });
    }

    /** @param array<string, string> $old @param array<string, string> $errors */
    private function renderForm(Request $r, ResolvedSite $s, array $old, ?string $aiError, array $errors = [], int $status = 200): Response
    {
        return $this->view->render($r, 'ai_generate', [
            'rs' => $s, 'site' => $s->site, 'topics' => $this->topics->overview($s)['rotation'], 'old' => $old,
            'errors' => $errors, 'aiError' => $aiError, 'aiReady' => $this->ai->textReady(), 'usage' => $this->usage->summary($s),
            'preselect' => (int) ($r->query['topic'] ?? 0),
        ], $status);
    }
}
