<?php
declare(strict_types=1);

namespace OmniWrite\Net;

/** DNS-Abfragen für die Domain-Prüfung; austauschbar, damit Tests ohne Netz laufen. */
interface DnsResolver
{
    /** @return list<string> CNAME-Ziele des Hosts, kleingeschrieben, ohne Punkt am Ende */
    public function cname(string $host): array;

    /** @return list<string> TXT-Einträge des Hosts (je Eintrag zusammengesetzt) */
    public function txt(string $host): array;

    /** @return list<string> IPv4-Adressen des Hosts */
    public function addresses(string $host): array;
}
