<?php
declare(strict_types=1);

namespace OmniWrite\Mail;

use OmniWrite\Ai\HttpClient;

/** Mailversand über die Resend-API (Schlüssel gehört OmniWrite, steht nur in config/config.php). */
final class ResendMailer implements Mailer
{
    public const DEFAULT_ENDPOINT = 'https://api.resend.com/emails';

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $apiKey,
        private readonly string $from,
        private readonly string $endpoint = self::DEFAULT_ENDPOINT,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->from !== '';
    }

    public function send(string $toEmail, string $toName, string $subject, string $html, string $text): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }
        $to = $toName !== '' ? str_replace(['<', '>', '"', "\r", "\n"], '', $toName) . ' <' . $toEmail . '>' : $toEmail;
        $res = $this->http->postJson(
            $this->endpoint,
            ['Authorization' => 'Bearer ' . $this->apiKey, 'Content-Type' => 'application/json'],
            json_encode(['from' => $this->from, 'to' => [$to], 'subject' => $subject, 'html' => $html, 'text' => $text], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            20,
        );
        if ($res['status'] < 200 || $res['status'] >= 300) {
            error_log('resend: HTTP ' . $res['status'] . ' ' . substr($res['body'], 0, 300));
            return false;
        }
        return true;
    }
}
