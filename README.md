# OmniWrite

Mandantenfähige Blog-Automation: KI-Entwurf (Claude), Bild (OpenAI), Review-Mail mit Publish/Edit/Decline,
wöchentlicher Cron, SEO/GEO (Sitemap, `llms.txt`, RSS). Veröffentlicht wird immer nur nach manueller Freigabe.

Hervorgegangen aus der Blog-Automation von Coastal Copy. Architektur und Phasenplan: [docs/architecture.md](docs/architecture.md).

## Stack

PHP 8.2+ und MySQL 8 / MariaDB auf Hostinger. Der Code ist geschichtet, damit ein späterer Umzug möglich bleibt.

| Schicht | Ordner | Regel |
|---|---|---|
| HTTP | `src/Http`, `public/` | Dünn: Request rein, Response raus, keine Geschäftslogik, kein SQL |
| Domain | `src/Domain` | Reine Objekte und Regeln, kein I/O |
| Repository | `src/Repository` | **Einziger Ort für SQL.** Mandantengebundene Repositories erben von `SiteScopedRepository` und erzwingen `site_id` |
| Infrastruktur | `src/Infrastructure`, `src/Core` | Config, DB, Migrationen, später KI-, Mail- und Connector-Clients |

## Einrichtung

```bash
composer install
cp config/config.example.php config/config.php   # nicht committen, Werte eintragen
php bin/migrate.php                              # Migrationen anwenden
# Ohne SSH: `setup.token` in config/config.php setzen, /setup.php im Browser öffnen (Migrationen + erster Superadmin), Token danach entfernen.
php -S localhost:8000 -t public                  # lokal starten, /health prüfen
```

Lokal in `config/config.php` `app.base_url` auf `http://localhost:8000` setzen: Bei einer nicht-https `base_url` leitet
OmniWrite nicht auf HTTPS um (sonst würde der lokale Server auf die Produktions-URL umleiten).

`config/config.php` enthält Datenbank, API-Keys und den Master-Key (libsodium) und steht in `.gitignore`.

HTTPS: Mit `app.force_https` (Standard an) leitet PHP HTTP auf die `base_url` um. `X-Forwarded-Proto` wird nur beachtet,
wenn `app.trust_forwarded_proto` gesetzt ist, also hinter einem TLS-beendenden Proxy, der den Header selbst setzt.

Ersten Superadmin anlegen (danach unter `/login` anmelden):

```bash
OMNIWRITE_ADMIN_PASSWORD='mindestens-10-zeichen' php bin/create-superadmin.php mail@beispiel.de "Name"
```

Die Selbstregistrierung steuert `auth.registration` in der Config: `open` (sofort aktiv), `approval`
(Superadmin schaltet unter `/admin` frei) oder `closed`.

## Migrationen

SQL-Dateien in `migrations/` als `NNNN_name.sql`. `bin/migrate.php` wendet neue Dateien der Reihe nach an
(Tabelle `schema_migrations`). Angewendete Dateien werden nicht mehr geändert, Korrekturen kommen als neue Migration.
MySQL committet DDL sofort. Jede Migration muss deshalb vollständig wiederholbar sein (`CREATE TABLE IF NOT EXISTS`,
`INSERT IGNORE`); ein `ALTER TABLE` nur abgesichert über `information_schema` (Beispiel: `0003_plans.sql`).
Angewendete Dateien werden nie geändert, Anpassungen kommen als neue Migration. Tests gegen MySQL spielen alle
Migrationen erneut ein und prüfen das Upgrade vom ursprünglichen Phase-0-Stand.

## Tests und CI

```bash
composer lint   # php -l über alle PHP-Dateien
composer test   # PHPUnit
```

GitHub Actions (`.github/workflows/ci.yml`) führt Lint und Tests auf PHP 8.2/8.3 aus und spielt die Migrationen
zweimal gegen MySQL 8 ein. Die Tests laufen lokal gegen SQLite (`tests/Support/SqliteSchema.php` spiegelt die
Migrationen, bei neuen Migrationen mitpflegen) und in der CI zusätzlich gegen MySQL mit den echten Migrationen
(`OMNIWRITE_TEST_DSN`). Die Mandantentrennung ist per Test abgesichert (`tests/Repository`, `tests/Service`).

## Status

Phase 1 (Kern und Mandanten): Registrierung, Login, Rollen (Owner, Editor, Superadmin), Sites anlegen, Superadmin-Bereich.
2FA (TOTP, Pflicht für Superadmins, optional für alle; Recovery-Codes) und Impersonation für den Support (mit Banner und Audit-Log).

