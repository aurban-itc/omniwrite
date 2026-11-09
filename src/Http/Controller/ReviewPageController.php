<?php
declare(strict_types=1);

namespace OmniWrite\Http\Controller;

use OmniWrite\Domain\ValidationException;
use OmniWrite\Http\Request;
use OmniWrite\Http\Response;
use OmniWrite\Http\View;
use OmniWrite\Service\ReviewService;

/**
 * Öffentliche Seiten hinter den Mail-Links (kein Konto nötig). GET zeigt nur eine Bestätigungsseite und ändert nie etwas,
 * damit Mail-Scanner, die Links vorab abrufen, nichts auslösen. Erst der POST (mit CSRF-Token) veröffentlicht oder lehnt ab.
 */
final class ReviewPageController
{
    public function __construct(private readonly View $view, private readonly ReviewService $review)
    {
    }

    public function show(Request $r): Response
    {
        $ticket = $this->review->ticket((string) ($r->params['token'] ?? ''));
        $do = (string) ($r->params['do'] ?? '');
        if ($ticket === null || !in_array($do, ['publish', 'decline', 'edit'], true)) {
            return $this->page($r, ['mode' => 'expired'], 404);
        }
        return $this->page($r, $this->data($ticket, $do) + ['saved' => isset($r->query['saved'])]);
    }

    public function act(Request $r): Response
    {
        $ticket = $this->review->ticket((string) ($r->params['token'] ?? ''));
        $do = (string) ($r->params['do'] ?? '');
        if ($ticket === null || !in_array($do, ['publish', 'decline', 'edit'], true)) {
            return $this->page($r, ['mode' => 'expired'], 404);
        }
        if ($do === 'edit') {
            try {
                $ok = $this->review->edit($ticket, [
                    'title' => $r->input('title'), 'body_html' => $r->input('body_html'),
                    'excerpt' => $r->input('excerpt'), 'meta_description' => $r->input('meta_description'),
                ], $r->ip);
            } catch (ValidationException $e) {
                return $this->page($r, $this->data($ticket, 'edit', $e->errors, [
                    'title' => $r->input('title'), 'body_html' => $r->input('body_html'), 'excerpt' => $r->input('excerpt'), 'meta_description' => $r->input('meta_description'),
                ]), 422);
            }
            return $ok
                ? Response::redirect('/review/' . rawurlencode((string) $r->params['token']) . '/edit?saved=1')
                : $this->page($r, ['mode' => 'decided', 'post' => $ticket['post'], 'site' => $ticket['site']]);
        }
        $ok = $do === 'publish' ? $this->review->publish($ticket, $r->ip) : $this->review->decline($ticket, $r->input('note'), $r->ip);
        return $this->page($r, ['mode' => $ok ? 'done_' . $do : 'decided', 'post' => $ticket['post'], 'site' => $ticket['site']]);
    }

    /**
     * @param array<string, mixed> $ticket
     * @param array<string, string> $errors
     * @param array<string, string> $old
     * @return array<string, mixed>
     */
    private function data(array $ticket, string $do, array $errors = [], array $old = []): array
    {
        $post = $ticket['post'];
        if (!in_array($post['status'], ['draft', 'in_review'], true)) {
            return ['mode' => 'decided', 'post' => $post, 'site' => $ticket['site']];
        }
        return ['mode' => $do, 'post' => $post, 'site' => $ticket['site'], 'errors' => $errors, 'values' => $old + $post];
    }

    /** @param array<string, mixed> $data */
    private function page(Request $r, array $data, int $status = 200): Response
    {
        $r->locale = isset($data['site']['locale']) && in_array($data['site']['locale'], ['de', 'en'], true) ? $data['site']['locale'] : $r->locale;
        $token = (string) ($r->params['token'] ?? '');
        $res = $this->view->render($r, 'review_page', $data + ['errors' => [], 'values' => [], 'saved' => false, 'token' => preg_match('/^[a-f0-9]{64}$/', $token) === 1 ? $token : ''], $status);
        // Der Token steckt im Pfad: nicht per Referer weitergeben, nicht cachen, nicht indexieren.
        return new Response($res->status, array_merge($res->headers, ['Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']), $res->body);
    }
}
