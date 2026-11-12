<?php
declare(strict_types=1);

namespace OmniWrite\Ai;

final class CurlHttpClient implements HttpClient
{
    public function postJson(string $url, array $headers, string $json, int $timeoutSeconds): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false) {
            error_log('ai http: ' . curl_error($ch));
            $status = 0;
            $body = '';
        }
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $body];
    }

    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds, ?string $pinIp = null): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            return ['status' => 0, 'body' => ''];
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $received = '';
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_WRITEFUNCTION => static function ($c, string $chunk) use (&$received): int {
                if (strlen($received) < 65536) {
                    $received .= substr($chunk, 0, 65536 - strlen($received));
                }
                return strlen($chunk);
            },
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        if ($pinIp !== null) {
            $opts[CURLOPT_RESOLVE] = [strtolower((string) $parts['host']) . ':' . (int) ($parts['port'] ?? 443) . ':' . $pinIp];
        }
        curl_setopt_array($ch, $opts);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($ok === false) {
            error_log('push http: ' . curl_error($ch));
            $status = 0;
            $received = '';
        }
        curl_close($ch);
        return ['status' => $status, 'body' => $received];
    }
}
