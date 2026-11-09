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
 * Eigene Domain pro Site. Ausgeliefert wird erst nach erfolgreicher DNS-Prüfung: Inhaber-Nachweis per TXT-Eintrag (Token aus Site und Host,
 * mit dem Master-Key signiert; ein CNAME auf das Portal allein beweist keine Inhaberschaft) plus Weg zum Portal (CNAME oder gleiche IPv4-Adresse).
 * Ein unverifizierter Eintrag schützt den Host nicht vor einem anderen Anspruch.
 */
final class DomainService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly DnsResolver $dns,
        private readonly string $portalHost,
        private readonly string $secret = '',
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
        if ($existing !== null && (int) $existing['site_id'] === $s->id()) {
            return; // unveränderter Host: bestehende Verifizierung bleibt erhalten
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

    /** TXT-Eintrag, mit dem der Domain-Inhaber nachweist, dass die Domain zu dieser Site gehört. @return array{name: string, value: string}|null */
    public function challenge(ResolvedSite $s, string $host): ?array
    {
        if ($this->secret === '') {
            return null;
        }
        $token = substr(hash_hmac('sha256', $s->id() . '|' . $host, $this->secret), 0, 32);
        return ['name' => '_omniwrite.' . $host, 'value' => 'omniwrite-verify=' . $token];
    }

    /** @return string 'ok' (verifiziert), 'txt' (Inhaber-Nachweis fehlt) oder 'dns' (Domain zeigt nicht auf das Portal) */
    public function verify(ResolvedSite $s, User $actor, ?string $ip = null): string
    {
        $this->requireOwner($s);
        $repo = new DomainRepository($this->pdo);
        $row = $repo->forSite($s->id()) ?? throw new ValidationException(['domain' => 'err.domain_missing']);
        $host = (string) $row['host'];
        if ($row['verified_at'] !== null) {
            return 'ok';
        }
        $challenge = $this->challenge($s, $host);
        if ($challenge === null || !in_array($challenge['value'], $this->dns->txt($challenge['name']), true)) {
            return 'txt';
        }
        $ok = in_array($this->portalHost, $this->dns->cname($host), true);
        if (!$ok) {
            $mine = $this->dns->addresses($host);
            $ok = $mine !== [] && array_intersect($mine, $this->dns->addresses($this->portalHost)) !== [];
        }
        if (!$ok) {
            return 'dns';
        }
        $repo->markVerified($s->id(), gmdate('Y-m-d H:i:s'));
        $this->audit->log('domain.verify', $actor->id, $s->accountId(), ['site_id' => $s->id(), 'host' => $host], $ip);
        return 'ok';
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
