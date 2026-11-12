<?php
declare(strict_types=1);

namespace OmniWrite\Http\Controller;

use OmniWrite\Ai\AiException;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Http\Request;
use OmniWrite\Http\Response;
use OmniWrite\Http\View;
use OmniWrite\Net\FetchException;
use OmniWrite\Service\ResolvedSite;
use OmniWrite\Service\SiteAccess;
use OmniWrite\Service\SiteProfileService;
use OmniWrite\Service\UsageLimitException;
use OmniWrite\Support\Translator;

/** Einstellungen und Themen aus der Firmenwebsite vorschlagen lassen: analysieren, prüfen, gezielt übernehmen. */
final class SiteProfileController extends SiteController
{
    public function __construct(
        SiteAccess $access,
        private readonly View $view,
        private readonly Translator $translator,
        private readonly SiteProfileService $profile,
    ) {
        parent::__construct($access);
    }

    public function form(Request $r): Response
    {
        return $this->withSite($r, fn (ResolvedSite $s): Response => $this->renderForm($r, $s, '', null));
    }

    public function analyze(Request $r): Response
    {
        return $this->withSite($r, function (ResolvedSite $s) use ($r): Response {
            // Abruf mehrerer Seiten plus KI-Aufruf dauert; PHP darf nicht vorher abbrechen.
            @set_time_limit(300);
            $url = $r->input('url');
            try {
                $result = $this->profile->analyze($s, $r->user, $url, $r->ip);
            } catch (ValidationException $e) {
                return $this->renderForm($r, $s, $url, $this->message($r, (string) array_values($e->errors)[0]), 422);
            } catch (FetchException $e) {
                return $this->renderForm($r, $s, $url, $this->message($r, $e->getMessage(), $e->detail), $e->getMessage() === 'err.fetch_blocked' ? 422 : 502);
            } catch (UsageLimitException $e) {
                return $this->renderForm($r, $s, $url, $this->message($r, $e->getMessage()), 429);
            } catch (AiException $e) {
                return $this->renderForm($r, $s, $url, $this->message($r, $e->getMessage(), $e->detail), 502);
            }
            return $this->view->render($r, 'site_analyze_review', ['rs' => $s, 'site' => $s->site, 'result' => $result]);
        });
    }

    public function apply(Request $r): Response
    {
        return $this->withSite($r, function (ResolvedSite $s) use ($r): Response {
            try {
                $done = $this->profile->apply($s, $r->user, $r->post, $r->ip);
            } catch (ValidationException $e) {
                return $this->renderForm($r, $s, '', $this->message($r, (string) array_values($e->errors)[0]), 422);
            }
            return Response::redirect($this->url($s, '/settings/analyze?applied=1&s=' . $done['settings'] . '&t=' . $done['topics']));
        });
    }

    private function message(Request $r, string $key, string $detail = ''): string
    {
        $msg = $this->translator->t($r->locale, $key);
        if ($detail !== '' && $r->user?->isSuperadmin === true) {
            $msg .= ' [' . $detail . ']';
        }
        return $msg;
    }

    private function renderForm(Request $r, ResolvedSite $s, string $url, ?string $error, int $status = 200): Response
    {
        $applied = isset($r->query['applied']) ? ['settings' => (int) ($r->query['s'] ?? 0), 'topics' => (int) ($r->query['t'] ?? 0)] : null;
        return $this->view->render($r, 'site_analyze', [
            'rs' => $s, 'site' => $s->site, 'url' => $url, 'error' => $error, 'applied' => $applied, 'aiReady' => $this->profile->ready(),
        ], $status);
    }
}