Phase 2 (Blog-Kern, aus Coastal portiert): pro Site Beiträge (Editor, Entwurf → Prüfung → veröffentlicht/abgelehnt), Themenliste mit Rotation (oben nach unten, dann von vorn) und Einstellungen (Stilrichtlinie, Firmenfakten, Bildstil; Standardtexte DE/EN). Unter `/sites/<kürzel>/posts|topics|settings`. Rechte: Owner und Superadmin dürfen veröffentlichen, ablehnen, löschen und Einstellungen ändern; Editor schreibt Entwürfe, bearbeitet Themen und reicht zur Prüfung ein. Fremde Sites antworten mit 404. Veröffentlichung nach außen (Hosted-Seiten, Feed, Push) in den Phasen 5 bis 7; bis dahin ändert "veröffentlicht" nur den Status.
Braucht `security.master_key` in der Config. Noch offen: Passwort-Reset (braucht den Mailversand aus Phase 4).

Phase 3 (KI): Beitrag aus Thema generieren (`/sites/<kürzel>/generate`), Text per Anweisung überarbeiten, SEO-Felder und Titelbild erzeugen, Themen vorschlagen lassen. Ergebnisse sind immer Entwürfe. Jede KI-Anfrage wird pro Site gezählt (`usage_events`); Stundenlimits sind fest (Beiträge 20, Textaufrufe 60, Bilder 15), das Monatslimit für KI-Beiträge kommt aus `plans.posts_per_month` (NULL = unbegrenzt). Die KI-Schlüssel gehören OmniWrite und stehen nur in `config/config.php` (`ai.anthropic_api_key`, `ai.openai_api_key`, optional Modelle); Bilder liegen unter `storage.path` (muss beschreibbar sein) und werden über `/media/<site>/<datei>` ausgeliefert.

Phase 4 (Review-Mail und Cron): pro Site Empfänger (`/sites/<kürzel>/recipients`, kein Konto nötig) und "Zur Prüfung senden" im Editor. Jeder aktive Empfänger bekommt per Resend eine Mail mit eigenen Links zum Freigeben, Bearbeiten und Ablehnen (72 Stunden gültig, nur als SHA-256 gespeichert). Ein Klick auf den Link zeigt nur eine Bestätigungsseite; erst der Button dort veröffentlicht bzw. lehnt ab (Mail-Scanner lösen nichts aus). Zeitplan pro Site (`/sites/<kürzel>/schedule`: Wochentag und Stunde in der Zeitzone der Site, Protokoll, "Jetzt ausführen"). Der stündliche Cron `0 * * * * php bin/cron.php` erzeugt für fällige Sites das nächste Thema als Entwurf (im Namen des ersten Owners, zählt aufs KI-Kontingent), schickt die Review-Mail und meldet Fehler per Mail an die Owner. Es wird nie automatisch veröffentlicht. Braucht `mail.resend_api_key` und `mail.from` (Absenderdomain bei Resend verifizieren).

Weiter mit Phase 5 (Auslieferung Hosted), siehe Architektur-Dokument.

Phase 5 (Auslieferung Hosted): Jede Site hat ein öffentliches Blog unter `/b/<kürzel>/` (Startseite mit Seiten, Beiträge mit JSON-LD und canonical, `sitemap.xml`, `feed.xml` als RSS, `llms.txt`). Es erscheinen nur veröffentlichte Beiträge. Unter `/sites/<kürzel>/blog` stellen Owner das Aussehen ein (Untertitel, Akzentfarbe, Schrift, Fußzeile, Link zur eigenen Website) und hinterlegen optional eine eigene Domain. Die Domain muss per CNAME auf `omniwrite.urbanlabs.ai` zeigen (eine Hauptdomain ohne Subdomain per A-Eintrag auf dieselbe IP) und mit einem TXT-Eintrag `_omniwrite.<domain>` (Wert steht auf der Seite, an Site und Domain gebunden, braucht `security.master_key`) die Inhaberschaft belegen; erst nach "DNS prüfen" liefert OmniWrite dort aus, dann an der Wurzel (`https://blog.kunde.de/`) und ohne Login oder Backend. Auf Hostinger muss die Domain zusätzlich als Alias der Website hinzugefügt und das Zertifikat dort eingerichtet werden. Sitemap, Feed, canonical und `llms.txt` nutzen immer die bevorzugte Adresse (eigene Domain, sobald verifiziert).

Phase 6 (API und Einbettung): Öffentliche, schreibgeschützte JSON-API für veröffentlichte Beiträge: `GET /api/v1/sites/<kürzel>/posts` (Parameter `page`, `per_page` bis 50) und `.../posts/<slug>` (mit `body_html`); auf einer verifizierten eigenen Domain `GET /api/v1/posts` und `/api/v1/posts/<slug>`. Antworten tragen `Access-Control-Allow-Origin: *` (keine Cookies) und sind 5 Minuten cachebar. Der RSS-Feed (`feed.xml`) kam schon mit Phase 5. Einbettung per `<script src="https://omniwrite.urbanlabs.ai/assets/embed.js" data-site="<kürzel>" data-limit="5" async></script>`; den fertigen Code zeigt die Seite "Blog & Domain". `body_html` ist beim Speichern per Allowlist gesäubert, Verbraucher sollten es trotzdem nur als HTML-Fragment einfügen.

