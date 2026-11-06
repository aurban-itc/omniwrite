<?php
declare(strict_types=1);

namespace OmniWrite\Http;

use OmniWrite\Repository\UserRepository;

/** Request-Pipeline: Nutzer laden, Locale bestimmen, CSRF prüfen, Router aufrufen. */
final class Kernel
{
    public function __construct(
        private readonly Router $router,
        private readonly UserRepository $users,
        private readonly Csrf $csrf,
        private readonly string $defaultLocale = 'de',
    ) {
    }

    public function handle(Request $request): Response
    {
        $userId = $request->session->get('user_id');
        if (is_int($userId)) {
            $user = $this->users->find($userId);
            if ($user !== null && $user->isActive()) {
                $request->user = $user;
            } else {
                $request->session->forget('user_id'); // deaktivierte Nutzer fliegen sofort raus
            }
        }

        $lang = $request->query['lang'] ?? null;
        if ($request->method === 'GET' && in_array($lang, ['de', 'en'], true)) {
            $request->session->set('locale', $lang);
        }
        $locale = $request->user?->locale ?? $request->session->get('locale', $this->defaultLocale);
        $request->locale = in_array($locale, ['de', 'en'], true) ? $locale : $this->defaultLocale;

        if ($request->method === 'POST' && !$this->csrf->verify($request->session, $request->post[Csrf::FIELD] ?? null)) {
            return Response::text('CSRF token invalid', 419);
        }
        return $this->router->dispatch($request);
    }
}
