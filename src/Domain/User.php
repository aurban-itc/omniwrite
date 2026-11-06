<?php
declare(strict_types=1);

namespace OmniWrite\Domain;

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $name,
        public readonly string $locale,
        public readonly bool $isSuperadmin,
        public readonly string $status,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['email'],
            (string) $row['name'],
            (string) $row['locale'],
            (bool) $row['is_superadmin'],
            (string) $row['status'],
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
