<?php
declare(strict_types=1);

namespace OmniWrite\Mail;

/** Versand von Transaktions-Mails. Gibt false zurück, wenn der Anbieter die Mail nicht angenommen hat. */
interface Mailer
{
    public function isConfigured(): bool;

    public function send(string $toEmail, string $toName, string $subject, string $html, string $text): bool;
}
