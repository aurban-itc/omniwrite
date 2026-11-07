<?php
declare(strict_types=1);

namespace OmniWrite\Http\Controller;

use OmniWrite\Auth\TwoFactorService;
use OmniWrite\Domain\AccessDeniedException;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Http\Request;
use OmniWrite\Http\Response;
use OmniWrite\Http\View;
use OmniWrite\Repository\UserRepository;

final class TwoFactorController
{
    public const PENDING_TTL = 300;

    public function __construct(
        private readonly View $view,
        private readonly TwoFactorService $twoFactor,
        private readonly UserRepository $users,
    ) {
    }

    /** Zweiter Schritt beim Login (Passwort war richtig, Sitzung noch nicht angemeldet). */
    public function loginForm(Request $r): Response
    {
        return $this->pendingUser($r) === null ? Response::redirect('/login') : $this->view->render($r, 'twofa_login', ['error' => null]);
    }

    public function login(Request $r): Response
    {
        $user = $this->pendingUser($r);
        if ($user === null) {
            return Response::redirect('/login');
        }
        try {
            $ok = $this->twoFactor->verifyLogin($user, $r->input('code'), $r->ip, time());
        } catch (AccessDeniedException) {
            return $this->view->render($r, 'twofa_login', ['error' => 'err.too_many_attempts'], 429);
        }
        if (!$ok) {
            return $this->view->render($r, 'twofa_login', ['error' => 'err.code_invalid'], 422);
        }
        $r->session->forget('pending_2fa');
        $r->session->regenerate();
        $r->session->set('user_id', $user->id);
        $r->session->set('mfa', $user->id);
        return Response::redirect($user->isSuperadmin ? '/admin' : '/dashboard');
    }

    public function setupForm(Request $r): Response
    {
        if ($r->impersonator !== null) {
            return Response::redirect('/dashboard'); // Sicherheitseinstellungen gehören dem Kunden
        }
        try {
            $info = $this->twoFactor->setupInfo($r->user);
        } catch (AccessDeniedException) {
            return $this->view->render($r, 'twofa_status');
        }
        return $this->view->render($r, 'twofa_setup', $info + ['error' => null]);
    }

    public function setupConfirm(Request $r): Response
    {
        if ($r->impersonator !== null) {
            return Response::redirect('/dashboard'); // Admin darf keinen eigenen Faktor am Kundenkonto hinterlegen
        }
        try {
            $codes = $this->twoFactor->confirmSetup($r->user, $r->input('code'), time(), $r->ip);
        } catch (ValidationException $e) {
            return $this->view->render($r, 'twofa_setup', $this->twoFactor->setupInfo($r->user) + ['error' => $e->errors['code']], 422);
        } catch (AccessDeniedException) {
            return Response::redirect($r->user->isSuperadmin ? '/admin' : '/dashboard');
        }
        $r->session->regenerate();
        $r->session->set('mfa', $r->user->id); // diese Sitzung hat den Faktor gerade nachgewiesen
        return $this->view->render($r, 'twofa_codes', ['codes' => $codes]);
    }

    private function pendingUser(Request $r): ?\OmniWrite\Domain\User
    {
        $pending = $r->session->get('pending_2fa');
        if (!is_array($pending) || !isset($pending['user_id'], $pending['at']) || time() - (int) $pending['at'] > self::PENDING_TTL) {
            $r->session->forget('pending_2fa');
            return null;
        }
        $user = $this->users->find((int) $pending['user_id']);
        return $user !== null && $user->isActive() ? $user : null;
    }
}
