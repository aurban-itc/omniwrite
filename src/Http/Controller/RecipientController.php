<?php
declare(strict_types=1);

namespace OmniWrite\Http\Controller;

use OmniWrite\Domain\ValidationException;
use OmniWrite\Http\Request;
use OmniWrite\Http\Response;
use OmniWrite\Http\View;
use OmniWrite\Service\RecipientService;
use OmniWrite\Service\ResolvedSite;
use OmniWrite\Service\SiteAccess;

final class RecipientController extends SiteController
{
    public function __construct(SiteAccess $access, private readonly View $view, private readonly RecipientService $recipients)
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
            try {
                $this->recipients->add($s, $r->user, $r->input('email'), $r->input('name'), $r->ip);
            } catch (ValidationException $e) {
                return $this->render($r, $s, $e->errors, ['email' => $r->input('email'), 'name' => $r->input('name')], 422);
            }
            return Response::redirect($this->url($s, '/recipients'));
        });
    }

    public function action(Request $r): Response
    {
        return $this->withSite($r, function (ResolvedSite $s) use ($r): Response {
            $id = $this->id($r);
            match ((string) ($r->params['action'] ?? '')) {
                'toggle' => $this->recipients->toggle($s, $r->user, $id, $r->ip),
                'remove' => $this->recipients->remove($s, $r->user, $id, $r->ip),
                default => throw new \OmniWrite\Domain\NotFoundException('Aktion unbekannt'),
            };
            return Response::redirect($this->url($s, '/recipients'));
        });
    }

    /** @param array<string, string> $errors @param array<string, string> $old */
    private function render(Request $r, ResolvedSite $s, array $errors = [], array $old = [], int $status = 200): Response
    {
        return $this->view->render($r, 'recipients', [
            'rs' => $s, 'site' => $s->site, 'recipients' => $this->recipients->list($s), 'errors' => $errors, 'old' => $old,
        ], $status);
    }
}
