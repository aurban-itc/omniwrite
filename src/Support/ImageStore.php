<?php
declare(strict_types=1);

namespace OmniWrite\Support;

use RuntimeException;

/**
 * Legt Bilder außerhalb des Webroots ab (storage/images/<site_id>/) und liefert die öffentliche Adresse /media/<site_id>/<datei>.
 * Daten werden immer neu kodiert (WebP), das entfernt Metadaten und fremde Inhalte.
 */
final class ImageStore
{
    public const FILE_PATTERN = '/^[a-f0-9]{16}\.(webp|png|avif)$/';

    public function __construct(private readonly string $baseDir)
    {
    }

    /** @return string öffentliche Adresse, z. B. /media/7/ab12cd34ef567890.webp */
    public function save(int $siteId, string $raw): string
    {
        $info = @getimagesizefromstring($raw);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_AVIF], true) || $info[0] > 8000 || $info[1] > 8000) {
            throw new RuntimeException('unsupported image');
        }
        $dir = $this->dir($siteId);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('cannot create image directory');
        }
        $name = bin2hex(random_bytes(8));
        if ($info[2] === IMAGETYPE_AVIF && !(function_exists('gd_info') && (gd_info()['AVIF Support'] ?? false))) {
            // Dieses PHP kann AVIF nicht neu kodieren: die (geprüfte, begrenzte) Datei unverändert ablegen, sie wird nur mit festem Typ ausgeliefert.
            if (strlen($raw) > 8 * 1024 * 1024 || file_put_contents("$dir/$name.avif", $raw) === false) {
                throw new RuntimeException('image could not be saved');
            }
            return "/media/$siteId/$name.avif";
        }
        $im = @imagecreatefromstring($raw);
        if ($im === false) {
            throw new RuntimeException('image could not be decoded');
        }
        if ($info[0] > 1600) {
            $im = imagescale($im, 1600) ?: $im;
        }
        imagepalettetotruecolor($im);
        if (function_exists('imagewebp') && imagewebp($im, "$dir/$name.webp", 82)) {
            return "/media/$siteId/$name.webp";
        }
        if (imagepng($im, "$dir/$name.png")) {
            return "/media/$siteId/$name.png";
        }
        throw new RuntimeException('image could not be saved');
    }

    /** Pfad einer vorhandenen Datei oder null; prüft das Namensmuster, damit nichts außerhalb des Verzeichnisses gelesen wird. */
    public function pathFor(int $siteId, string $file): ?string
    {
        if ($siteId <= 0 || preg_match(self::FILE_PATTERN, $file) !== 1) {
            return null;
        }
        $path = $this->dir($siteId) . '/' . $file;
        return is_file($path) ? $path : null;
    }

    /**
     * Diagnose für den Admin: wohin geschrieben wird und was dort liegt (nur Namen, keine Inhalte).
     *
     * @return array{base: string, base_real: string, exists: bool, writable: bool, dir: string, dir_exists: bool, count: int, sample: list<string>}
     */
    public function report(int $siteId): array
    {
        $base = rtrim($this->baseDir, '/');
        $dir = $this->dir($siteId);
        $files = is_dir($dir) ? array_values(array_filter(scandir($dir) ?: [], static fn (string $f): bool => preg_match(self::FILE_PATTERN, $f) === 1)) : [];
        return [
            'base' => $base, 'base_real' => (string) (realpath($base) ?: ''), 'exists' => is_dir($base), 'writable' => is_dir($base) && is_writable($base),
            'dir' => $dir, 'dir_exists' => is_dir($dir), 'count' => count($files), 'sample' => array_slice($files, 0, 12),
        ];
    }

    private function dir(int $siteId): string
    {
        return rtrim($this->baseDir, '/') . '/images/' . $siteId;
    }
}
