<?php
declare(strict_types=1);

namespace OmniWrite\Http\Controller;

use OmniWrite\Auth\AuditLogger;
use OmniWrite\Http\Request;
use OmniWrite\Http\Response;
use OmniWrite\Repository\UserRepository;

/** Support: Superadmin meldet sich vorübergehend als Kunde an. Jeder Schritt wird protokolliert. */
final class ImpersonationController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuditLogger $audit,
    ) {
    }

    /** Route-Guard 'superadmin': nur ein echter (nicht bereits imitierender) Superadmin kommt hierher. */
    public function start(Request $r): Response
    {
        $target = $this->users->find((int) ($r->params['id'] ?? 0));
        if ($target === null || !$target->isActive() || $target->isSuperadmin) {
            return Response::text('Not found', 404);
        }
        $r->session->regenerate();
        $r->session->set('impersonator_id', $r->user->id);
        $r->session->set('user_id', $target->id);
        $this->audit->log('admin.impersonate_start', $r->user->id, null, ['target_user_id' => $target->id], $r->ip);
        return Response::redirect('/dashboard');
    }

    public function stop(Request $r): Response
    {
        if ($r->impersonator === null) {
            return Response::redirect('/dashboard');
        }
        $targetId = $r->user->id;
        $r->session->regenerate();
        $r->session->forget('impersonator_id');
        $r->session->set('user_id', $r->impersonator->id);
        $this->audit->setImpersonator(null);
        $this->audit->log('admin.impersonate_stop', $r->impersonator->id, null, ['target_user_id' => $targetId], $r->ip);
        return Response::redirect('/admin');
    }
}
