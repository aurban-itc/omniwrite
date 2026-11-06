<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Core;

use OmniWrite\Core\Migrator;
use OmniWrite\Tests\Support\DbTestCase;

/** Simuliert "DDL lief, Verbuchung nicht": alle Migrationen müssen sich auf MySQL erneut anwenden lassen. */
final class MigrationReplayTest extends DbTestCase
{
    public function testMigrationsCanBeReplayedAfterInterruption(): void
    {
        if (!getenv('OMNIWRITE_TEST_DSN')) {
            self::markTestSkipped('braucht MySQL (OMNIWRITE_TEST_DSN); die CI führt ihn aus');
        }
        $this->pdo->exec('DELETE FROM schema_migrations');
        $applied = (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        self::assertNotSame([], $applied);
        self::assertSame('1', (string) $this->pdo->query("SELECT COUNT(*) FROM plans WHERE code = 'default'")->fetchColumn());
    }
}
