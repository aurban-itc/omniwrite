<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Auth;

use OmniWrite\Auth\SecretBox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SecretBoxTest extends TestCase
{
    private function key(string $c = 'a'): string
    {
        return base64_encode(str_repeat($c, 32));
    }

    public function testRoundTripWithRandomNonce(): void
    {
        $box = new SecretBox($this->key());
        $a = $box->encrypt('geheim');
        self::assertNotSame($a, $box->encrypt('geheim'));
        self::assertSame('geheim', $box->decrypt($a));
        self::assertStringNotContainsString('geheim', $a);
    }

    public function testWrongKeyOrTamperingFails(): void
    {
        $enc = (new SecretBox($this->key('a')))->encrypt('geheim');
        $this->expectException(RuntimeException::class);
        (new SecretBox($this->key('b')))->decrypt($enc);
    }

    public function testMissingOrShortKeyThrowsHelpfulError(): void
    {
        foreach (['', 'not-base64!!', base64_encode('too short')] as $bad) {
            try {
                (new SecretBox($bad))->encrypt('x');
                self::fail('Exception erwartet');
            } catch (RuntimeException $e) {
                self::assertStringContainsString('security.master_key', $e->getMessage());
            }
        }
    }
}
