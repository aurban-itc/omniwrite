<?php
declare(strict_types=1);

namespace OmniWrite\Http\Controller;

use OmniWrite\Auth\AuditLogger;
use OmniWrite\Auth\AuthService;
use OmniWrite\Auth\TwoFactorService;
use OmniWrite\Domain\AccessDeniedException;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Http\Request;
use OmniWrite\Http\Response;
use OmniWrite\Http\View;

final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly View $view,
        private readonly TwoFactorService $twoFactor,
        private readonly AuditLogger $audit,
    ) {
    }

    public function loginForm(Request $r): Response
    {
        return $r->user ? Response::redirect('/dashboard') : $this->view->render($r, 'login', ['errors' => [], 'old' => []]);
    }

    public function login(Request $r): Response
    {
        $email = $r->input('email');
        try {
            $user = $this->auth->attempt($email, $r->input('password'), $r->ip);
        } catch (AccessDeniedException) {
            return $this->view->render($r, 'login', ['errors' => ['form' => 'err.too_many_attempts'], 'old' => ['email' => $email]], 429);
        }
        if ($user === null) {
            return $this->view->render($r, 'login', ['errors' => ['form' => 'err.invalid_credentials'], 'old' => ['email' => $email]], 422);
        }
        $r->session->regenerate();
        if ($this->twoFactor->isEnabled($user)) {
            // Passwort stimmt, zweiter Faktor fehlt noch: noch keine angemeldete Sitzung.
            $r->session->set('pending_2fa', ['user_id' => $user->id, 'at' => time()]);
            return Response::redirect('/login/2fa');
        }
        $r->session->set('user_id', $user->id);
        return Response::redirect($user->isSuperadmin ? '/admin' : '/dashboard');
    }

    public function logout(Request $r): Response
    {
        if ($r->impersonator !== null && $r->user !== null) {
            $this->audit->log('admin.impersonate_stop', $r->impersonator->id, null, ['target_user_id' => $r->user->id, 'via' => 'logout'], $r->ip);
        }
        $r->session->clear();
        return Response::redirect('/login');
    }

    public function registerForm(Request $r): Response
    {
        if (!$this->auth->registrationEnabled()) {
            return Response::text('Not found', 404);
        }
        return $this->view->render($r, 'register', ['errors' => [], 'old' => []]);
    }

    public function register(Request $r): Response
    {
        if (!$this->auth->registrationEnabled()) {
            return Response::text('Not found', 404);
        }
        $input = [
            'email' => $r->input('email'), 'password' => $r->input('password'), 'name' => $r->input('name'),
            'account_name' => $r->input('account_name'), 'locale' => $r->locale,
        ];
        try {
            $user = $this->auth->register($input, $r->ip);
        } catch (ValidationException $e) {
            $old = $input;
            unset($old['password']);
            return $this->view->render($r, 'register', ['errors' => $e->errors, 'old' => $old], 422);
        }
        if (!$user->isActive()) {
            return $this->view->render($r, 'register_pending');
        }
        $r->session->regenerate();
        $r->session->set('user_id', $user->id);
        return Response::redirect('/dashboard');
    }
}
