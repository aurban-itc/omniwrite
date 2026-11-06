<?php
declare(strict_types=1);

namespace OmniWrite\Core;

use PDO;

/** Erzeugt die PDO-Verbindung. SQL gehört ausschließlich in src/Repository und src/Core/Migrator. */
final class Database
{
    public static function connect(Config $config): PDO
    {
        return new PDO(
            (string) $config->get('db.dsn'),
            (string) $config->get('db.user'),
            (string) $config->get('db.password'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }
}
