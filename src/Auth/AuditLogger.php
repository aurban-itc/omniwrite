<?php
declare(strict_types=1);

namespace OmniWrite\Auth;

use PDO;

final class AuditLogger
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $meta */
    public function log(string $action, ?int $userId = null, ?int $accountId = null, array $meta = [], ?string $ip = null): void
    {
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
