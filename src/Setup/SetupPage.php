<?php
declare(strict_types=1);

namespace OmniWrite\Setup;

use Closure;
use OmniWrite\App;
use OmniWrite\Core\Config;
use OmniWrite\Core\Migrator;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Repository\UserRepository;
use PDO;
use Throwable;

/**
 * Einmalige Einrichtungsseite für Hosting ohne SSH: Migrationen ausführen und den ersten Superadmin anlegen.
 * Ist nur aktiv, solange `setup.token` in config/config.php gesetzt ist; danach Token entfernen.
 */
final class SetupPage
{
    /** @param Closure(): PDO $connect */
    public function __construct(private readonly Config $config, private readonly Closure $connect, private readonly string $migrationsDir)
    {
    }

    /**
     * @param array<string, mixed> $post
     * @return array{status: int, html: string}
     */
    public function handle(string $method, array $post): array
    {
        $token = (string) $this->config->get('setup.token', '');
        if ($token === '') {
            return ['status' => 404, 'html' => 'Not found'];
        }
        if ($method !== 'POST') {
            return $this->page(200, '');
        }
        if (!hash_equals($token, (string) ($post['token'] ?? ''))) {
            usleep(500_000); // bremst Raten
            return $this->page(403, $this->box('Das Setup-Token stimmt nicht.', true));
        }

        try {
            $pdo = ($this->connect)();
        } catch (Throwable $e) {
            error_log('setup: ' . $e->getMessage());
            return $this->page(500, $this->box('Keine Verbindung zur Datenbank. Bitte db.dsn, db.user und db.password in config/config.php prüfen.', true));
        }

        $action = (string) ($post['action'] ?? '');
        try {
            if ($action === 'migrate') {
                $applied = (new Migrator($pdo, $this->migrationsDir))->migrate();
                $msg = $applied === [] ? 'Datenbank ist aktuell, nichts zu tun.' : 'Angewendet: ' . implode(', ', $applied);
                return $this->page(200, $this->box($msg, false));
            }
            if ($action === 'superadmin') {
                return $this->createSuperadmin($pdo, $post);
            }
        } catch (Throwable $e) {
            error_log('setup: ' . $e);
            // Nur mit gültigem Setup-Token erreichbar; die Meldung hilft bei der Einrichtung (kein Passwort darin, PDO nennt keine Zugangsdaten).
            return $this->page(500, $this->box('Fehler beim Ausführen: ' . mb_substr($e->getMessage(), 0, 500), true));
        }
        return $this->page(200, '');
    }

    /** @param array<string, mixed> $post @return array{status: int, html: string} */
    private function createSuperadmin(PDO $pdo, array $post): array
    {
        $users = new UserRepository($pdo);
        // Prüfen und Anlegen unter einer Sperre: zwei gleichzeitige Anfragen können nicht beide "noch keiner" sehen.
        return $users->withLock('omniwrite_setup_superadmin', function () use ($pdo, $users, $post): array {
            if ($users->hasSuperadmin()) {
                return $this->page(409, $this->box('Es gibt schon einen Superadmin. Weitere werden hier nicht angelegt.', true));
            }
            try {
                $user = App::buildAuth($pdo, $this->config)->createSuperadmin(
                    trim((string) ($post['email'] ?? '')),
                    trim((string) ($post['name'] ?? '')) ?: 'Superadmin',
                    (string) ($post['password'] ?? ''),
                );
            } catch (ValidationException $e) {
                return $this->page(422, $this->box('Eingabe ungültig: ' . implode(', ', array_values($e->errors)), true));
            }
            return $this->page(200, $this->box('Superadmin angelegt: ' . $user->email . '. Bitte jetzt `setup.token` aus config/config.php entfernen und anmelden; 2FA ist für Superadmins Pflicht.', false));
        });
    }

    private function box(string $text, bool $error): string
    {
        return '<p class="' . ($error ? 'err' : 'ok') . '">' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }

    /** @return array{status: int, html: string} */
    private function page(int $status, string $message): array
    {
        $html = <<<HTML
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>OmniWrite Einrichtung</title>
<style>body{font:16px system-ui,sans-serif;max-width:32rem;margin:2rem auto;padding:0 1rem}input,button{font:inherit;padding:.5rem;margin:.25rem 0;width:100%;box-sizing:border-box}
.err{color:#b00020}.ok{color:#116611}fieldset{margin:1.5rem 0}</style></head><body>
<h1>OmniWrite Einrichtung</h1>
$message
<form method="post" autocomplete="off"><fieldset><legend>1. Datenbank einrichten</legend>
<label>Setup-Token <input type="password" name="token" required></label>
<input type="hidden" name="action" value="migrate"><button>Migrationen ausführen</button></fieldset></form>
<form method="post" autocomplete="off"><fieldset><legend>2. Ersten Superadmin anlegen</legend>
<label>Setup-Token <input type="password" name="token" required></label>
<label>E-Mail <input type="email" name="email" required></label>
<label>Name <input name="name" value="Andreas"></label>
<label>Passwort (mind. 12 Zeichen) <input type="password" name="password" minlength="12" required></label>
<input type="hidden" name="action" value="superadmin"><button>Superadmin anlegen</button></fieldset></form>
<p>Danach <code>setup.token</code> aus <code>config/config.php</code> entfernen, dann ist diese Seite aus.</p>
</body></html>
HTML;
        return ['status' => $status, 'html' => $html];
    }
}
