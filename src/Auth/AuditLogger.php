<?php
declare(strict_types=1);

namespace OmniWrite\Auth;

use PDO;

final class AuditLogger
{
    private ?int $impersonatorId = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Während einer Impersonation hängt jeder Eintrag den echten Akteur (Superadmin) an. */
    public function setImpersonator(?int $adminId): void
    {
        $this->impersonatorId = $adminId;
    }

    /** @param array<string, mixed> $meta */
    public function log(string $action, ?int $userId = null, ?int $accountId = null, array $meta = [], ?string $ip = null): void
    {
        if ($this->impersonatorId !== null) {
            $meta['impersonator_id'] = $this->impersonatorId;
        }
        $this->pdo->prepare(
            'INSERT INTO audit_log (user_id, account_id, action, meta, ip) VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            $accountId,
            $action,
            $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $ip,
        ]);
    }
}
