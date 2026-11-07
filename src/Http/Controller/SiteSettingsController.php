<?php
declare(strict_types=1);

namespace OmniWrite\Http\Controller;

use OmniWrite\Domain\ValidationException;
use OmniWrite\Http\Request;
use OmniWrite\Http\Response;
use OmniWrite\Http\View;
use OmniWrite\Service\ResolvedSite;
use OmniWrite\Service\SiteAccess;
use OmniWrite\Service\SiteSettingsService;

final class SiteSettingsController extends SiteController
{
    public function __construct(SiteAccess $access, private readonly View $view, private readonly SiteSettingsService $settings)
    {
        parent::__construct($access);
    }

    public function form(Request $r): Response
    {
        return $this->withSite($r, fn (ResolvedSite $s): Response => $this->render($r, $s, $this->settings->get($s), [], isset($r->query['saved'])));
    }

    public function save(Request $r): Response
    {
        return $this->withSite($r, function (ResolvedSite $s) use ($r): Response {
            $in = [];
            foreach (SiteSettingsService::KEYS as $k) {
                $in[$k] = $r->input($k);
            }
            try {
                $this->settings->save($s, $r->user, $in, $r->ip);
            } catch (ValidationException $e) {
                return $this->render($r, $s, $in, $e->errors, false, 422);
            }
            return Response::redirect($this->url($s, '/settings?saved=1'));
        });
    }

    /** @param array<string, string> $values @param array<string, string> $errors */
    private function render(Request $r, ResolvedSite $s, array $values, array $errors, bool $saved, int $status = 200): Response
    {
        return $this->view->render($r, 'site_settings', [
            'rs' => $s, 'site' => $s->site, 'values' => $values, 'errors' => $errors, 'saved' => $saved,
        ], $status);
    }
}
