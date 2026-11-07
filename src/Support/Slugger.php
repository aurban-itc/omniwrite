<?php
declare(strict_types=1);

namespace OmniWrite\Support;

final class Slugger
{
    public static function slugify(string $text, int $max = 80): string
    {
        $text = strtr(mb_strtolower($text, 'UTF-8'), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = $ascii === false || $ascii === '' ? $text : $ascii;
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
        $slug = rtrim(substr($slug, 0, $max), '-');
        return $slug === '' ? 'post' : $slug;
    }
}
