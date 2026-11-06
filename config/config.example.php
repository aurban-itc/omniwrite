<?php
// Kopieren nach config/config.php (wird nicht committet) und Werte setzen.
declare(strict_types=1);

return [
    'app' => [
        'env'      => 'production',            // production | development
        'base_url' => 'https://omniwrite.urbanlabs.ai',
        'default_locale' => 'de',              // de | en
        // HTTP wird auf base_url (https) umgeleitet. Hinter einem TLS-beendenden Proxy trust_forwarded_proto
        // nur aktivieren, wenn der Proxy X-Forwarded-Proto selbst setzt und Client-Werte überschreibt.
        'force_https' => true,
        'trust_forwarded_proto' => false,
    ],
    'auth' => [
        // open = sofort aktiv, approval = Superadmin schaltet frei, closed = keine Selbstregistrierung
        'registration' => 'approval',
    ],
    'db' => [
        'dsn'      => 'mysql:host=localhost;dbname=omniwrite;charset=utf8mb4',
        'user'     => 'omniwrite',
        'password' => '',
    ],
    'security' => [
        // 32 Byte, base64-kodiert: php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
        // Verschlüsselt Anbindungs-Zugangsdaten (libsodium). Niemals ändern ohne Re-Encrypt.
        'master_key' => '',
    ],
    'ai' => [
        'anthropic_api_key' => '',
        'openai_api_key'    => '',
    ],
    'mail' => [
        'resend_api_key' => '',
        'from'           => 'OmniWrite <noreply@urbanlabs.ai>',
    ],
];
