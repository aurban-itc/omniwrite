<?php
declare(strict_types=1);

namespace OmniWrite\Auth;

/**
 * Minimaler QR-Code-Generator (Byte-Modus, Fehlerkorrektur M, Version 1–10, bis 213 Bytes).
 * Läuft komplett lokal ohne Abhängigkeiten, damit das TOTP-Secret den Server nie verlässt.
 */
final class QrCode
{
    /** Version => [EC-Codewords je Block, Blöcke Gruppe 1, Daten-CW Gruppe 1, Blöcke Gruppe 2, Daten-CW Gruppe 2] (Stufe M). */
    private const BLOCKS = [
        1 => [10, 1, 16, 0, 0], 2 => [16, 1, 28, 0, 0], 3 => [26, 1, 44, 0, 0],
        4 => [18, 2, 32, 0, 0], 5 => [24, 2, 43, 0, 0], 6 => [16, 4, 27, 0, 0],
        7 => [18, 4, 31, 0, 0], 8 => [22, 2, 38, 2, 39], 9 => [22, 3, 36, 2, 37],
        10 => [26, 4, 43, 1, 44],
    ];
    private const ALIGN = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34],
        7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    /** @var list<list<bool>> */
    private array $modules = [];
    /** @var list<list<bool>> */
    private array $reserved = [];
    private int $size;

    private function __construct(private readonly int $version)
    {
        $this->size = 17 + 4 * $version;
        $row = array_fill(0, $this->size, false);
        $this->modules = array_fill(0, $this->size, $row);
        $this->reserved = $this->modules;
    }

    /** Passt der Text in die größte unterstützte Version? Aufrufer können sonst auf die manuelle Eingabe ausweichen. */
    public static function fits(string $text): bool
    {
        return strlen($text) <= 213;
    }

    /** @return list<list<bool>> Modul-Matrix (true = dunkel). */
    public static function matrix(string $text): array
    {
        $len = strlen($text);
        $version = 0;
        foreach (self::BLOCKS as $v => [$ec, $n1, $d1, $n2, $d2]) {
            $capacity = $n1 * $d1 + $n2 * $d2;
            $countBits = $v < 10 ? 8 : 16;
            if (4 + $countBits + 8 * $len <= 8 * $capacity) {
                $version = $v;
                break;
            }
        }
        if ($version === 0) {
            throw new \InvalidArgumentException('Text zu lang für den QR-Code');
        }
        $qr = new self($version);
        $qr->build($text);
        return $qr->modules;
    }

    /** SVG-Markup (inline einbettbar), inkl. 4 Module Ruhezone. */
    public static function svg(string $text, int $scale = 5): string
    {
        $m = self::matrix($text);
        $n = count($m);
        $dim = $n + 8;
        $path = '';
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($m[$y][$x]) {
                    $path .= 'M' . ($x + 4) . ',' . ($y + 4) . 'h1v1h-1z';
                }
            }
        }
        $px = $dim * $scale;
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dim . ' ' . $dim . '" width="' . $px
            . '" height="' . $px . '" role="img" shape-rendering="crispEdges">'
            . '<rect width="100%" height="100%" fill="#fff"/><path d="' . $path . '" fill="#000"/></svg>';
    }

    private function build(string $text): void
    {
        $data = $this->encodeData($text);
        $codewords = $this->interleave($data);
        $this->drawFunctionPatterns();
        $this->placeData($codewords);

        $bestMask = 0;
        $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->applyMask($mask);
            $this->drawFormat($mask);
            $score = $this->penalty();
            if ($score < $bestScore) {
                $bestScore = $score;
                $bestMask = $mask;
            }
            $this->applyMask($mask); // XOR rückgängig
        }
        $this->applyMask($bestMask);
        $this->drawFormat($bestMask);
    }

    /** @return list<int> */
    private function encodeData(string $text): array
    {
        [, $n1, $d1, $n2, $d2] = self::BLOCKS[$this->version];
        $capacityBits = 8 * ($n1 * $d1 + $n2 * $d2);
        $bits = '0100' . str_pad(decbin(strlen($text)), $this->version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
        foreach (str_split($text) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $bits .= str_repeat('0', min(4, $capacityBits - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
        $bytes = array_map('bindec', str_split($bits, 8));
        for ($pad = 0xEC; count($bytes) < $capacityBits / 8; $pad ^= 0xEC ^ 0x11) {
            $bytes[] = $pad;
        }
        return $bytes;
    }

    /** @param list<int> $data @return list<int> */
    private function interleave(array $data): array
    {
        [$ecLen, $n1, $d1, $n2, $d2] = self::BLOCKS[$this->version];
        $gen = self::generator($ecLen);
        $blocks = [];
        $ecs = [];
        $pos = 0;
        foreach ([[$n1, $d1], [$n2, $d2]] as [$count, $len]) {
            for ($i = 0; $i < $count; $i++) {
                $block = array_slice($data, $pos, $len);
                $pos += $len;
                $blocks[] = $block;
                $ecs[] = self::remainder($block, $gen);
            }
        }
        $out = [];
        for ($i = 0, $max = max($d1, $d2); $i < $max; $i++) {
            foreach ($blocks as $b) {
                if (isset($b[$i])) {
                    $out[] = $b[$i];
                }
            }
        }
        for ($i = 0; $i < $ecLen; $i++) {
            foreach ($ecs as $e) {
                $out[] = $e[$i];
            }
        }
        return $out;
    }

    private static function gfMul(int $a, int $b): int
    {
        $r = 0;
        while ($b > 0) {
            if ($b & 1) {
                $r ^= $a;
            }
            $a <<= 1;
            if ($a & 0x100) {
                $a ^= 0x11D;
            }
            $b >>= 1;
        }
        return $r;
    }

    /** @return list<int> Generatorpolynom ohne führenden 1-Koeffizienten. */
    private static function generator(int $degree): array
    {
        $poly = [1];
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($poly) + 1, 0);
            foreach ($poly as $j => $coef) {
                $next[$j] ^= $coef;
                $next[$j + 1] ^= self::gfMul($coef, $root);
            }
            $poly = $next;
            $root = self::gfMul($root, 2);
        }
        return array_slice($poly, 1);
    }

    /** @param list<int> $data @param list<int> $gen @return list<int> */
    private static function remainder(array $data, array $gen): array
    {
        $rem = array_fill(0, count($gen), 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($rem);
            $rem[] = 0;
            foreach ($gen as $i => $g) {
                $rem[$i] ^= self::gfMul($g, $factor);
            }
        }
        return $rem;
    }

    private function set(int $x, int $y, bool $dark): void
    {
        if ($x < 0 || $y < 0 || $x >= $this->size || $y >= $this->size) {
            return;
        }
        $this->modules[$y][$x] = $dark;
        $this->reserved[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        $s = $this->size;
        for ($i = 0; $i < $s; $i++) {
            $this->set($i, 6, $i % 2 === 0);
            $this->set(6, $i, $i % 2 === 0);
        }
        foreach ([[3, 3], [$s - 4, 3], [3, $s - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $d = max(abs($dx), abs($dy));
                    $this->set($cx + $dx, $cy + $dy, $d !== 2 && $d !== 4);
                }
            }
        }
        $pos = self::ALIGN[$this->version];
        $last = count($pos) - 1;
        foreach ($pos as $i => $px) {
            foreach ($pos as $j => $py) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->set($px + $dx, $py + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->drawFormat(0); // Bereich reservieren
        if ($this->version >= 7) {
            $rem = $this->version;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            }
            $bits = ($this->version << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $dark = (($bits >> $i) & 1) === 1;
                $a = $s - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->set($a, $b, $dark);
                $this->set($b, $a, $dark);
            }
        }
    }

    private function drawFormat(int $mask): void
    {
        $data = (0 << 3) | $mask; // Stufe M = 00
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = fn(int $i): bool => (($bits >> $i) & 1) === 1;
        $s = $this->size;
        for ($i = 0; $i <= 5; $i++) {
            $this->set(8, $i, $bit($i));
        }
        $this->set(8, 7, $bit(6));
        $this->set(8, 8, $bit(7));
        $this->set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->set(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->set($s - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->set(8, $s - 15 + $i, $bit($i));
        }
        $this->set(8, $s - 8, true); // festes dunkles Modul
    }

    /** @param list<int> $codewords */
    private function placeData(array $codewords): void
    {
        $bits = [];
        foreach ($codewords as $cw) {
            for ($i = 7; $i >= 0; $i--) {
                $bits[] = (($cw >> $i) & 1) === 1;
            }
        }
        $s = $this->size;
        $k = 0;
        $total = count($bits);
        for ($right = $s - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($v = 0; $v < $s; $v++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $up = (($right + 1) & 2) === 0;
                    $y = $up ? $s - 1 - $v : $v;
                    if (!$this->reserved[$y][$x]) {
                        $this->modules[$y][$x] = $k < $total ? $bits[$k] : false;
                        $k++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->reserved[$y][$x]) {
                    continue;
                }
                $inv = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($inv) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    private function penalty(): int
    {
        $n = $this->size;
        $m = $this->modules;
        $score = 0;
        $dark = 0;
        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $n; $a++) {
                $line = [];
                for ($b = 0; $b < $n; $b++) {
                    $line[] = $pass === 0 ? $m[$a][$b] : $m[$b][$a];
                }
                $run = 1;
                for ($i = 1; $i < $n; $i++) {
                    if ($line[$i] === $line[$i - 1]) {
                        $run++;
                        if ($run === 5) {
                            $score += 3;
                        } elseif ($run > 5) {
                            $score++;
                        }
                    } else {
                        $run = 1;
                    }
                }
                $str = implode('', array_map(fn($v) => $v ? '1' : '0', $line));
                $score += 40 * (substr_count($str, '10111010000') + substr_count($str, '00001011101'));
            }
        }
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($m[$y][$x]) {
                    $dark++;
                }
                if ($x + 1 < $n && $y + 1 < $n && $m[$y][$x] === $m[$y][$x + 1]
                    && $m[$y][$x] === $m[$y + 1][$x] && $m[$y][$x] === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
        $score += 10 * intdiv(abs($dark * 20 - $n * $n * 10), $n * $n);
        return $score;
    }
}
