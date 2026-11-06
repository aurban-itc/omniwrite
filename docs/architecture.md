# OmniWrite: Architektur und Umsetzungsplan (Entwurf v1)

Stand: 2026-11-06. Basis: Blog-Automation von Coastal Copy (PRs #14 bis #21), Entscheidungen von Andreas.

## Entscheidungen
- Kunden: eigene Websites (Coastal Copy zuerst) und externe Neukunden.
- Hosting: PHP 8 + MySQL auf Hostinger, Code so geschnitten, dass ein späterer Umzug (Node/Supabase/Vercel) möglich bleibt (saubere Schichten, SQL nur im Repository-Layer, API-first).
- Portal: `omniwrite.urbanlabs.ai` (Login, Admin, Blog-Auslieferung).
- Abrechnung: Andreas rechnet ab; die KI-Keys (Claude, OpenAI) und Resend gehören OmniWrite, Nutzung wird pro Mandant gezählt.
- Sprachen: DE und EN (UI und Inhalte, Sprache pro Site einstellbar).
- Nutzer: Kunden-Registrierung mit Login, Andreas ist Superadmin über alle Mandanten.

## Rollen
- **Superadmin** (Andreas): alle Sites, Kunden, Nutzung/Kosten, Pläne, Impersonation (Support).
- **Kunde / Site-Owner**: eigene Sites, Themen, Empfänger, Style-Guide, Anbindung.
- **Editor**: Posts bearbeiten und freigeben für eine Site.
- **Reviewer** (nur Mail-Link, kein Account): Publish/Edit/Decline per Einmal-Token wie bei Coastal.

## Mandantenmodell
Eine gemeinsame MySQL-DB. `accounts` (Kunde) 1:n `sites` 1:n `posts`, `topics`, `recipients`, `runs`, `media`, `action_tokens`, `site_settings`. Jede Abfrage läuft über einen Repository-Layer, der `site_id` erzwingt (kein Roh-SQL in Controllern). Tabellen: `users`, `account_users` (Rolle), `sites`, `domains`, `usage_events` (Tokens, Bilder, Mails pro Site), `plans`. Zugangsdaten für Anbindungen (SFTP/GitHub-Token) verschlüsselt in der DB (libsodium), Master-Key nur in `config.php`.

## Wie Websites angebunden werden
Hosted-Subdomain heißt: Das Portal liegt unter `omniwrite.urbanlabs.ai`, und jede Site bekommt dort ihren Blog unter `omniwrite.urbanlabs.ai/b/<site-slug>/`. Optional zeigt der Kunde eine eigene Domain (`blog.kunde.de`, CNAME) auf uns; OmniWrite erkennt die Site am Host.

Anbindungsarten (Connectors), pro Site wählbar:
1. **Hosted (Phase 1):** Posts werden vollständig von OmniWrite ausgeliefert (statisches HTML, Sitemap, RSS, `llms.txt`, JSON-LD). Der Kunde verlinkt nur auf den Blog.
2. **API/Feed (Phase 2):** Öffentliche JSON-API und RSS pro Site (`/api/v1/sites/<slug>/posts`), dazu ein JS-Snippet für eingebettete Listen.
3. **Push (Phase 3):** Beim Publish schreibt OmniWrite die fertigen HTML-Dateien per SFTP, GitHub-Commit oder Webhook in die Kundenseite (Coastal-Modell). Coastal wechselt hierauf, damit die URLs `coastalcopy.com/insights/...` erhalten bleiben.

## Was aus dem Coastal-Code wiederverwendet wird
`ai.php` (Claude), `images.php` (OpenAI), `topics.php` (Rotation), `review.php` (Mail und Tokens), `publisher.php` (statische Ausgabe, Sitemap, llms.txt, RSS), `editor.php` und `admin/` (TipTap), `cron/weekly.php`. Anpassungen: `site_id` überall, Coastal-spezifische Texte (Tri-County, Produktseiten, `generate-head.php`) wandern in Site-Einstellungen und Templates, Cron iteriert über alle aktiven Sites mit deren Zeitplan und Zeitzone.

## Zeitplan pro Site
Wochentag, Uhrzeit, Zeitzone und Sprache einstellbar; ein stündlicher Cron prüft, welche Sites fällig sind. Veröffentlicht wird nie automatisch, nur nach Freigabe (Standard, nicht abschaltbar im ersten Release).

## Sicherheit
Passwort-Hashing, CSRF, Rate-Limit, 2FA (TOTP) für Superadmin, Mandantentrennung per Repository-Layer plus Tests, Token-Links nur mit Bestätigungsseite, Upload-Prüfung, Audit-Log, DSGVO (AV-Vertrag, Datenexport/-löschung pro Kunde), Impressum/Datenschutz des Portals.

## Phasen (je ein PR)
0. **Repo-Grundgerüst:** Struktur, README, Config, Migrationen, CI (PHP-Lint, Tests).
1. **Kern und Mandanten:** Schema, Registrierung/Login, Rollen, Superadmin, Sites anlegen.
2. **Extraktion:** Coastal-Logik portieren (Posts, Editor, Themen, Style-Guide pro Site), i18n DE/EN.
3. **KI und Bilder:** Claude/OpenAI mit Nutzungszähler pro Site, Limits pro Plan.
4. **Review-Mail und Cron:** Empfänger pro Site, Token-Links, Mehrmandanten-Cron.
5. **Auslieferung Hosted:** Blog-Seiten, Templates/Theme-Einstellungen, Sitemap, RSS, `llms.txt`, Custom-Domain.
6. **API/Feed und Embed.**
7. **Push-Connectors (SFTP, GitHub, Webhook) und Migration von Coastal.**
8. **Abrechnungsvorbereitung:** Nutzungsberichte, Pläne (Rechnungen stellt Andreas manuell oder später via Stripe).

## Offene Fragen
1. Pläne/Limits: Wie viele Posts pro Monat pro Kunde, gibt es Testzeitraum?
2. Theme: Reicht ein anpassbares Standard-Template (Logo, Farben, Schrift) pro Site für Hosted, oder soll jeder Kunde eigenes HTML-Template liefern?
3. Registrierung: offen für jeden, oder per Einladung/Freigabe durch dich?
4. Coastal-Migration: Soll Coastal die erste Site auf OmniWrite werden (Daten importieren, Push zurück nach `coastalcopy.com`)?
5. DNS: Kannst du `omniwrite.urbanlabs.ai` auf Hostinger zeigen lassen (neue Website oder Subdomain im selben Account)?
