# Push-Connectors

Veröffentlichte Beiträge können automatisch an externe Ziele übertragen werden. Konfiguration pro Site unter **Connectors** (`/sites/{slug}/connectors`, nur Owner/Admin).

Die Übertragung läuft über `php bin/cron.php` (stündlich) oder sofort über „Jetzt senden“. Dafür muss der Cron-Job eingerichtet sein.

## Verhalten

- Abgleich statt Ereignisse: pro Ziel und Beitrag wird der Hash des Payloads gespeichert. Neu, geändert oder zurückgezogen → es wird genau einmal gesendet.
- Fehler: Wiederholung mit Backoff (300 s · 2^(n-1), max. 6 h, höchstens 8 Versuche). Dauerhafte Fehler (4xx außer 408/425/429) werden nicht wiederholt, bis sich der Inhalt ändert oder „Jetzt senden“ gedrückt wird.
- Zugangsdaten (Secret/Token) werden verschlüsselt gespeichert und nie wieder angezeigt.
- Nur HTTPS; private/interne Adressen werden blockiert (SSRF-Schutz, IP-Pinning), keine Redirects.

## Webhook

`POST` mit JSON, Header `X-OmniWrite-Event` (`post.published`, `post.updated`, `post.unpublished`) und, falls ein Secret gesetzt ist, `X-OmniWrite-Signature: sha256=<HMAC-SHA256 des Rohbodys>`. Antwort 2xx = zugestellt.

## GitHub

Schreibt pro Beitrag eine Markdown-Datei mit Frontmatter in ein Repository (Contents API). Zurückgezogene Beiträge und umbenannte Slugs löschen die alte Datei. Token: Fine-grained mit **Contents: Read and write** nur für das Ziel-Repository.

## Noch offen

SFTP-Connector (eigener PR).
