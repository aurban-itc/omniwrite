<?php
declare(strict_types=1);

namespace OmniWrite\Http\Controller;

use InvalidArgumentException;
use OmniWrite\Domain\NotFoundException;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Http\Request;
use OmniWrite\Http\Response;
use OmniWrite\Http\View;
use OmniWrite\Import\CoastalImporter;
use OmniWrite\Repository\AccountRepository;
use OmniWrite\Repository\SiteRepository;
use OmniWrite\Repository\UserRepository;
use OmniWrite\Service\AdminService;
use OmniWrite\Service\SiteService;
use OmniWrite\Service\UsageReport;

/** Superadmin-Bereich (Routen sind mit Guard 'superadmin' geschützt). */
final class AdminController
{
    private const MAX_IMPORT_BYTES = 200 * 1024 * 1024;

    public function __construct(
        private readonly View $view,
        private readonly UserRepository $users,
        private readonly AccountRepository $accounts,
        private readonly SiteRepository $sites,
        private readonly AdminService $admin,
        private readonly SiteService $siteService,
        private readonly CoastalImporter $importer,
        private readonly UsageReport $usageReport,
    ) {
    }

    public function index(Request $r): Response
    {
        return $this->view->render($r, 'admin', [
            'pending' => $this->users->pending(),
            'accounts' => $this->accounts->allWithSiteCount(),
            'impersonable' => $this->users->listImpersonable(),
        ]);
    }

    public function activate(Request $r): Response
    {
        return $this->userStatus($r, 'active');
    }

    public function reject(Request $r): Response
    {
        return $this->userStatus($r, 'disabled');
    }

    public function disable(Request $r): Response
    {
        return $this->userStatus($r, 'disabled', $this->back($r));
    }

    public function enable(Request $r): Response
    {
        return $this->userStatus($r, 'active', $this->back($r));
    }

    public function resetPassword(Request $r): Response
    {
        $id = (int) ($r->params['id'] ?? 0);
        try {
            $this->admin->resetPassword($r->user, $id, $r->input('password'), $r->ip);
        } catch (ValidationException $e) {
            return $this->detailOrIndexError($r, $e->errors, 'reset');
        } catch (NotFoundException) {
            return Response::text('Not found', 404);
        }
        return Response::redirect($this->back($r, '?saved=1'));
    }

    public function sites(Request $r): Response
    {
        return $this->view->render($r, 'admin_sites', ['sites' => $this->sites->allWithAccount()]);
    }

