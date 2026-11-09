<?php
declare(strict_types=1);

namespace OmniWrite\Http\Controller;

use OmniWrite\Domain\ValidationException;
use OmniWrite\Http\Request;
use OmniWrite\Http\Response;
use OmniWrite\Http\View;
use OmniWrite\Service\BlogSettingsService;
use OmniWrite\Service\DomainService;
use OmniWrite\Service\ResolvedSite;
use OmniWrite\Service\SiteAccess;

/** Blog-Aussehen und eigene Domain einer Site. Lesen dürfen alle Mitglieder, ändern nur Owner. */
final class BlogSettingsController extends SiteController
{
    public function __construct(
        SiteAccess $access,
        private readonly View $view,
        private readonly BlogSettingsService $settings,
        private readonly DomainService $domains,
        private readonly string $baseUrl,
        private readonly string $portalHost,
    ) {
        parent::__construct($access);
    }

    public function form(Request $r): Response
    {
        return $this->withSite($r, fn (ResolvedSite $s): Response => $this->render($r, $s, $this->settings->theme($s->context), [], (string) ($r->query['notice'] ?? '')));
    }

    public function save(Request $r): Response
    {
        return $this->withSite($r, function (ResolvedSite $s) use ($r): Response {
            $in = [];
            foreach (BlogSettingsService::KEYS as $k) {
                $in[$k] = $r->input($k);
            }
            try {
                $this->settings->save($s, $r->user, $in, $r->ip);
            } catch (ValidationException $e) {
                return $this->render($r, $s, $in, $e->errors, '', 422);
            }
            return Response::redirect($this->url($s, '/blog?notice=saved'));
        });
    }

    public function setDomain(Request $r): Response
    {
        return $this->withSite($r, function (ResolvedSite $s) use ($r): Response {
            try {
                $this->domains->set($s, $r->user, $r->input('domain'), $r->ip);
            } catch (ValidationException $e) {
                return $this->render($r, $s, $this->settings->theme($s->context), $e->errors, '', 422);
            }
            return Response::redirect($this->url($s, '/blog?notice=domain_saved'));
        });
    }

    public function verifyDomain(Request $r): Response
    {
        return $this->withSite($r, function (ResolvedSite $s) use ($r): Response {
            try {
                $result = $this->domains->verify($s, $r->user, $r->ip);
            } catch (ValidationException $e) {
                return $this->render($r, $s, $this->settings->theme($s->context), $e->errors, '', 422);
            }
            return Response::redirect($this->url($s, '/blog?notice=' . (['ok' => 'domain_verified', 'txt' => 'domain_txt'][$result] ?? 'domain_dns')));
        });
    }

    public function removeDomain(Request $r): Response
    {
        return $this->withSite($r, function (ResolvedSite $s) use ($r): Response {
            $this->domains->remove($s, $r->user, $r->ip);
            return Response::redirect($this->url($s, '/blog?notice=saved'));
        });
    }

    /** @param array<string, string> $values @param array<string, string> $errors */
    private function render(Request $r, ResolvedSite $s, array $values, array $errors, string $notice, int $status = 200): Response
    {
        $slug = rawurlencode((string) $s->site['slug']);
        $domain = $this->domains->get($s);
        $own = $domain !== null && $domain['verified_at'] !== null;
        $origin = $own ? 'https://' . $domain['host'] : rtrim($this->baseUrl, '/');
        $apiUrl = $origin . ($own ? '/api/v1/posts' : '/api/v1/sites/' . $slug . '/posts');
        $embed = '<script src="' . $origin . '/assets/embed.js"' . ($own ? '' : ' data-site="' . $slug . '"') . ' data-limit="5" async></script>';
        return $this->view->render($r, 'blog_settings', [
            'apiUrl' => $apiUrl, 'embedSnippet' => $embed,
            'rs' => $s, 'site' => $s->site, 'values' => $values, 'errors' => $errors, 'notice' => $notice,
            'domain' => $domain = $this->domains->get($s), 'portalHost' => $this->portalHost,
            'challenge' => $domain !== null && $domain['verified_at'] === null ? $this->domains->challenge($s, (string) $domain['host']) : null,
            'publicUrl' => rtrim($this->baseUrl, '/') . '/b/' . rawurlencode((string) $s->site['slug']) . '/',
        ], $status);
    }
}
