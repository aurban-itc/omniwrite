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

    /** Datenbank, die nur den ursprünglichen Phase-0-Stand (0001 ohne plans) kennt. */
    public function testUpgradeFromOriginalBaseline(): void
    {
        if (!getenv('OMNIWRITE_TEST_DSN')) {
            self::markTestSkipped('braucht MySQL (OMNIWRITE_TEST_DSN); die CI führt ihn aus');
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['usage_events', 'domains', 'audit_log', 'login_attempts', 'account_users', 'users', 'plans'] as $t) {
            $this->pdo->exec("DROP TABLE IF EXISTS $t");
        }
        $this->pdo->exec('ALTER TABLE accounts DROP FOREIGN KEY fk_accounts_plan, DROP COLUMN plan_id');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $this->pdo->exec("DELETE FROM schema_migrations WHERE name <> '0001_baseline.sql'");

        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();

        $this->pdo->exec("INSERT INTO accounts (name, plan_id) SELECT 'A', id FROM plans WHERE code = 'default'");
        self::assertSame('1', (string) $this->pdo->query('SELECT COUNT(*) FROM accounts WHERE plan_id IS NOT NULL')->fetchColumn());
    }
}
