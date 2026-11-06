<?php
declare(strict_types=1);

namespace OmniWrite\Http;

/** Minimaler Platzhalter für den Front-Controller; Router, CSRF und Auth folgen in Phase 1. */
final class Kernel
{
    public function handle(string $method, string $path): Response
    {
        if ($path === '/health') {
            return new Response(200, ['Content-Type' => 'application/json'], '{"status":"ok"}');
        }
        return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'Not found');
    }
}
