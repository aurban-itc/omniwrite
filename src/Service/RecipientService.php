<?php
declare(strict_types=1);

namespace OmniWrite\Service;

use OmniWrite\Auth\AuditLogger;
use OmniWrite\Domain\AccessDeniedException;
use OmniWrite\Domain\NotFoundException;
use OmniWrite\Domain\User;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Repository\RecipientRepository;
use PDO;

/** Empfänger der Review-Mail pro Site. Pflegen dürfen nur Owner (sie bestimmen, wer Beiträge freigeben kann). */
final class RecipientService
{
    public function __construct(private readonly PDO $pdo, private readonly AuditLogger $audit)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(ResolvedSite $s): array
    {
        return $this->repo($s)->listAll();
    }

    public function add(ResolvedSite $s, User $actor, string $email, string $name, ?string $ip = null): int
    {
        $this->requireOwner($s);
        $email = mb_strtolower(trim($email));
        if ($email === '' || mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException(['email' => 'err.email_invalid']);
        }
        $repo = $this->repo($s);
        if ($repo->emailTaken($email)) {
            throw new ValidationException(['email' => 'err.recipient_exists']);
        }
        $id = $repo->insert(['email' => $email, 'name' => mb_substr(trim($name), 0, 120), 'active' => 1]);
        $this->audit->log('recipient.add', $actor->id, $s->accountId(), ['site_id' => $s->id(), 'recipient_id' => $id], $ip);
        return $id;
    }

    public function toggle(ResolvedSite $s, User $actor, int $id, ?string $ip = null): void
    {
        $this->requireOwner($s);
        $row = $this->repo($s)->find($id) ?? throw new NotFoundException('Empfänger nicht gefunden');
        $this->repo($s)->update($id, ['active' => (int) $row['active'] === 1 ? 0 : 1]);
        $this->audit->log('recipient.toggle', $actor->id, $s->accountId(), ['site_id' => $s->id(), 'recipient_id' => $id], $ip);
    }

    public function remove(ResolvedSite $s, User $actor, int $id, ?string $ip = null): void
    {
        $this->requireOwner($s);
        if (!$this->repo($s)->delete($id)) {
            throw new NotFoundException('Empfänger nicht gefunden');
        }
        $this->audit->log('recipient.remove', $actor->id, $s->accountId(), ['site_id' => $s->id(), 'recipient_id' => $id], $ip);
    }

    private function requireOwner(ResolvedSite $s): void
    {
        if (!$s->canPublish()) {
            throw new AccessDeniedException('Nur Owner dürfen Empfänger pflegen');
        }
    }

    private function repo(ResolvedSite $s): RecipientRepository
    {
        return new RecipientRepository($this->pdo, $s->context);
    }
}
