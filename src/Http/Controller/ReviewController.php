<?php
declare(strict_types=1);

namespace OmniWrite\Http\Controller;

use OmniWrite\Domain\NotFoundException;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Http\Request;
use OmniWrite\Http\Response;
use OmniWrite\Service\ResolvedSite;
use OmniWrite\Service\ReviewService;
use OmniWrite\Service\SiteAccess;

/** "Zur Prüfung senden" aus dem Editor: Review-Mail an die Empfänger der Site. */
final class ReviewController extends SiteController
{
    public function __construct(SiteAccess $access, private readonly ReviewService $review)
    {
        parent::__construct($access);
    }

    public function send(Request $r): Response
    {
        return $this->withSite($r, function (ResolvedSite $s) use ($r): Response {
            $id = $this->id($r);
            try {
                $res = $this->review->send($s, $r->user, $id, $r->ip);
            } catch (ValidationException $e) {
                return Response::redirect($this->url($s, "/posts/$id?error=" . rawurlencode((string) array_values($e->errors)[0])));
            } catch (NotFoundException) {
                return Response::text('Not found', 404);
            }
            if ($res['sent'] === 0) {
                return Response::redirect($this->url($s, "/posts/$id?error=err.mail_failed"));
            }
            return Response::redirect($this->url($s, "/posts/$id?notice=" . ($res['failed'] === [] ? 'review_sent' : 'review_partial')));
        });
    }
}
