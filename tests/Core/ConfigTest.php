<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Core;

use OmniWrite\Core\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDotNotationAndDefault(): void
    {
        $c = new Config(['db' => ['dsn' => 'x']]);
        self::assertSame('x', $c->get('db.dsn'));
        self::assertSame('fallback', $c->get('db.missing', 'fallback'));
        self::assertNull($c->get('nope.deep'));
    }

    public function testExampleConfigIsValid(): void
    {
        $c = Config::fromFile(__DIR__ . '/../../config/config.example.php');
        self::assertSame('de', $c->get('app.default_locale'));
    }
}
