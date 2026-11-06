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

## Migrationen

SQL-Dateien in `migrations/` als `NNNN_name.sql`. `bin/migrate.php` wendet neue Dateien der Reihe nach an
(Tabelle `schema_migrations`). Angewendete Dateien werden nicht mehr geändert, Korrekturen kommen als neue Migration.

## Tests und CI

```bash
composer lint   # php -l über alle PHP-Dateien
composer test   # PHPUnit
```

GitHub Actions (`.github/workflows/ci.yml`) führt Lint und Tests auf PHP 8.2/8.3 aus und spielt die Migrationen
zweimal gegen MySQL 8 ein. Die Mandantentrennung ist per Test abgesichert (`tests/Repository`).

## Status

Phase 0 (Grundgerüst). Weiter mit Phase 1 (Kern und Mandanten), siehe Architektur-Dokument.
