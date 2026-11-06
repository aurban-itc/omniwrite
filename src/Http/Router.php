<?php
declare(strict_types=1);

namespace OmniWrite\Http;

final class Router
{
    /** @var list<array{string, string, callable, ?string}> */
    private array $routes = [];

    /** @param ?string $guard null = öffentlich, 'user' = angemeldet, 'superadmin' */
    public function add(string $method, string $pattern, callable $handler, ?string $guard = null): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler, $guard];
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as [$method, $regex, $handler, $guard]) {
            if ($method !== $request->method || !preg_match($regex, $request->path, $m)) {
                continue;
            }
            if ($guard !== null && $request->user === null) {
                return Response::redirect('/login');
            }
            if ($guard === 'superadmin' && !$request->user?->isSuperadmin) {
                return Response::text('Forbidden', 403);
            }
            $request->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return $handler($request);
        }
        return Response::text('Not found', 404);
    }
}
