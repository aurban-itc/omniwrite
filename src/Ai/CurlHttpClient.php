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
}
