<?php
declare(strict_types=1);

namespace OmniWrite\Http\Controller;

use OmniWrite\Domain\AccessDeniedException;
use OmniWrite\Domain\NotFoundException;
use OmniWrite\Http\Request;
use OmniWrite\Http\Response;
use OmniWrite\Service\ResolvedSite;
use OmniWrite\Service\SiteAccess;

/** Gemeinsame Basis für alles unter /sites/{slug}: Site auflösen, Berechtigungsfehler in 403/404 übersetzen. */
abstract class SiteController
{
    public function __construct(protected readonly SiteAccess $access)
    {
    }

    /** @param callable(ResolvedSite): Response $handler */
    protected function withSite(Request $r, callable $handler): Response
    {
        try {
            return $handler($this->access->resolve($r->user, (string) ($r->params['slug'] ?? '')));
        } catch (NotFoundException) {
            return Response::text('Not found', 404);
        } catch (AccessDeniedException) {
            return Response::text('Forbidden', 403);
        }
    }

    protected function id(Request $r): int
    {
        return (int) ($r->params['id'] ?? 0);
    }

    protected function url(ResolvedSite $s, string $path = ''): string
    {
        return '/sites/' . rawurlencode((string) $s->site['slug']) . $path;
    }
}
