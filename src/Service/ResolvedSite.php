<?php
declare(strict_types=1);

namespace OmniWrite\Service;

use OmniWrite\Domain\Role;
use OmniWrite\Domain\SiteContext;

/** Eine Site, auf die der aktuelle Nutzer zugreifen darf, samt seiner Rechte darauf. */
final class ResolvedSite
{
    public readonly SiteContext $context;

    /** @param array<string, mixed> $site Zeile aus sites */
    public function __construct(public readonly array $site, public readonly ?Role $role, public readonly bool $superadmin)
    {
        $this->context = new SiteContext((int) $site['id']);
    }

    public function id(): int
    {
        return $this->context->siteId;
    }

    public function accountId(): int
    {
        return (int) $this->site['account_id'];
    }

    public function locale(): string
    {
        return (string) $this->site['locale'];
    }

    public function canWrite(): bool
    {
        return $this->superadmin || ($this->role?->canWriteContent() ?? false);
    }

    public function canPublish(): bool
    {
        return $this->superadmin || ($this->role?->canPublish() ?? false);
    }
}
