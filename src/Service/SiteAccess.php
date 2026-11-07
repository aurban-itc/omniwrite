<?php
declare(strict_types=1);

namespace OmniWrite\Service;

use OmniWrite\Domain\NotFoundException;
use OmniWrite\Domain\User;
use OmniWrite\Repository\AccountRepository;
use OmniWrite\Repository\SiteRepository;

/** Löst /sites/{slug} auf. Fremde und unbekannte Sites sehen gleich aus (NotFound), damit keine Slugs erraten werden können. */
final class SiteAccess
{
    public function __construct(private readonly SiteRepository $sites, private readonly AccountRepository $accounts)
    {
    }

    public function resolve(User $user, string $slug): ResolvedSite
    {
        $site = $this->sites->findBySlug($slug);
        if ($site === null) {
            throw new NotFoundException('Site nicht gefunden');
        }
        $role = $this->accounts->roleOf((int) $site['account_id'], $user->id);
        if ($role === null && !$user->isSuperadmin) {
            throw new NotFoundException('Site nicht gefunden');
        }
        return new ResolvedSite($site, $role, $user->isSuperadmin);
    }
}