    public function usage(Request $r): Response
    {
        $month = UsageReport::normalizeMonth(is_string($r->query['month'] ?? null) ? $r->query['month'] : null);
        $report = $this->usageReport->forMonth($month);
        if (($r->query['format'] ?? '') === 'csv') {
            return new Response(200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="omniwrite-nutzung-' . $month . '.csv"',
                'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store',
            ], UsageReport::csv($month, $report));
        }
        $dt = new \DateTimeImmutable($month . '-01');
        return $this->view->render($r, 'admin_usage', [
            'month' => $month, 'report' => $report,
            'prev' => $dt->modify('-1 month')->format('Y-m'), 'next' => $dt->modify('+1 month')->format('Y-m'),
            'isCurrent' => $month === gmdate('Y-m'),
        ]);
    }

    public function newAccountForm(Request $r): Response
    {
        return $this->view->render($r, 'admin_account_new', ['errors' => [], 'old' => []]);
    }

    public function createAccount(Request $r): Response
    {
        $input = $this->userInput($r) + ['account_name' => $r->input('account_name')];
        try {
            $id = $this->admin->createAccount($r->user, $input, $r->ip);
        } catch (ValidationException $e) {
            $old = $input;
            unset($old['password']);
            return $this->view->render($r, 'admin_account_new', ['errors' => $e->errors, 'old' => $old], 422);
        }
        return Response::redirect('/admin/accounts/' . $id . '?created=1');
    }

    public function account(Request $r): Response
    {
        return $this->renderAccount($r);
    }

    public function rename(Request $r): Response
    {
        return $this->mutate($r, 'rename', fn (int $id) => $this->admin->renameAccount($r->user, $id, $r->input('account_name'), $r->ip));
    }

    public function addMember(Request $r): Response
    {
        return $this->mutate($r, 'member', fn (int $id) => $this->admin->addMember($r->user, $id, $this->userInput($r) + ['role' => $r->input('role', 'editor')], $r->ip));
    }

    public function memberRole(Request $r): Response
    {
        return $this->mutate($r, 'role', fn (int $id) => $this->admin->setMemberRole($r->user, $id, (int) ($r->params['uid'] ?? 0), $r->input('role'), $r->ip));
    }

    public function removeMember(Request $r): Response
    {
        return $this->mutate($r, 'role', fn (int $id) => $this->admin->removeMember($r->user, $id, (int) ($r->params['uid'] ?? 0), $r->ip));
    }

    public function createSite(Request $r): Response
    {
        return $this->mutate($r, 'site', fn (int $id) => $this->siteService->create($r->user, $id, [
            'name' => $r->input('name'), 'slug' => $r->input('slug'),
            'locale' => $r->input('locale', 'de'), 'timezone' => $r->input('timezone', 'Europe/Berlin'),
        ], $r->ip));
    }

    public function importForm(Request $r): Response
    {
        return $this->renderImport($r);
    }

    public function import(Request $r): Response
    {
        $site = $this->sites->find((int) $r->input('site_id'));
        if ($site === null) {
            return $this->renderImport($r, ['site_id' => 'err.import_site'], null, 422);
        }
        $file = $r->files['export'] ?? null;
        $err = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK || !is_file((string) $file['tmp_name'])) {
            return $this->renderImport($r, ['export' => $err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE ? 'err.import_too_big' : 'err.import_file'], (int) $site['id'], 422);
        }
        if ((int) filesize((string) $file['tmp_name']) > self::MAX_IMPORT_BYTES) {
            return $this->renderImport($r, ['export' => 'err.import_too_big'], (int) $site['id'], 422);
        }
        $bundle = json_decode((string) file_get_contents((string) $file['tmp_name']), true);
        if (!is_array($bundle)) {
            return $this->renderImport($r, ['export' => 'err.import_invalid'], (int) $site['id'], 422);
        }
        @set_time_limit(300);
        try {
            $result = $this->importer->import((int) $site['id'], $bundle);
        } catch (InvalidArgumentException $e) {
            return $this->renderImport($r, ['export' => 'err.import_invalid'], (int) $site['id'], 422, $e->getMessage());
        }
        return $this->renderImport($r, [], (int) $site['id'], 200, null, $result);
    }

    /** @param array<string, string> $errors @param array<string, mixed>|null $result */
    private function renderImport(Request $r, array $errors = [], ?int $selected = null, int $status = 200, ?string $detail = null, ?array $result = null): Response
    {
        return $this->view->render($r, 'admin_import', [
            'sites' => $sites = $this->sites->allWithAccount(), 'errors' => $errors, 'selected' => $selected,
            'detail' => $detail, 'result' => $result,
            'storage' => array_map(fn (array $s): array => ['site' => $s] + $this->importer->storageReport((int) $s['id']), $sites),
        ], $status);
    }

    private function userStatus(Request $r, string $status, string $to = '/admin'): Response
    {
        try {
            $this->admin->setUserStatus($r->user, (int) ($r->params['id'] ?? 0), $status, $r->ip);
        } catch (NotFoundException) {
            return Response::text('Not found', 404);
        }
        return Response::redirect($to);
    }

    /**
     * Führt eine Änderung an einem Account aus; Eingabefehler zeigen die Detailseite erneut (Formular $form).
     *
     * @param callable(int): mixed $fn
     */
    private function mutate(Request $r, string $form, callable $fn): Response
    {
        $id = (int) ($r->params['id'] ?? 0);
        try {
            $fn($id);
        } catch (NotFoundException) {
            return Response::text('Not found', 404);
        } catch (ValidationException $e) {
            return $this->renderAccount($r, $e->errors, $form, 422);
        } catch (\OmniWrite\Domain\AccessDeniedException) {
            return Response::text('Forbidden', 403);
        }
        return Response::redirect('/admin/accounts/' . $id . '?saved=1');
    }

    /** @param array<string, string> $errors */
    private function renderAccount(Request $r, array $errors = [], string $form = '', int $status = 200): Response
    {
        $id = (int) ($r->params['id'] ?? 0);
        $account = $this->accounts->find($id);
        if ($account === null) {
            return Response::text('Not found', 404);
        }
        $old = $r->post;
        unset($old['password'], $old['_csrf']);
        return $this->view->render($r, 'admin_account', [
            'account' => $account,
            'members' => $this->accounts->members($id),
            'sites' => $this->sites->forAccount($id),
            'errors' => $errors, 'form' => $form, 'old' => $old,
            'saved' => isset($r->query['saved']) || isset($r->query['created']),
            'created' => isset($r->query['created']),
        ], $status);
    }

    /** @return array<string, string> */
    private function userInput(Request $r): array
    {
        return [
            'email' => $r->input('email'), 'name' => $r->input('name'),
            'password' => $r->input('password'), 'locale' => $r->input('locale', 'de'),
        ];
    }

    /** Rücksprung: bei Aktionen aus der Account-Detailseite dorthin, sonst zur Übersicht. */
    private function back(Request $r, string $suffix = ''): string
    {
        $account = (int) $r->input('account_id');
        return $account > 0 ? '/admin/accounts/' . $account . $suffix : '/admin' . $suffix;
    }

    /** @param array<string, string> $errors */
    private function detailOrIndexError(Request $r, array $errors, string $form): Response
    {
        $account = (int) $r->input('account_id');
        if ($account > 0) {
            $r->params['id'] = (string) $account;
            return $this->renderAccount($r, $errors, $form, 422);
        }
        return Response::redirect('/admin');
    }
}
