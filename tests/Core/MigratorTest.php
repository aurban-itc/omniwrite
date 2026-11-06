<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Core;

use OmniWrite\Core\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    public function testAppliesInOrderOnceOnly(): void
    {
        $dir = sys_get_temp_dir() . '/omniwrite_mig_' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/0002_b.sql", 'INSERT INTO t (v) VALUES (2);');
        file_put_contents("$dir/0001_a.sql", 'CREATE TABLE t (v INTEGER);');

        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $m = new Migrator($pdo, $dir);

        self::assertSame(['0001_a.sql', '0002_b.sql'], $m->migrate());
        self::assertSame([], $m->migrate());
        self::assertSame('2', (string) $pdo->query('SELECT v FROM t')->fetchColumn());

        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
    }
}
