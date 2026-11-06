<?php
declare(strict_types=1);

namespace OmniWrite\Service;

use DateTimeZone;
use OmniWrite\Auth\AuditLogger;
use OmniWrite\Domain\AccessDeniedException;
use OmniWrite\Domain\User;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Repository\AccountRepository;
use OmniWrite\Repository\SiteRepository;

/** Berechtigungen und Validierung rund um Sites. Controller rufen nur diesen Service, nie die Repositories. */
final class SiteService
{
    private const RESERVED_SLUGS = ['admin', 'api', 'assets', 'b', 'login', 'logout', 'register', 'static', 'www', 'health'];

    public function __construct(
        private readonly SiteRepository $sites,
        private readonly AccountRepository $accounts,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function listFor(User $user, int $accountId): array
    {
        if (!$user->isSuperadmin && $this->accounts->roleOf($accountId, $user->id) === null) {
            throw new AccessDeniedException('Kein Zugriff auf diesen Account');
        }
        return $this->sites->forAccount($accountId);
    }

    /** @param array<string, mixed> $input name, slug, locale, timezone */
    public function create(User $user, int $accountId, array $input, ?string $ip = null): int
    {
        $role = $this->accounts->roleOf($accountId, $user->id);
        if (!$user->isSuperadmin && ($role === null || !$role->canManageSites())) {
            throw new AccessDeniedException('Sites anlegen ist Owners vorbehalten');
        }
        $name = trim((string) ($input['name'] ?? ''));
        $slug = strtolower(trim((string) ($input['slug'] ?? '')));
        $locale = (string) ($input['locale'] ?? 'de');
        $timezone = (string) ($input['timezone'] ?? 'Europe/Berlin');

        $errors = [];
        if ($name === '' || mb_strlen($name) > 190) {
            $errors['name'] = 'err.name_required';
        }
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{1,38})[a-z0-9]$/', $slug)) {
            $errors['slug'] = 'err.slug_invalid';
        } elseif (in_array($slug, self::RESERVED_SLUGS, true)) {
            $errors['slug'] = 'err.slug_reserved';
        } elseif ($this->sites->slugExists($slug)) {
            $errors['slug'] = 'err.slug_taken';
        }
        if (!in_array($locale, ['de', 'en'], true)) {
            $errors['locale'] = 'err.locale_invalid';
        }
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'] = 'err.timezone_invalid';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $id = $this->sites->create($accountId, $slug, $name, $locale, $timezone);
        $this->audit->log('site.create', $user->id, $accountId, ['site_id' => $id, 'slug' => $slug], $ip);
        return $id;
    }
}
