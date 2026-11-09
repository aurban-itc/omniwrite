<?php
declare(strict_types=1);

namespace OmniWrite\Http;

use OmniWrite\Auth\AuditLogger;
use OmniWrite\Auth\TwoFactorService;
use OmniWrite\Repository\UserRepository;
use OmniWrite\Service\PublicBlogService;

/** Request-Pipeline: Nutzer laden, Impersonation und 2FA-Pflicht prüfen, Locale, CSRF, Router. */
final class Kernel
{
    /** Pfade, die ein Superadmin ohne eingerichtete 2FA noch erreichen darf. */
    private const OPEN_WITHOUT_2FA = ['/account/2fa', '/logout', '/health'];

    public function __construct(
        private readonly Router $router,
        private readonly UserRepository $users,
        private readonly Csrf $csrf,
        private readonly TwoFactorService $twoFactor,
        private readonly AuditLogger $audit,
        private readonly string $defaultLocale = 'de',
        private readonly ?PublicBlogService $publicBlog = null,
        private readonly ?Router $publicRouter = null,
        private readonly string $portalHost = '',
    ) {
    }

    public function handle(Request $request): Response
    {
        // Eine verifizierte eigene Domain liefert ausschließlich das öffentliche Blog aus, nie Login oder Backend.
        if ($this->publicBlog !== null && $this->publicRouter !== null && $request->host !== '' && $request->host !== $this->portalHost) {
            $site = $this->publicBlog->siteByHost($request->host);
            if ($site !== null) {
                $request->publicSite = $site;
                return $this->publicRouter->dispatch($request);
            }
        }
        $this->audit->setImpersonator(null); // pro Request neu bestimmen
        $this->loadUser($request);

        $lang = $request->query['lang'] ?? null;
        if ($request->method === 'GET' && in_array($lang, ['de', 'en'], true)) {
            $request->session->set('locale', $lang);
        }
        $locale = $request->user?->locale ?? $request->session->get('locale', $this->defaultLocale);
        $request->locale = in_array($locale, ['de', 'en'], true) ? $locale : $this->defaultLocale;

        if ($request->method === 'POST' && !$this->csrf->verify($request->session, $request->post[Csrf::FIELD] ?? null)) {
            return Response::text('CSRF token invalid', 419);
        }
        if ($this->mustSetUpTwoFactor($request)) {
            return Response::redirect('/account/2fa');
        }
        return $this->router->dispatch($request);
    }

    private function loadUser(Request $request): void
    {
        $userId = $request->session->get('user_id');
        if (!is_int($userId)) {
            return;
        }
        $user = $this->users->find($userId);
        if ($user === null || !$user->isActive()) {
            $request->session->forget('user_id'); // deaktivierte Nutzer fliegen sofort raus
            $request->session->forget('impersonator_id');
            return;
        }
        $request->user = $user;

        $adminId = $request->session->get('impersonator_id');
        if ($adminId === null) {
            // Wer 2FA aktiv hat, muss den zweiten Faktor in dieser Sitzung für genau dieses Konto bestanden haben.
            if ($this->twoFactor->isEnabled($user) && $request->session->get('mfa') !== $user->id) {
                $request->session->forget('user_id');
                $request->user = null;
                return;
            }
        } else {
            $admin = is_int($adminId) ? $this->users->find($adminId) : null;
            if ($admin === null || !$admin->isActive() || !$admin->isSuperadmin || $user->isSuperadmin) {
                // Der echte Superadmin ist nicht mehr gültig: Sitzung komplett beenden.
                $request->session->clear();
                $request->user = null;
                return;
            }
            if ($this->twoFactor->isEnabled($admin) && $request->session->get('mfa') !== $admin->id) {
                $request->session->clear();
                $request->user = null;
                return;
            }
            $request->impersonator = $admin;
            $this->audit->setImpersonator($admin->id);
        }
    }

    private function mustSetUpTwoFactor(Request $request): bool
    {
        $user = $request->user;
        return $user !== null && $request->impersonator === null
            && $this->twoFactor->isRequired($user) && !$this->twoFactor->isEnabled($user)
            && !in_array($request->path, self::OPEN_WITHOUT_2FA, true);
    }
}
