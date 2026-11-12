<?php
declare(strict_types=1);

namespace OmniWrite\Net;

final class CurlPageTransport implements PageTransport
{
    public function get(string $url, string $host, int $port, string $ip, int $maxBytes, int $timeoutSeconds): array
    {
        $location = '';
        $contentType = '';
        $body = '';
        $target = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $target], // verbindet nur mit der geprüften Adresse (kein DNS-Rebinding)
            CURLOPT_PROXY => '',      // nie über einen Proxy: er würde die gepinnte IP umgehen
            CURLOPT_NOPROXY => '*',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_REDIR_PROTOCOLS => 0,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'OmniWriteBot/1.0 (+https://omniwrite.urbanlabs.ai)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.1'],
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$location, &$contentType): int {
                if (stripos($line, 'location:') === 0) {
                    $location = trim(substr($line, 9));
                } elseif (stripos($line, 'content-type:') === 0) {
                    $contentType = strtolower(trim(substr($line, 13)));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($c, string $chunk) use (&$body, $maxBytes): int {
                $body .= $chunk;
                if (strlen($body) > $maxBytes) {
                    $body = substr($body, 0, $maxBytes);
                    return -1; // Abbruch: genug gelesen
                }
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno !== 0 && $errno !== CURLE_WRITE_ERROR) {
            $status = 0;
        }
        return ['status' => $status, 'location' => $location, 'contentType' => $contentType, 'body' => $body];
    }
}
