<?php
declare(strict_types=1);

namespace OmniWrite\Core;

/**
 * Zerlegt eine SQL-Datei in einzelne Statements (Trenner ";" außerhalb von Zeichenketten und Kommentaren).
 * Nötig, weil manche Hoster Mehrfach-Statements pro Abfrage abschalten; so läuft jede Migration überall Statement für Statement.
 */
final class SqlSplitter
{
    /** @return list<string> */
    public static function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $next = $sql[$i + 1] ?? '';
            if ($c === '-' && $next === '-' || $c === '#') { // Zeilenkommentar bis Zeilenende (nicht Teil des Statements)
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end;
                $current .= "\n";
                continue;
            }
            if ($c === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 1;
                $current .= ' ';
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $current .= $c;
                for ($i++; $i < $len; $i++) {
                    $d = $sql[$i];
                    $current .= $d;
                    if ($d === '\\' && $c !== '`' && $i + 1 < $len) {
                        $current .= $sql[++$i];
                        continue;
                    }
                    if ($d === $c) {
                        if (($sql[$i + 1] ?? '') === $c) { // verdoppeltes Anführungszeichen bleibt in der Zeichenkette
                            $current .= $sql[++$i];
                            continue;
                        }
                        break;
                    }
                }
                continue;
            }
            if ($c === ';') {
                self::push($statements, $current);
                $current = '';
                continue;
            }
            $current .= $c;
        }
        self::push($statements, $current);
        return $statements;
    }

    /** @param list<string> $statements */
    private static function push(array &$statements, string $statement): void
    {
        $statement = trim($statement);
        if ($statement !== '') {
            $statements[] = $statement;
        }
    }
}
