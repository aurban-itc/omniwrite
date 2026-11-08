<?php
declare(strict_types=1);

namespace OmniWrite\Support;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;

/** Allowlist für Post-Inhalte (Editor und später KI-Ausgabe). Alles andere wird entfernt. */
final class Html
{
    private const ALLOWED = [
        'p' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'ul' => [], 'ol' => [], 'li' => [],
        'strong' => [], 'em' => [], 'b' => [], 'i' => [], 'br' => [], 'blockquote' => [], 'hr' => [],
        'a' => ['href', 'title'], 'img' => ['src', 'alt', 'title'],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => [], 'td' => [],
    ];
    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'svg', 'math', 'template', 'noscript'];

    public static function sanitize(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="ow-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $doc->getElementById('ow-root');
        if ($root === null) {
            return '';
        }
        self::clean($root);
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out);
    }

    public static function text(string $html): string
    {
        return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    public static function readMinutes(string $html): int
    {
        $words = preg_match_all('/[\p{L}\p{N}]+/u', self::text($html));
        return max(1, (int) round(($words === false ? 0 : $words) / 220));
    }

    /** Erlaubt https, relative Pfade, Anker, mailto/tel (Bilder nur https/relativ). Kein javascript:/data:. */
    public static function safeUrl(string $url, bool $image = false): bool
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x1f\x7f]/', $url) === 1) {
            return false;
        }
        if (preg_match('#^(https://|/(?!/)|\#|mailto:|tel:)#i', $url) === 1) {
            return !($image && preg_match('#^(mailto:|tel:|\#)#i', $url) === 1);
        }
        return preg_match('#^[a-z0-9][^:]*$#i', $url) === 1; // relativer Pfad ohne Schema
    }

    private static function clean(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMComment) {
                $node->removeChild($child);
                continue;
            }
            if (!($child instanceof DOMElement)) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (!isset(self::ALLOWED[$tag])) {
                if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                    $node->removeChild($child);
                } else { // Tag auspacken, Text behalten
                    self::clean($child);
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                }
                continue;
            }
            foreach (iterator_to_array($child->attributes) as $attr) {
                $name = strtolower($attr->name);
                $ok = in_array($name, self::ALLOWED[$tag], true);
                if ($ok && ($name === 'href' || $name === 'src') && !self::safeUrl($attr->value, $name === 'src')) {
                    $ok = false;
                }
                if (!$ok) {
                    $child->removeAttribute($attr->name);
                }
            }
            if ($tag === 'a' && $child->hasAttribute('href') && preg_match('#^https?://#i', $child->getAttribute('href')) === 1) {
                $child->setAttribute('rel', 'noopener');
            }
            self::clean($child);
        }
    }
}
