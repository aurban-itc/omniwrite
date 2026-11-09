<?php
declare(strict_types=1);

namespace OmniWrite\Service;

use OmniWrite\Auth\AuditLogger;
use OmniWrite\Domain\AccessDeniedException;
use OmniWrite\Domain\NotFoundException;
use OmniWrite\Domain\SiteContext;
use OmniWrite\Domain\User;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Mail\Mailer;
use OmniWrite\Repository\PostRepository;
use OmniWrite\Repository\RecipientRepository;
use OmniWrite\Repository\ReviewTokenRepository;
use OmniWrite\Repository\SiteRepository;
use OmniWrite\Repository\UsageRepository;
use OmniWrite\Support\Html;
use OmniWrite\Support\Translator;
use PDO;

/**
 * Review per Mail: Entwurf an die Empfänger der Site schicken, jeder mit eigenen Publish/Edit/Decline-Links.
 * Veröffentlicht wird nie automatisch, nur durch einen Klick (Bestätigungsseite) oder einen Owner in der Oberfläche.
 */
final class ReviewService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly Mailer $mailer,
        private readonly Translator $translator,
        private readonly string $baseUrl,
        private readonly int $linkHours = 72,
    ) {
    }

    public function mailReady(): bool
    {
        return $this->mailer->isConfigured();
    }

    /**
     * Setzt den Beitrag auf "in Prüfung" und mailt jedem aktiven Empfänger seine eigenen Links.
     *
     * @return array{sent: int, failed: list<string>}
     * @throws ValidationException|AccessDeniedException|NotFoundException
     */
    public function send(ResolvedSite $s, User $actor, int $postId, ?string $ip = null): array
    {
        if (!$s->canWrite()) {
            throw new AccessDeniedException('Keine Schreibrechte');
        }
        if (!$this->mailer->isConfigured()) {
            throw new ValidationException(['mail' => 'err.mail_not_configured']);
        }
        $posts = new PostRepository($this->pdo, $s->context);
        $post = $posts->find($postId) ?? throw new NotFoundException('Beitrag nicht gefunden');
        if ($post['status'] === 'published') {
            throw new ValidationException(['status' => 'err.status_transition']);
        }
        $recipients = (new RecipientRepository($this->pdo, $s->context))->listAll(true);
        if ($recipients === []) {
            throw new ValidationException(['recipients' => 'err.no_recipients']);
        }

        $now = gmdate('Y-m-d H:i:s');
        $previous = (string) $post['status'];
        $posts->update($postId, ['status' => 'in_review', 'reviewed_by' => null, 'reviewed_at' => null, 'review_note' => null, 'updated_at' => $now]);

        $tokens = new ReviewTokenRepository($this->pdo);
        $usage = new UsageRepository($this->pdo, $s->context);
        $sent = 0;
        $failed = [];
        foreach ($recipients as $r) {
            $raw = bin2hex(random_bytes(32));
            $tokens->insert($s->id(), $postId, (string) $r['email'], hash('sha256', $raw), gmdate('Y-m-d H:i:s', time() + $this->linkHours * 3600));
            [$subject, $html, $text] = $this->compose($s->site, $post, $raw, (string) $r['name']);
            if ($this->mailer->send((string) $r['email'], (string) $r['name'], $subject, $html, $text)) {
                $sent++;
                $usage->insert('mail', ['post_id' => $postId], gmdate('Y-m-d H:i:s'));
            } else {
                $failed[] = (string) $r['email'];
            }
        }
        if ($sent === 0) {
            $posts->update($postId, ['status' => $previous, 'updated_at' => gmdate('Y-m-d H:i:s')]); // nichts verschickt: Status zurück
        }
        $this->audit->log('review.send', $actor->id, $s->accountId(), ['site_id' => $s->id(), 'post_id' => $postId, 'sent' => $sent, 'failed' => count($failed)], $ip);
        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Löst den Token aus einem Mail-Link auf. null, wenn unbekannt oder abgelaufen (beides sieht gleich aus).
     *
     * @return array{context: SiteContext, site: array<string, mixed>, post: array<string, mixed>, email: string}|null
     */
    public function ticket(string $rawToken): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
            return null;
        }
        $row = (new ReviewTokenRepository($this->pdo))->findValid(hash('sha256', $rawToken), gmdate('Y-m-d H:i:s'));
        if ($row === null) {
            return null;
        }
        $site = (new SiteRepository($this->pdo))->find((int) $row['site_id']);
        $context = new SiteContext((int) $row['site_id']);
        $post = (new PostRepository($this->pdo, $context))->find((int) $row['post_id']);
        if ($site === null || $post === null) {
            return null;
        }
        return ['context' => $context, 'site' => $site, 'post' => $post, 'email' => (string) $row['email']];
    }

    /** @param array{context: SiteContext, site: array<string, mixed>, post: array<string, mixed>, email: string} $ticket */
    public function publish(array $ticket, ?string $ip = null): bool
    {
        return $this->decide($ticket, 'published', '', 'review.publish', $ip);
    }

    /** @param array{context: SiteContext, site: array<string, mixed>, post: array<string, mixed>, email: string} $ticket */
    public function decline(array $ticket, string $note, ?string $ip = null): bool
    {
        return $this->decide($ticket, 'declined', trim($note), 'review.decline', $ip);
    }

    /**
     * Änderungen des Reviewers (Titel, Text, Kurztext, Meta-Beschreibung), solange der Beitrag noch auf Entscheidung wartet.
     *
     * @param array{context: SiteContext, site: array<string, mixed>, post: array<string, mixed>, email: string} $ticket
     * @param array<string, mixed> $in
     * @throws ValidationException
     */
    public function edit(array $ticket, array $in, ?string $ip = null): bool
    {
        $title = trim((string) ($in['title'] ?? ''));
        $body = Html::sanitize((string) ($in['body_html'] ?? ''));
        $errors = [];
        if ($title === '' || mb_strlen($title) > 255) {
            $errors['title'] = 'err.title_required';
        }
        if (Html::text($body) === '') {
            $errors['body_html'] = 'err.body_empty';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $posts = new PostRepository($this->pdo, $ticket['context']);
        $current = $posts->find((int) $ticket['post']['id']);
        if ($current === null || !in_array($current['status'], ['draft', 'in_review'], true)) {
            return false;
        }
        $excerpt = trim((string) ($in['excerpt'] ?? ''));
        $ok = $posts->updateUnlessPublished((int) $current['id'], [
            'title' => $title, 'body_html' => $body,
            'excerpt' => $excerpt !== '' ? mb_substr($excerpt, 0, 600) : mb_substr(Html::text($body), 0, 300),
            'meta_description' => mb_substr(trim((string) ($in['meta_description'] ?? '')), 0, 320),
            'read_minutes' => Html::readMinutes($body), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        if ($ok) {
            $this->audit->log('review.edit', null, (int) $ticket['site']['account_id'], ['site_id' => $ticket['context']->siteId, 'post_id' => (int) $current['id'], 'by' => $ticket['email']], $ip);
        }
        return $ok;
    }

    /** @param array{context: SiteContext, site: array<string, mixed>, post: array<string, mixed>, email: string} $ticket */
    private function decide(array $ticket, string $status, string $note, string $action, ?string $ip): bool
    {
        $posts = new PostRepository($this->pdo, $ticket['context']);
        $ok = $posts->decide((int) $ticket['post']['id'], $status, $ticket['email'], $note, gmdate('Y-m-d H:i:s'));
        if ($ok) {
            $this->audit->log($action, null, (int) $ticket['site']['account_id'], ['site_id' => $ticket['context']->siteId, 'post_id' => (int) $ticket['post']['id'], 'by' => $ticket['email']], $ip);
        }
        return $ok;
    }

    /**
     * @param array<string, mixed> $site
     * @param array<string, mixed> $post
     * @return array{0: string, 1: string, 2: string} Betreff, HTML, Text
     */
    private function compose(array $site, array $post, string $token, string $name): array
    {
        $l = (string) $site['locale'];
        $t = fn (string $key, array $p = []): string => $this->translator->t($l, $key, $p);
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $url = fn (string $do): string => rtrim($this->baseUrl, '/') . '/review/' . $token . '/' . $do;
        $first = $name !== '' ? explode(' ', trim($name))[0] : '';
        $greet = $first !== '' ? $t('mail.review.hello', ['name' => $first]) . ' ' : '';
        $hours = $this->linkHours;

        $btn = static fn (string $href, string $label, string $bg): string => '<td style="padding:0 8px 8px 0"><a href="' . $href . '" style="display:inline-block;background:' . $bg
            . ';color:#ffffff;text-decoration:none;font:600 15px Arial,sans-serif;padding:12px 22px;border-radius:6px">' . $label . '</a></td>';
        $buttons = '<table role="presentation" cellpadding="0" cellspacing="0"><tr>'
            . $btn($e($url('publish')), $e($t('mail.review.publish')), '#1b7a3a') . $btn($e($url('edit')), $e($t('mail.review.edit')), '#0b2545') . $btn($e($url('decline')), $e($t('mail.review.decline')), '#a4262c')
            . '</tr></table>';
        $html = '<!doctype html><html><body style="margin:0;background:#f5f7fa;font:16px/1.6 Arial,sans-serif;color:#2d3a4a">'
            . '<div style="max-width:640px;margin:0 auto;padding:24px 12px"><div style="background:#ffffff;border-radius:10px;padding:28px">'
            . '<p style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#5b6775;margin:0 0 6px">' . $e((string) $site['name']) . ' · ' . $e($t('mail.review.kicker')) . '</p>'
            . '<h1 style="font-size:26px;line-height:1.2;color:#0b2545;margin:0 0 16px">' . $e((string) $post['title']) . '</h1>'
            . '<p>' . $e($greet . $t('mail.review.intro')) . '</p>' . $buttons
            . '<hr style="border:0;border-top:1px solid #d8dee6;margin:22px 0">' . Html::sanitize((string) $post['body_html'])
            . '<hr style="border:0;border-top:1px solid #d8dee6;margin:22px 0">' . $buttons
            . '<p style="font-size:12px;color:#5b6775;margin:14px 0 0">' . $e($t('mail.review.footer', ['hours' => $hours])) . '</p>'
            . '</div></div></body></html>';
        $text = $greet . $t('mail.review.intro') . "\n\n" . $post['title'] . "\n\n" . $post['excerpt'] . "\n\n"
            . $t('mail.review.publish') . ': ' . $url('publish') . "\n" . $t('mail.review.edit') . ': ' . $url('edit') . "\n" . $t('mail.review.decline') . ': ' . $url('decline') . "\n\n"
            . $t('mail.review.footer', ['hours' => $hours]) . "\n\n" . Html::text((string) $post['body_html']);
        return [$t('mail.review.subject', ['title' => (string) $post['title']]), $html, $text];
    }
}
