<?php
declare(strict_types=1);

namespace OmniWrite\Core;

use PDO;

/** Erzeugt die PDO-Verbindung. SQL gehört ausschließlich in src/Repository und src/Core/Migrator. */
final class Database
{
    public static function connect(Config $config): PDO
    {
        $pdo = new PDO(
            (string) $config->get('db.dsn'),
            (string) $config->get('db.user'),
            (string) $config->get('db.password'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
        // KI-Aufrufe dauern bis zu mehrere Minuten; sonst trennt der Server die untätige Verbindung ("server has gone away").
        try {
            $pdo->exec('SET SESSION wait_timeout = 900');
        } catch (\Throwable) {
            // Anbieter erlaubt es nicht oder kein MySQL: ohne Anpassung weiterarbeiten.
        }
        return $pdo;
    }
}
