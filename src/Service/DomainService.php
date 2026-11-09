<?php
declare(strict_types=1);

namespace OmniWrite\Service;

use OmniWrite\Auth\AuditLogger;
use OmniWrite\Domain\AccessDeniedException;
use OmniWrite\Domain\User;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Net\DnsResolver;
use OmniWrite\Repository\DomainRepository;
use PDO;

/**
 * Eigene Domain pro Site. Ausgeliefert wird erst nach erfolgreicher DNS-Prüfung (CNAME auf das Portal oder gleiche IPv4-Adresse);
 * ein unverifizierter Eintrag schützt den Host nicht vor einem anderen Anspruch.
 */
final class DomainService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly DnsResolver $dns,
        private readonly string $portalHost,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function get(ResolvedSite $s): ?array
    {
        return (new DomainRepository($this->pdo))->forSite($s->id());
    }

    /** Kleingeschriebener Hostname oder null, wenn die Eingabe keine zulässige eigene Domain ist. */
    public function normalize(string $input): ?string
    {
        $host = trim($input);
        if (str_contains($host, '://')) {
            $host = (string) parse_url($host, PHP_URL_HOST);
        }
        $host = rtrim(strtolower(trim($host)), '.');
        if ($host === '' || strlen($host) > 190 || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', $host) !== 1) {
            return null;
        }
        if ($this->portalHost !== '' && ($host === $this->portalHost || str_ends_with($host, '.' . $this->portalHost))) {
            return null;
        }
        return $host;
    }

    public function set(ResolvedSite $s, User $actor, string $input, ?string $ip = null): void
    {
        $this->requireOwner($s);
        $host = $this->normalize($input);
        if ($host === null) {
            throw new ValidationException(['domain' => 'err.domain_invalid']);
        }
        $repo = new DomainRepository($this->pdo);
        $existing = $repo->findByHost($host);
        if ($existing !== null && (int) $existing['site_id'] !== $s->id() && $existing['verified_at'] !== null) {
            throw new ValidationException(['domain' => 'err.domain_taken']);
        }
        try {
            $repo->replaceForSite($s->id(), $host);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new ValidationException(['domain' => 'err.domain_taken']);
            }
            throw $e;
        }
        $this->audit->log('domain.set', $actor->id, $s->accountId(), ['site_id' => $s->id(), 'host' => $host], $ip);
    }

    /** @return bool true, wenn der Host jetzt auf das Portal zeigt und verifiziert wurde */
    public function verify(ResolvedSite $s, User $actor, ?string $ip = null): bool
    {
        $this->requireOwner($s);
        $repo = new DomainRepository($this->pdo);
        $row = $repo->forSite($s->id()) ?? throw new ValidationException(['domain' => 'err.domain_missing']);
        $host = (string) $row['host'];
        $ok = in_array($this->portalHost, $this->dns->cname($host), true);
        if (!$ok) {
            $mine = $this->dns->addresses($host);
            $ok = $mine !== [] && array_intersect($mine, $this->dns->addresses($this->portalHost)) !== [];
        }
        if ($ok && $row['verified_at'] === null) {
            $repo->markVerified($s->id(), gmdate('Y-m-d H:i:s'));
            $this->audit->log('domain.verify', $actor->id, $s->accountId(), ['site_id' => $s->id(), 'host' => $host], $ip);
        }
        return $ok;
    }

    public function remove(ResolvedSite $s, User $actor, ?string $ip = null): void
    {
        $this->requireOwner($s);
        $row = (new DomainRepository($this->pdo))->forSite($s->id());
        (new DomainRepository($this->pdo))->deleteForSite($s->id());
        if ($row !== null) {
            $this->audit->log('domain.remove', $actor->id, $s->accountId(), ['site_id' => $s->id(), 'host' => $row['host']], $ip);
        }
    }

    private function requireOwner(ResolvedSite $s): void
    {
        if (!$s->canPublish()) {
            throw new AccessDeniedException('Nur Owner dürfen die Domain ändern');
        }
    }
}
