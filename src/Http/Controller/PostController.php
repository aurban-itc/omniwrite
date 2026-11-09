<?php
declare(strict_types=1);

namespace OmniWrite\Http\Controller;

use OmniWrite\Domain\ValidationException;
use OmniWrite\Http\Request;
use OmniWrite\Http\Response;
use OmniWrite\Http\View;
use OmniWrite\Service\PostService;
use OmniWrite\Service\ResolvedSite;
use OmniWrite\Service\SiteAccess;

final class PostController extends SiteController
{
    private const FIELDS = ['title', 'slug', 'body_html', 'excerpt', 'category', 'keywords', 'meta_description', 'cover_image', 'cover_alt', 'image_prompt'];

    public function __construct(SiteAccess $access, private readonly View $view, private readonly PostService $posts, private readonly ?\OmniWrite\Service\AiService $ai = null)
    {
        parent::__construct($access);
    }

    public function index(Request $r): Response
    {
        return $this->withSite($r, function (ResolvedSite $s) use ($r): Response {
            $status = isset($r->query['status']) && is_string($r->query['status']) ? $r->query['status'] : null;
            return $this->view->render($r, 'posts_list', [
                'rs' => $s, 'site' => $s->site, 'filter' => $status, 'paging' => $paging = $this->posts->list($s, $status, (int) ($r->query['page'] ?? 1)),
                'posts' => $paging['items'],
                'notice' => $this->notice($r),
            ]);
        });
    }

    public function newForm(Request $r): Response
    {
        return $this->withSite($r, fn (ResolvedSite $s): Response => $this->form($r, $s, null, array_fill_keys(self::FIELDS, '')));
    }

    public function editForm(Request $r): Response
    {
        return $this->withSite($r, function (ResolvedSite $s) use ($r): Response {
            $post = $this->posts->get($s, $this->id($r));
            return $this->form($r, $s, $post, $post, [], 200, $this->notice($r));
        });
    }

    /** Legt an (kein :id) oder speichert (mit :id). */
    public function save(Request $r): Response
    {
        return $this->withSite($r, function (ResolvedSite $s) use ($r): Response {
            $id = $this->id($r);
            $input = [];
            foreach (self::FIELDS as $f) {
                $input[$f] = $r->input($f);
            }
            try {
                $saved = $this->posts->save($s, $r->user, $id, $input, $r->ip);
            } catch (ValidationException $e) {
                $post = $id > 0 ? $this->posts->get($s, $id) : null;
                return $this->form($r, $s, $post, $input, $e->errors, 422);
            }
            return Response::redirect($this->url($s, "/posts/$saved?notice=saved"));
        });
    }

    public function action(Request $r): Response
    {
        return $this->withSite($r, function (ResolvedSite $s) use ($r): Response {
            $id = $this->id($r);
            $action = (string) ($r->params['action'] ?? '');
            try {
                if ($action === 'delete') {
                    $this->posts->delete($s, $r->user, $id, $r->ip);
                    return Response::redirect($this->url($s, '/posts?notice=deleted'));
                }
                $this->posts->transition($s, $r->user, $id, $action, $r->ip);
            } catch (ValidationException $e) {
                return Response::redirect($this->url($s, '/posts/' . $id . '?error=' . rawurlencode(reset($e->errors))));
            }
            return Response::redirect($this->url($s, "/posts/$id?notice=" . rawurlencode($action)));
        });
    }

    /**
     * @param array<string, mixed>|null $post gespeicherter Stand (null = neu)
     * @param array<string, mixed> $values Formularwerte
     * @param array<string, string> $errors
     */
    private function form(Request $r, ResolvedSite $s, ?array $post, array $values, array $errors = [], int $status = 200, ?string $notice = null): Response
    {
        $queryError = isset($r->query['error']) && is_string($r->query['error']) && str_starts_with($r->query['error'], 'err.') ? $r->query['error'] : null;
        return $this->view->render($r, 'post_edit', [
            'rs' => $s, 'site' => $s->site, 'post' => $post, 'values' => $values, 'errors' => $errors,
            'notice' => $notice, 'queryError' => $queryError,
            'textReady' => $this->ai?->textReady() ?? false, 'imageReady' => $this->ai?->imageReady() ?? false,
        ], $status);
    }

    private function notice(Request $r): ?string
    {
        $n = $r->query['notice'] ?? null;
        return is_string($n) && preg_match('/^[a-z_]{1,20}$/', $n) === 1 ? $n : null;
    }
}
