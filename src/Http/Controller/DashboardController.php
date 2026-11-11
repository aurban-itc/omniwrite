<?php
declare(strict_types=1);

namespace OmniWrite\Http\Controller;

use OmniWrite\Domain\AccessDeniedException;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Http\Request;
use OmniWrite\Http\Response;
use OmniWrite\Http\View;
use OmniWrite\Repository\AccountRepository;
use OmniWrite\Service\SiteService;

final class DashboardController
{
    public function __construct(
        private readonly View $view,
        private readonly AccountRepository $accounts,
        private readonly SiteService $sites,
    ) {
    }

    public function show(Request $r): Response
    {
        return $this->renderDashboard($r);
    }

    public function createSite(Request $r): Response
    {
        $membership = $this->currentMembership($r);
        if ($membership === null) {
            return Response::redirect('/admin');
        }
        try {
            $this->sites->create($r->user, $membership['id'], [
                'name' => $r->input('name'), 'slug' => $r->input('slug'),
                'locale' => $r->input('locale', 'de'), 'timezone' => $r->input('timezone', 'Europe/Berlin'),
            ], $r->ip);
        } catch (ValidationException $e) {
            return $this->renderDashboard($r, $e->errors, [
                'name' => $r->input('name'), 'slug' => $r->input('slug'), 'timezone' => $r->input('timezone'),
            ], 422);
        } catch (AccessDeniedException) {
            return Response::text('Forbidden', 403);
        }
        return Response::redirect('/dashboard');
    }

    /** @param array<string, string> $errors @param array<string, string> $old */
    private function renderDashboard(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $membership = $this->currentMembership($r);
        if ($membership === null) {
            return Response::redirect('/admin');
        }
        return $this->view->render($r, 'dashboard', [
            'account' => $membership,
            'sites' => $this->sites->listFor($r->user, $membership['id']),
            'canManage' => $membership['role'] === 'owner',
            'errors' => $errors,
            'old' => $old,
        ], $status);
    }

    /**
     * Aktueller Account: gewählter aus der Session, sonst der erste. Account-Wechsel folgt,
     * sobald Nutzer in mehreren Accounts vorkommen.
     *
     * @return array{id: int, name: string, role: string}|null
     */
    private function currentMembership(Request $r): ?array
    {
        $memberships = $this->accounts->membershipsOf($r->user->id);
        return $memberships[0] ?? null;
    }
}
