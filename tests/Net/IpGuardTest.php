<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Net;

use OmniWrite\Net\IpGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpGuardTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function blocked(): array
    {
        $ips = ['0.0.0.0', '10.1.2.3', '100.64.0.1', '127.0.0.1', '169.254.169.254', '172.16.0.1', '172.31.255.255', '192.168.1.1',
            '198.18.0.1', '224.0.0.1', '255.255.255.255', '::', '::1', 'fe80::1', 'fc00::1', 'fd12:3456::1', '::ffff:127.0.0.1',
            '::ffff:10.0.0.1', '64:ff9b::7f00:1', '2001:db8::1', '2002:7f00:1::', 'ff02::1', 'not-an-ip'];
        return array_combine($ips, array_map(static fn (string $i): array => [$i], $ips));
    }

    #[DataProvider('blocked')]
    public function testBlocksNonPublicAddresses(string $ip): void
    {
        self::assertFalse(IpGuard::isPublic($ip));
    }

    public function testAllowsPublicAddresses(): void
    {
        foreach (['8.8.8.8', '1.1.1.1', '93.184.216.34', '172.32.0.1', '100.63.255.255', '2606:4700:4700::1111', '::ffff:8.8.8.8'] as $ip) {
            self::assertTrue(IpGuard::isPublic($ip), $ip);
        }
    }
}
