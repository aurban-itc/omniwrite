<?php
declare(strict_types=1);

namespace OmniWrite\Auth;

use RuntimeException;

/** Verschlüsselt kleine Geheimnisse (TOTP-Secrets, später Anbindungs-Zugangsdaten) mit libsodium. */
final class SecretBox
{
    /** @param string $masterKeyBase64 32 Byte, base64 (config security.master_key) */
    public function __construct(private readonly string $masterKeyBase64)
    {
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key()));
    }

    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Verschlüsselte Daten sind beschädigt');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key());
        if ($plain === false) {
            throw new RuntimeException('Entschlüsselung fehlgeschlagen (falscher security.master_key?)');
        }
        return $plain;
    }

    private function key(): string
    {
        $key = base64_decode($this->masterKeyBase64, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException(
                'security.master_key fehlt oder ist ungültig (32 Byte, base64): '
                . "php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'"
            );
        }
        return $key;
    }
}
