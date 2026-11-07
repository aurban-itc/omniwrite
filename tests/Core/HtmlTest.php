<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Core;

use OmniWrite\Support\Html;
use OmniWrite\Support\Slugger;
use PHPUnit\Framework\TestCase;

final class HtmlTest extends TestCase
{
    public function testStripsScriptsEventHandlersAndDangerousUrls(): void
    {
        $out = Html::sanitize('<p onclick="x()">Hi <script>alert(1)</script><a href="javascript:alert(1)">l</a> <a href="https://x.test/a">ok</a><img src="data:image/png;base64,AA" alt="a"><iframe src="https://e"></iframe></p>');
        self::assertStringNotContainsString('script', $out);
        self::assertStringNotContainsString('onclick', $out);
        self::assertStringNotContainsString('javascript:', $out);
        self::assertStringNotContainsString('data:', $out);
        self::assertStringNotContainsString('iframe', $out);
        self::assertStringContainsString('<a href="https://x.test/a" rel="noopener">ok</a>', $out);
    }

    public function testKeepsAllowedStructureAndUnwrapsUnknownTags(): void
    {
        $out = Html::sanitize('<h2>T</h2><div><span>text</span></div><ul><li>a</li></ul>');
        self::assertStringContainsString('<h2>T</h2>', $out);
        self::assertStringContainsString('text', $out);
        self::assertStringNotContainsString('<div', $out);
        self::assertStringNotContainsString('<span', $out);
        self::assertStringContainsString('<ul><li>a</li></ul>', $out);
    }

    public function testProtocolRelativeAndControlCharUrlsAreRejected(): void
    {
        self::assertFalse(Html::safeUrl('//evil.test/x'));
        self::assertFalse(Html::safeUrl("java\tscript:alert(1)"));
        self::assertFalse(Html::safeUrl('http://insecure.test/x'));
        self::assertTrue(Html::safeUrl('https://ok.test/x'));
        self::assertTrue(Html::safeUrl('/bild.webp', true));
        self::assertFalse(Html::safeUrl('mailto:a@b.de', true));
    }

    public function testTextAndReadMinutes(): void
    {
        self::assertSame('Eins zwei', Html::text('<p>Eins</p><p>zwei</p>'));
        self::assertSame(1, Html::readMinutes('<p>kurz</p>'));
        self::assertSame(5, Html::readMinutes('<p>' . str_repeat('wort ', 1100) . '</p>'));
    }

    public function testSlugHandlesGermanUmlautsAndEmpty(): void
    {
        self::assertSame('schoene-gruesse-aus-muenchen', Slugger::slugify('Schöne Grüße aus München!'));
        self::assertSame('post', Slugger::slugify('!!!'));
        self::assertLessThanOrEqual(80, strlen(Slugger::slugify(str_repeat('abc ', 60))));
    }
}
