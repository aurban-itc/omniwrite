<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Support;

use OmniWrite\Mail\Mailer;

/** Sammelt Mails im Speicher; `$fail` lässt den Versand scheitern, `$configured` simuliert fehlenden Schlüssel. */
final class FakeMailer implements Mailer
{
    /** @var list<array{to: string, name: string, subject: string, html: string, text: string}> */
    public array $sent = [];
    public bool $fail = false;
    public bool $configured = true;

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function send(string $toEmail, string $toName, string $subject, string $html, string $text): bool
    {
        if ($this->fail) {
            return false;
        }
        $this->sent[] = ['to' => $toEmail, 'name' => $toName, 'subject' => $subject, 'html' => $html, 'text' => $text];
        return true;
    }

    /** Extrahiert den Token einer Aktion ("publish" | "edit" | "decline") aus der zuletzt gesendeten Mail. */
    public function lastToken(string $do = 'publish'): string
    {
        $mail = end($this->sent);
        preg_match('#/review/([a-f0-9]{64})/' . $do . '#', $mail['text'], $m);
        return $m[1] ?? '';
    }
}
