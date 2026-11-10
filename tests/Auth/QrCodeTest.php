<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Auth;

use OmniWrite\Auth\QrCode;
use PHPUnit\Framework\TestCase;

final class QrCodeTest extends TestCase
{
    private const URI = 'otpauth://totp/OmniWrite%3Aa%40example.com?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ&issuer=OmniWrite&algorithm=SHA1&digits=6&period=30';

    public function testVersionGrowsWithPayload(): void
    {
        self::assertCount(21, QrCode::matrix('hi'));
        self::assertCount(41, QrCode::matrix(str_repeat('a', 106))); // Version 6, voll
        self::assertCount(45, QrCode::matrix(str_repeat('a', 107))); // Version 7
        self::assertCount(57, QrCode::matrix(str_repeat('a', 213))); // Version 10, voll
    }

    public function testTooLongPayloadIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QrCode::matrix(str_repeat('a', 214));
    }

    public function testFitsMatchesEncoderLimit(): void
    {
        self::assertTrue(QrCode::fits(str_repeat('a', 213)));
        self::assertFalse(QrCode::fits(str_repeat('a', 214)));
    }

    public function testFinderPatternsAndTimingArePresent(): void
    {
        $m = QrCode::matrix(self::URI);
        $n = count($m);
        foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$x0, $y0]) {
            self::assertTrue($m[$y0][$x0] && $m[$y0 + 3][$x0 + 3] && $m[$y0 + 6][$x0 + 6]);
            self::assertFalse($m[$y0 + 1][$x0 + 1] || $m[$y0 + 5][$x0 + 5]);
        }
        for ($i = 8; $i < $n - 8; $i++) {
            self::assertSame($i % 2 === 0, $m[6][$i]);
            self::assertSame($i % 2 === 0, $m[$i][6]);
        }
    }

    /** Golden-Wert: Ausgabe wurde extern (OpenCV) zurückgelesen und stimmte mit URI überein. */
    public function testSvgIsStableAndSelfContained(): void
    {
        $svg = QrCode::svg(self::URI);
        self::assertSame('8882a6f3211cc288355e85eb5b888338eb202c8aa8ff49a3b32d80033a1cec0c', hash('sha256', $svg));
        self::assertStringStartsWith('<svg ', $svg);
        self::assertStringNotContainsString('http', str_replace('http://www.w3.org/2000/svg', '', $svg));
        self::assertStringNotContainsString('GEZDG', $svg, 'Secret steht nicht im Klartext im Markup');
    }
}
