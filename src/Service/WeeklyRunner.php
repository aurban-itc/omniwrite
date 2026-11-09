<?php
declare(strict_types=1);

namespace OmniWrite\Service;

use DateTimeImmutable;
use OmniWrite\Ai\AiException;
use OmniWrite\Domain\Role;
use OmniWrite\Domain\User;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Mail\Mailer;
use OmniWrite\Repository\RunRepository;
use OmniWrite\Repository\UserRepository;
use OmniWrite\Support\Translator;
use PDO;
use Throwable;

/**
 * Wochenlauf für alle Sites mit Zeitplan: nächstes Thema → Entwurf (KI) → Review-Mail.
 * Handelt im Namen des ersten Owners des Accounts. Bei Fehlern bekommen die Owner eine Mail, der Lauf steht im Protokoll.
 */
final class WeeklyRunner
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ScheduleService $schedule,
        private readonly AiService $ai,
        private readonly TopicService $topics,
        private readonly ReviewService $review,
        private readonly Mailer $mailer,
        private readonly Translator $translator,
    ) {
    }

    /** @return list<array{site: string, status: string, message: string}> */
    public function runDue(DateTimeImmutable $nowUtc): array
    {
        $out = [];
        foreach ($this->schedule->scheduledSites() as $site) {
            if (!$this->schedule->isDue($site, $nowUtc) || !$this->schedule->claimWeek($site, $nowUtc)) {
                continue;
            }
            $out[] = ['site' => (string) $site['slug']] + $this->runSite($site);
        }
        return $out;
    }

    /**
     * Ein Lauf für eine Site (auch für "Jetzt ausführen" in der Oberfläche).
     *
     * @param array<string, mixed> $site
     * @return array{status: string, message: string, post_id?: int}
     */
    public function runSite(array $site): array
    {
        $l = (string) $site['locale'];
        $t = fn (string $key, array $p = []): string => $this->translator->t($l, $key, $p);
        $owners = $this->owners((int) $site['account_id']);
        $postId = null;
        try {
            if ($owners === []) {
                return $this->fail($site, null, $t('run.err.no_owner'), $owners);
            }
            $rs = new ResolvedSite($site, Role::Owner, false);
            $actor = $owners[0];
            if (!$this->ai->textReady()) {
                return $this->fail($site, null, $t('run.err.ai'), $owners);
            }
            $topic = $this->topics->nextFor($rs);
            if ($topic === null) {
                return $this->fail($site, null, $t('run.err.no_topic'), $owners);
            }
            $postId = $this->ai->generateDraft($rs, $actor, ['topic_id' => (int) $topic['id']], null, true);
            $res = $this->review->send($rs, $actor, $postId);
            if ($res['sent'] === 0) {
                return $this->fail($site, $postId, $t('run.err.send_failed', ['title' => (string) $topic['title']]), $owners);
            }
            $msg = $t('run.ok', ['title' => (string) $topic['title'], 'sent' => $res['sent']]) . ($res['failed'] !== [] ? ' ' . $t('run.failed_to', ['list' => implode(', ', $res['failed'])]) : '');
            $this->log($site, 'ok', $postId, $msg);
            return ['status' => 'ok', 'message' => $msg, 'post_id' => $postId];
        } catch (ValidationException $e) {
            return $this->fail($site, $postId, $t(array_values($e->errors)[0] ?? 'run.err.generic'), $owners);
        } catch (AiException | UsageLimitException $e) {
            return $this->fail($site, $postId, $t($e->getMessage()), $owners);
        } catch (Throwable $e) {
            error_log('weekly run site ' . $site['id'] . ': ' . $e);
            return $this->fail($site, $postId, $t('run.err.generic'), $owners);
        }
    }

    /**
     * @param array<string, mixed> $site
     * @param list<User> $owners
     * @return array{status: string, message: string, post_id?: int}
     */
    private function fail(array $site, ?int $postId, string $message, array $owners): array
    {
        $this->log($site, 'failed', $postId, $message);
        $l = (string) $site['locale'];
        foreach ($owners as $o) {
            $this->mailer->send(
                $o->email, $o->name,
                $this->translator->t($l, 'mail.run_failed.subject', ['site' => (string) $site['name']]),
                '<p>' . htmlspecialchars($this->translator->t($l, 'mail.run_failed.body', ['site' => (string) $site['name'], 'reason' => $message]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>',
                $this->translator->t($l, 'mail.run_failed.body', ['site' => (string) $site['name'], 'reason' => $message]),
            );
        }
        return ['status' => 'failed', 'message' => $message] + ($postId !== null ? ['post_id' => $postId] : []);
    }

    /** @param array<string, mixed> $site */
    private function log(array $site, string $status, ?int $postId, string $message): void
    {
        (new RunRepository($this->pdo, new \OmniWrite\Domain\SiteContext((int) $site['id'])))->insert([
            'status' => $status, 'post_id' => $postId, 'message' => mb_substr($message, 0, 600), 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @return list<User> aktive Owner des Accounts, dienstälteste zuerst */
    private function owners(int $accountId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT u.id FROM account_users au JOIN users u ON u.id = au.user_id WHERE au.account_id = ? AND au.role = 'owner' AND u.status = 'active' ORDER BY au.user_id"
        );
        $stmt->execute([$accountId]);
        $users = new UserRepository($this->pdo);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (($u = $users->find((int) $id)) !== null) {
                $out[] = $u;
            }
        }
        return $out;
    }
}
