<?php
declare(strict_types=1);

namespace OmniWrite\Http\Controller;

use OmniWrite\Domain\AccessDeniedException;
use OmniWrite\Http\Request;
use OmniWrite\Http\Response;
use OmniWrite\Http\View;
use OmniWrite\Repository\RunRepository;
use OmniWrite\Service\ResolvedSite;
use OmniWrite\Service\ScheduleService;
use OmniWrite\Service\SiteAccess;
use OmniWrite\Service\WeeklyRunner;
use PDO;

/** Zeitplan, Wochenlauf-Protokoll und "Jetzt ausführen" einer Site. */
final class ScheduleController extends SiteController
{
    public function __construct(
        SiteAccess $access,
        private readonly View $view,
        private readonly ScheduleService $schedule,
        private readonly WeeklyRunner $runner,
        private readonly PDO $pdo,
    ) {
        parent::__construct($access);
    }

    public function index(Request $r): Response
    {
        return $this->withSite($r, fn (ResolvedSite $s): Response => $this->render($r, $s));
    }

    public function save(Request $r): Response
    {
        return $this->withSite($r, function (ResolvedSite $s) use ($r): Response {
            $this->schedule->save($s, $r->user, [
                'enabled' => $r->input('enabled') === '1', 'weekday' => (int) $r->input('weekday', '1'), 'hour' => (int) $r->input('hour', '6'),
            ], $r->ip);
            return Response::redirect($this->url($s, '/schedule?saved=1'));
        });
    }

    public function run(Request $r): Response
    {
        return $this->withSite($r, function (ResolvedSite $s) use ($r): Response {
            if (!$s->canPublish()) {
                throw new AccessDeniedException('Nur Owner');
            }
            $res = $this->runner->runSite($s->site);
            return $this->render($r, $s, $res);
        });
    }

    /** @param array{status: string, message: string}|null $result */
    private function render(Request $r, ResolvedSite $s, ?array $result = null): Response
    {
        return $this->view->render($r, 'schedule', [
            'rs' => $s, 'site' => $s->site, 'cfg' => $this->schedule->get($s), 'saved' => isset($r->query['saved']),
            'runs' => (new RunRepository($this->pdo, $s->context))->recent(10), 'result' => $result,
        ]);
    }
}
