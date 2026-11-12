<?php
declare(strict_types=1);

namespace OmniWrite\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

/** Zieht aus dem HTML einer Firmenseite den lesbaren Kern (Titel, Beschreibung, Überschriften, Absätze) und passende Unterseiten. */
final class PageExtractor
{
    /** Pfadwörter, die auf Seiten mit Firmeninformationen hindeuten, nach Wichtigkeit gruppiert. */
    private const LINK_HINTS = [
        ['about', 'ueber', 'uber', 'über', 'unternehmen', 'company', 'wir', 'team', 'philosophie', 'mission'],
        ['leistung', 'service', 'angebot', 'produkt', 'product', 'loesung', 'lösung', 'solution', 'branche', 'industr'],
        ['impressum', 'imprint', 'kontakt', 'contact', 'legal'],
        ['blog', 'news', 'magazin', 'insight', 'ratgeber', 'wissen'],
    ];

    /**
     * @return array{title: string, description: string, text: string, links: list<string>}
     */
    public static function extract(string $html, string $pageUrl, int $maxChars = 6000): array
    {
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
        }
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new DOMXPath($doc);

        $title = self::clean((string) $xp->evaluate('string(//title)'));
        $description = '';
        foreach (['description', 'og:description'] as $name) {
            $v = (string) $xp->evaluate('string(//meta[@name="' . $name . '" or @property="' . $name . '"]/@content)');
            if (trim($v) !== '') {
                $description = self::clean($v);
                break;
            }
        }
        $links = self::links($xp, $pageUrl);

        foreach ($xp->query('//script|//style|//noscript|//svg|//iframe|//form|//nav|//template|//select|//button') ?: [] as $n) {
            $n->parentNode?->removeChild($n);
        }
        $blocks = [];
        $seen = [];
        foreach ($xp->query('//h1|//h2|//h3|//h4|//p|//li|//address|//blockquote|//dd|//td|//figcaption') ?: [] as $n) {
            /** @var DOMElement $n */
            $text = self::clean($n->textContent);
            $isHeading = $n->nodeName[0] === 'h';
            if ($text === '' || mb_strlen($text) < ($isHeading ? 3 : 25) || isset($seen[$text])) {
                continue;
            }
            $seen[$text] = true;
            $blocks[] = ($isHeading ? '## ' : '') . $text;
        }
        $text = '';
        foreach ($blocks as $b) {
            $room = $maxChars - mb_strlen($text) - 1;
            if ($room < 40) {
                break;
            }
            $text .= mb_substr($b, 0, min($room, max(1500, intdiv($room, 2)))) . "\n"; // überlange Absätze kürzen statt alles Folgende zu verlieren
        }
        return ['title' => mb_substr($title, 0, 200), 'description' => mb_substr($description, 0, 400), 'text' => rtrim($text), 'links' => $links];
    }

    /** @return list<string> bis zu 3 Unterseiten derselben Domain, je Gruppe höchstens eine */
    private static function links(DOMXPath $xp, string $pageUrl): array
    {
        $base = parse_url($pageUrl);
        if (!isset($base['host'])) {
            return [];
        }
        $host = strtolower(preg_replace('/^www\./', '', (string) $base['host']));
        $best = [];
        foreach ($xp->query('//a[@href]') ?: [] as $a) {
            /** @var DOMElement $a */
            $href = trim($a->getAttribute('href'));
            if ($href === '' || preg_match('#^(mailto:|tel:|javascript:|\#)#i', $href) === 1) {
                continue;
            }
            $abs = self::absolute($pageUrl, $href);
            $p = $abs === null ? false : parse_url($abs);
            if ($p === false || !isset($p['host']) || strtolower(preg_replace('/^www\./', '', $p['host'])) !== $host) {
                continue;
            }
            $path = strtolower(rawurldecode($p['path'] ?? '/'));
            if ($path === '/' || $path === ($base['path'] ?? '/') || preg_match('/\.(pdf|jpe?g|png|gif|webp|avif|zip|docx?|xlsx?)$/', $path) === 1) {
                continue;
            }
            foreach (self::LINK_HINTS as $group => $words) {
                foreach ($words as $w) {
                    if (str_contains($path, $w)) {
                        $key = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
                        $best[$group][$key] ??= strlen($path);
                        continue 3;
                    }
                }
            }
        }
        $out = [];
        ksort($best);
        foreach ($best as $urls) {
            asort($urls); // kürzester Pfad zuerst: meist die Übersichtsseite
            $out[] = (string) array_key_first($urls);
            if (count($out) === 3) {
                break;
            }
        }
        return $out;
    }

    private static function absolute(string $base, string $href): ?string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $href) === 1) {
            return preg_match('#^https?://#i', $href) === 1 ? $href : null;
        }
        $b = parse_url($base);
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($href, '//')) {
            return $b['scheme'] . ':' . $href;
        }
        $href = preg_replace('/[#].*$/', '', $href) ?? $href;
        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }
        return $origin . preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/') . $href;
    }

    private static function clean(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{a0}", ' ', $s)));
    }
}
