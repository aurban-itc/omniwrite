<?php
declare(strict_types=1);

namespace OmniWrite\Service;

use OmniWrite\Auth\AuditLogger;
use OmniWrite\Domain\AccessDeniedException;
use OmniWrite\Domain\User;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Repository\SiteSettingsRepository;
use OmniWrite\Support\Html;
use PDO;

/** Aussehen des öffentlichen Blogs (Theme). Leer speichern stellt den Standard wieder her. */
final class BlogSettingsService
{
    public const KEYS = ['blog_tagline', 'theme_accent', 'theme_font', 'blog_footer', 'home_url'];
    public const DEFAULT_ACCENT = '#1a56db';
    public const FONTS = ['sans', 'serif'];

    public function __construct(private readonly PDO $pdo, private readonly AuditLogger $audit)
    {
    }

    /** @return array<string, string> alle Schlüssel mit Standardwerten; ungültig gespeicherte Werte fallen auf den Standard zurück */
    public function theme(\OmniWrite\Domain\SiteContext $site): array
    {
        $repo = new SiteSettingsRepository($this->pdo, $site);
        $out = [];
        foreach (self::KEYS as $key) {
            $out[$key] = $repo->get($key, '');
        }
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $out['theme_accent']) !== 1) {
            $out['theme_accent'] = self::DEFAULT_ACCENT;
        }
        if (!in_array($out['theme_font'], self::FONTS, true)) {
            $out['theme_font'] = 'sans';
        }
        if ($out['home_url'] !== '' && !str_starts_with(strtolower($out['home_url']), 'https://')) {
            $out['home_url'] = '';
        }
        return $out;
    }

    /** @param array<string, mixed> $in */
    public function save(ResolvedSite $s, User $actor, array $in, ?string $ip = null): void
    {
        if (!$s->canPublish()) {
            throw new AccessDeniedException('Nur Owner dürfen das Blog-Design ändern');
        }
        $values = [];
        foreach (self::KEYS as $key) {
            $values[$key] = trim((string) ($in[$key] ?? ''));
        }
        $errors = [];
        if (mb_strlen($values['blog_tagline']) > 160) {
            $errors['blog_tagline'] = 'err.short_too_long';
        }
        if (mb_strlen($values['blog_footer']) > 300) {
            $errors['blog_footer'] = 'err.short_too_long';
        }
        if ($values['theme_accent'] !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $values['theme_accent']) !== 1) {
            $errors['theme_accent'] = 'err.accent_invalid';
        }
        if ($values['theme_font'] !== '' && !in_array($values['theme_font'], self::FONTS, true)) {
            $errors['theme_font'] = 'err.font_invalid';
        }
        if ($values['home_url'] !== '' && (mb_strlen($values['home_url']) > 300 || !str_starts_with(strtolower($values['home_url']), 'https://') || !Html::safeUrl($values['home_url'], true))) {
            $errors['home_url'] = 'err.home_url_invalid';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $repo = new SiteSettingsRepository($this->pdo, $s->context);
        foreach ($values as $key => $value) {
            $value === '' ? $repo->delete($key) : $repo->set($key, $value);
        }
        $this->audit->log('blog.settings', $actor->id, $s->accountId(), ['site_id' => $s->id()], $ip);
    }
}
