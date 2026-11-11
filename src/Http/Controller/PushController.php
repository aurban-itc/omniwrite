<?php
declare(strict_types=1);

namespace OmniWrite\Http\Controller;

use OmniWrite\Domain\NotFoundException;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Http\Request;
use OmniWrite\Http\Response;
use OmniWrite\Http\View;
use OmniWrite\Push\PushService;
use OmniWrite\Service\ResolvedSite;
use OmniWrite\Service\SiteAccess;

/** Anbindungen einer Site (Webhook, GitHub). Lesen dürfen alle Mitglieder, ändern nur Owner. */
final class PushController extends SiteController
{
    public function __construct(SiteAccess $access, private readonly View $view, private readonly PushService $push)
    {
        parent::__construct($access);
    }

    public function index(Request $r): Response
    {
        return $this->withSite($r, fn (ResolvedSite $s): Response => $this->render($r, $s));
    }

    public function add(Request $r): Response
    {
        return $this->withSite($r, function (ResolvedSite $s) use ($r): Response {
            $kind = $r->input('kind');
            $in = [];
            foreach (['name', 'url', 'secret', 'repo', 'branch', 'dir', 'ext', 'token'] as $k) {
                $in[$k] = $r->input($k);
            }
            try {
                $this->push->add($s, $r->user, $kind, $in, $r->ip);
            } catch (ValidationException $e) {
                // Geheimnisse werden nie zurück ins Formular geschrieben.
                unset($in['secret'], $in['token']);
                return $this->render($r, $s, $e->errors, $in + ['kind' => $kind], [], 422);
            }
            return Response::redirect($this->url($s, '/connectors'));
        });
    }

    public function action(Request $r): Response
    {
        return $this->withSite($r, function (ResolvedSite $s) use ($r): Response {
            $id = $this->id($r);
            switch ((string) ($r->params['action'] ?? '')) {
                case 'toggle':
                    $this->push->toggle($s, $r->user, $id, $r->ip);
                    break;
                case 'remove':
                    $this->push->remove($s, $r->user, $id, $r->ip);
                    break;
                case 'send':
                    @set_time_limit(120);
                    return $this->render($r, $s, [], [], $this->push->sendNow($s, $r->user, $id, $r->ip));
                default:
                    throw new NotFoundException('Aktion unbekannt');
            }
            return Response::redirect($this->url($s, '/connectors'));
        });
    }

    /** @param array<string, string> $errors @param array<string, string> $old @param list<string> $result */
    private function render(Request $r, ResolvedSite $s, array $errors = [], array $old = [], array $result = [], int $status = 200): Response
    {
        return $this->view->render($r, 'connectors', [
            'rs' => $s, 'site' => $s->site, 'targets' => $this->push->list($s), 'errors' => $errors, 'old' => $old, 'result' => $result,
        ], $status);
    }
}
