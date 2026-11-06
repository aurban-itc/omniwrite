<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Support;

use OmniWrite\Core\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Datenbank-Tests laufen standardmäßig gegen SQLite (schnell, lokal).
 * Mit OMNIWRITE_TEST_DSN (+ _USER/_PASSWORD) laufen sie gegen MySQL mit den echten Migrationen (CI).
 */
abstract class DbTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        $dsn = getenv('OMNIWRITE_TEST_DSN');
        if ($dsn) {
            $this->pdo = new PDO($dsn, getenv('OMNIWRITE_TEST_USER') ?: null, getenv('OMNIWRITE_TEST_PASSWORD') ?: null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
                $this->pdo->exec('DROP TABLE `' . $table . '`');
            }
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
            return;
        }
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        SqliteSchema::create($this->pdo);
    }
}
