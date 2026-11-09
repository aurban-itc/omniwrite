<?php
declare(strict_types=1);

namespace OmniWrite\Http\Controller;

use OmniWrite\Domain\ValidationException;
use OmniWrite\Http\Request;
use OmniWrite\Http\Response;
use OmniWrite\Http\View;
use OmniWrite\Service\ResolvedSite;
use OmniWrite\Service\SiteAccess;
use OmniWrite\Service\TopicService;

final class TopicController extends SiteController
{
    public function __construct(SiteAccess $access, private readonly View $view, private readonly TopicService $topics)
    {
        parent::__construct($access);
    }

    public function index(Request $r): Response
    {
        return $this->withSite($r, fn (ResolvedSite $s): Response => $this->list($r, $s));
    }

    public function add(Request $r): Response
    {
        return $this->withSite($r, function (ResolvedSite $s) use ($r): Response {
            $in = $this->fields($r);
            try {
                $this->topics->add($s, $r->user, $in, 'active', $r->ip);
            } catch (ValidationException $e) {
                return $this->list($r, $s, $e->errors, $in, 422);
            }
            return Response::redirect($this->url($s, '/topics'));
        });
    }

    public function editForm(Request $r): Response
    {
        return $this->withSite($r, fn (ResolvedSite $s): Response => $this->view->render($r, 'topic_edit', [
            'rs' => $s, 'site' => $s->site, 'topic' => $this->topics->get($s, $this->id($r)), 'errors' => [],
        ]));
    }

    public function update(Request $r): Response
    {
        return $this->withSite($r, function (ResolvedSite $s) use ($r): Response {
            $id = $this->id($r);
            $in = $this->fields($r);
            try {
                $this->topics->update($s, $r->user, $id, $in, $r->ip);
            } catch (ValidationException $e) {
                return $this->view->render($r, 'topic_edit', [
                    'rs' => $s, 'site' => $s->site, 'topic' => $in + ['id' => $id], 'errors' => $e->errors,
                ], 422);
            }
            return Response::redirect($this->url($s, '/topics'));
        });
    }

    public function action(Request $r): Response
    {
        return $this->withSite($r, function (ResolvedSite $s) use ($r): Response {
            $id = $this->id($r);
            match ((string) ($r->params['action'] ?? '')) {
                'up' => $this->topics->move($s, $r->user, $id, -1, $r->ip),
                'down' => $this->topics->move($s, $r->user, $id, 1, $r->ip),
                'pause' => $this->topics->setStatus($s, $r->user, $id, 'pause', $r->ip),
                'resume' => $this->topics->setStatus($s, $r->user, $id, 'resume', $r->ip),
                'accept' => $this->topics->setStatus($s, $r->user, $id, 'accept', $r->ip),
                'delete', 'dismiss' => $this->topics->delete($s, $r->user, $id, $r->ip),
                default => null,
            };
            return Response::redirect($this->url($s, '/topics'));
        });
    }

    /** @return array<string, string> */
    private function fields(Request $r): array
    {
        return [
            'title' => $r->input('title'), 'keywords' => $r->input('keywords'),
            'category' => $r->input('category'), 'notes' => $r->input('notes'),
        ];
    }

    /** @param array<string, string> $errors @param array<string, string> $old */
    private function list(Request $r, ResolvedSite $s, array $errors = [], array $old = [], int $status = 200): Response
    {
        return $this->view->render($r, 'topics', [
            'rs' => $s, 'site' => $s->site, 'overview' => $this->topics->overview($s), 'errors' => $errors, 'old' => $old,
            'suggested' => (int) ($r->query['suggested'] ?? 0),
        ], $status);
    }
}
