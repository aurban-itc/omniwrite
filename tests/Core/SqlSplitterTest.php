<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Core;

use OmniWrite\Core\SqlSplitter;
use PHPUnit\Framework\TestCase;

final class SqlSplitterTest extends TestCase
{
    public function testSplitsOnSemicolonOutsideStringsAndComments(): void
    {
        $sql = "-- Kommentar; mit Semikolon\nCREATE TABLE a (x INT); /* auch; hier */\nSET @s = 'a;b''c';\nDO 0;\n-- Ende ohne Statement";
        self::assertSame(["CREATE TABLE a (x INT)", "SET @s = 'a;b''c'", 'DO 0'], SqlSplitter::split($sql));
    }

    public function testBackslashEscapeAndBackticks(): void
    {
        self::assertSame(["SELECT 'it\\'s; ok'", 'SELECT `a;b`'], SqlSplitter::split("SELECT 'it\\'s; ok'; SELECT `a;b`;"));
    }

    public function testEmptyInput(): void
    {
        self::assertSame([], SqlSplitter::split("  \n-- nur Kommentar\n"));
    }

    public function testAllMigrationFilesSplitIntoWholeStatements(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/migrations/*.sql') as $file) {
            $parts = SqlSplitter::split((string) file_get_contents($file));
            self::assertNotEmpty($parts, $file);
            foreach ($parts as $p) {
                self::assertMatchesRegularExpression('/^(CREATE|ALTER|SET|PREPARE|EXECUTE|DEALLOCATE|INSERT|UPDATE|DO)\b/i', $p, basename($file));
            }
        }
    }
}
