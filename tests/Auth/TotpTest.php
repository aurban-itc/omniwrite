<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Auth;

use OmniWrite\Auth\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // base32("12345678901234567890")

    /** Testvektoren aus RFC 6238, Anhang B (SHA-1, 8 Stellen). */
    #[DataProvider('rfcVectors')]
    public function testRfc6238Vectors(int $time, string $expected): void
    {
        self::assertSame($expected, Totp::code(self::RFC_SECRET, intdiv($time, 30), 8));
    }

    /** @return array<string, array{int, string}> */
    public static function rfcVectors(): array
    {
        return [
            't=59' => [59, '94287082'],
            't=1111111109' => [1111111109, '07081804'],
            't=1111111111' => [1111111111, '14050471'],
            't=1234567890' => [1234567890, '89005924'],
            't=2000000000' => [2000000000, '69279037'],
            't=20000000000' => [20000000000, '65353130'],
        ];
    }

    public function testVerifyAcceptsWindowAndReturnsStep(): void
    {
        $now = 1_800_000_000;
        $step = intdiv($now, 30);
        self::assertSame($step, Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step), $now));
        self::assertSame($step - 1, Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step - 1), $now));
        self::assertSame($step + 1, Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step + 1), $now));
        self::assertNull(Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step + 2), $now));
        self::assertNull(Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step - 2), $now));
    }

    public function testVerifyIgnoresSpacesAndRejectsGarbage(): void
    {
        $now = 1_800_000_000;
        $code = Totp::code(self::RFC_SECRET, intdiv($now, 30));
        self::assertNotNull(Totp::verify(self::RFC_SECRET, substr($code, 0, 3) . ' ' . substr($code, 3), $now));
        foreach (['', 'abcdef', '12345', '1234567', '000000x'] as $bad) {
            self::assertNull(Totp::verify(self::RFC_SECRET, $bad, $now));
        }
    }

    public function testBase32RoundTripAndSecretShape(): void
    {
        $bytes = random_bytes(20);
        self::assertSame($bytes, Totp::base32Decode(Totp::base32Encode($bytes)));
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', Totp::generateSecret());
    }

    public function testProvisioningUri(): void
    {
        $uri = Totp::provisioningUri('ABC', 'a@example.com');
        self::assertStringStartsWith('otpauth://totp/OmniWrite%3Aa%40example.com?secret=ABC&issuer=OmniWrite', $uri);
    }
}
