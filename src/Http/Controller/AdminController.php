<?php
declare(strict_types=1);

namespace OmniWrite\Http\Controller;

use OmniWrite\Auth\AuditLogger;
use OmniWrite\Http\Request;
use OmniWrite\Http\Response;
use OmniWrite\Http\View;
use OmniWrite\Repository\AccountRepository;
use OmniWrite\Repository\UserRepository;

/** Superadmin-Bereich (Routen sind mit Guard 'superadmin' geschützt). */
final class AdminController
{
    public function __construct(
        private readonly View $view,
        private readonly UserRepository $users,
        private readonly AccountRepository $accounts,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $r): Response
    {
        return $this->view->render($r, 'admin', [
            'pending' => $this->users->pending(),
            'accounts' => $this->accounts->allWithSiteCount(),
        ]);
    }

    public function activate(Request $r): Response
    {
        $id = (int) ($r->params['id'] ?? 0);
        if ($id > 0 && $this->users->setStatus($id, 'active')) {
            $this->audit->log('user.activate', $r->user->id, null, ['target_user_id' => $id], $r->ip);
        }
        return Response::redirect('/admin');
    }
}
