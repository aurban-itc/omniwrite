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
php -S localhost:8000 -t public                  # lokal starten, /health prüfen
```

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
Noch offen in dieser Phase: 2FA (TOTP) für den Superadmin, Impersonation, Passwort-Reset (braucht den Mailversand aus Phase 4).
Weiter mit Phase 2 (Extraktion), siehe Architektur-Dokument.
