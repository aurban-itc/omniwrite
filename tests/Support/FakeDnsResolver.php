<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Support;

use OmniWrite\Net\DnsResolver;

final class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, list<string>> */
    public array $cnames = [];
    /** @var array<string, list<string>> */
    public array $addresses = [];

    public function cname(string $host): array
    {
        return $this->cnames[$host] ?? [];
    }

    public function addresses(string $host): array
    {
        return $this->addresses[$host] ?? [];
    }
}
