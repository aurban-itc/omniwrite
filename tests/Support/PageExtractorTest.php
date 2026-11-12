<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Support;

use OmniWrite\Support\PageExtractor;
use PHPUnit\Framework\TestCase;

final class PageExtractorTest extends TestCase
{
    public function testExtractsReadableCoreAndPicksSubpages(): void
    {
        $html = '<html><head><title> Muster GmbH – Heizung </title><meta name="description" content="Wir bauen Wärmepumpen."><script>var x = "geheim";</script><style>p{color:red}</style></head>'
            . '<body><nav><a href="/ueber-uns">Über uns</a></nav><h1>Willkommen bei Muster</h1><p>Wir sind ein Familienbetrieb aus Kiel seit 1985 mit zwölf Mitarbeitern.</p>'
            . '<p>Wir sind ein Familienbetrieb aus Kiel seit 1985 mit zwölf Mitarbeitern.</p><p>kurz</p>'
            . '<ul><li>Wärmepumpen für Altbau und Neubau, inklusive Förderberatung</li></ul>'
            . '<a href="/ueber-uns">Über uns</a><a href="/leistungen/waermepumpe">Leistungen</a><a href="/impressum#top">Impressum</a><a href="https://other.example/ueber">fremd</a>'
            . '<a href="mailto:a@b.de">Mail</a><a href="/broschuere.pdf">PDF</a><a href="/blog/a">Blog</a><a href="/blog">Blog Übersicht</a></body></html>';
        $r = PageExtractor::extract($html, 'https://www.muster.example/');
        self::assertSame('Muster GmbH – Heizung', $r['title']);
        self::assertSame('Wir bauen Wärmepumpen.', $r['description']);
        self::assertStringContainsString('## Willkommen bei Muster', $r['text']);
        self::assertSame(1, substr_count($r['text'], 'Familienbetrieb'));
        self::assertStringContainsString('Förderberatung', $r['text']);
        self::assertStringNotContainsString('geheim', $r['text']);
        self::assertStringNotContainsString('color:red', $r['text']);
        self::assertSame([
            'https://www.muster.example/ueber-uns',
            'https://www.muster.example/leistungen/waermepumpe',
            'https://www.muster.example/impressum',
        ], $r['links']);
    }

    public function testHandlesLatin1AndBrokenMarkup(): void
    {
        $html = "<html><body><h1>Gr\xfc\xdfe</h1><p>Sch\xf6ne Fr\xfchlingsangebote f\xfcr Ihr Zuhause <b>jetzt</b></body>";
        $r = PageExtractor::extract($html, 'https://x.example/');
        self::assertStringContainsString('Grüße', $r['text']);
        self::assertStringContainsString('Schöne Frühlingsangebote', $r['text']);
    }

    public function testRespectsCharLimit(): void
    {
        $html = '<body>' . str_repeat('<p>' . str_repeat('Lorem ipsum dolor sit amet. ', 5) . '</p>', 1) . implode('', array_map(static fn (int $i): string => "<p>Absatz Nummer $i mit genügend Text zum Durchzählen hier.</p>", range(1, 200))) . '</body>';
        self::assertLessThanOrEqual(1000, mb_strlen(PageExtractor::extract($html, 'https://x.example/', 1000)['text']));
    }

    public function testOversizedFirstParagraphDoesNotHideLaterText(): void
    {
        $html = '<body><p>' . str_repeat('Sehr lang. ', 700) . '</p><p>Danach folgt die wichtige Leistungsbeschreibung des Betriebs.</p></body>';
        self::assertStringContainsString('Leistungsbeschreibung', PageExtractor::extract($html, 'https://x.example/')['text']);
    }

    public function testSubpageLinksKeepQuery(): void
    {
        $html = '<body><a href="/about?lang=en">About</a></body>';
        self::assertSame(['https://x.example/about?lang=en'], PageExtractor::extract($html, 'https://x.example/')['links']);
    }
}
