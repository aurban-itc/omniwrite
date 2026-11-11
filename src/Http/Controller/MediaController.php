<?php
declare(strict_types=1);

namespace OmniWrite\Http\Controller;

use OmniWrite\Http\Request;
use OmniWrite\Http\Response;
use OmniWrite\Support\ImageStore;

/** Liefert gespeicherte Bilder aus. Öffentlich (die Bilder erscheinen in veröffentlichten Beiträgen), Namen sind unratbar und streng geprüft. */
final class MediaController
{
    public function __construct(private readonly ImageStore $store)
    {
    }

    public function show(Request $r): Response
    {
        $path = $this->store->pathFor((int) ($r->params['site'] ?? 0), (string) ($r->params['file'] ?? ''));
        $body = $path === null ? false : file_get_contents($path);
        if ($body === false) {
            return Response::text('Not found', 404);
        }
        return new Response(200, [
            'Content-Type' => match (true) {
                str_ends_with($path, '.png') => 'image/png',
                str_ends_with($path, '.avif') => 'image/avif',
                default => 'image/webp',
            },
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ], $body);
    }
}
