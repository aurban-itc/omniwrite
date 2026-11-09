<?php
declare(strict_types=1);

namespace OmniWrite\Core;

use PDO;
use RuntimeException;

/**
 * Führt migrations/NNNN_name.sql in Reihenfolge aus und merkt sich angewendete Dateien.
 * Dateien werden nach dem Anwenden nie mehr geändert; Korrekturen kommen als neue Migration.
 *
 * MySQL committet DDL implizit, ein Abbruch kann eine Datei angewendet, aber nicht verbucht hinterlassen.
 * Regel: jede Migration muss komplett wiederholbar sein (CREATE TABLE IF NOT EXISTS, INSERT IGNORE).
 * Ein nacktes ALTER TABLE ist das nicht; es wird über information_schema abgesichert (siehe 0003_plans.sql).
 * Bereits angewendete Dateien werden nie geändert, Anpassungen kommen als neue Vorwärts-Migration.
 * tests/Core/MigrationReplayTest.php prüft Wiederholbarkeit und Upgrade vom alten Stand gegen MySQL.
 */
final class Migrator
{
    public function __construct(private readonly PDO $pdo, private readonly string $dir)
    {
    }

    /** @return list<string> neu angewendete Migrationen */
    public function migrate(): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                name VARCHAR(190) NOT NULL PRIMARY KEY,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $done = $this->pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $applied = [];
        foreach ($this->pending(array_flip($done)) as $name => $file) {
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException("Migration nicht lesbar: $file");
            }
            // MySQL committet DDL implizit; daher keine Transaktion, Migrationen klein und idempotent halten.
            $this->run($sql);
            $this->pdo->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([$name]);
            $applied[] = $name;
        }
        return $applied;
    }

    /** Jedes Statement einzeln: funktioniert auch bei Hostern, die Mehrfach-Statements pro Abfrage verbieten, und Fehler bleiben eindeutig. */
    private function run(string $sql): void
    {
        foreach (SqlSplitter::split($sql) as $statement) {
            $this->pdo->exec($statement);
        }
    }

    /** @param array<string, int> $done @return array<string, string> name => Pfad */
    private function pending(array $done): array
    {
        $files = glob(rtrim($this->dir, '/') . '/[0-9][0-9][0-9][0-9]_*.sql') ?: [];
        sort($files);
        $pending = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (!isset($done[$name])) {
                $pending[$name] = $file;
            }
        }
        return $pending;
    }
}
