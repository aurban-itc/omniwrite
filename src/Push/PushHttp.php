<?php
declare(strict_types=1);

namespace OmniWrite\Push;

/** Gemeinsame Auswertung der HTTP-Antworten. */
final class PushHttp
{
    /** @param array{status: int, body: string} $res @param list<int> $ok */
    public static function expect(array $res, array $ok, string $what): void
    {
        $s = $res['status'];
        if (in_array($s, $ok, true)) {
            return;
        }
        if ($s === 0) {
            throw new PushException("$what: keine Antwort (Zeitüberschreitung oder Verbindungsfehler)");
        }
        $retry = $s === 408 || $s === 425 || $s === 429 || $s >= 500;
        throw new PushException("$what: HTTP $s", $retry);
    }
}
