# Coastal Copy nach OmniWrite umziehen

Einfachster Weg: Coastal-Blog wird eine OmniWrite-Site mit eigener Subdomain (z. B. `insights.coastalcopy.com`), alte URLs werden per 301 umgeleitet. Das Coastal-Hosting behält nur noch die statische Website.

1. **Site anlegen** im Portal (Slug z. B. `coastal`, Sprache EN), Empfänger/Owner einladen.
2. **Export** auf dem Coastal-Hosting (liest dort `_private/config.php`, enthält keine Zugangsdaten):
   `php tools/coastal-export.php /pfad/zu/_private/config.php /pfad/zu/public_html export.json`
   (`tools/coastal-export.php` ist eigenständig; Datei hochladen/kopieren genügt.) Beiträge, Themen, Einstellungen (ohne Schlüssel), Empfänger und die verwendeten Bilder landen in `export.json`.
3. **Import** auf dem OmniWrite-Server: `php bin/import-coastal.php coastal export.json`.
   Wiederholbar: vorhandene Slugs, Themen-Titel und E-Mail-Adressen werden übersprungen, Einstellungen nur gesetzt, wenn leer. Status bleiben erhalten (`review` wird `in_review`). Bilder werden in den OmniWrite-Speicher übernommen; fehlende bleiben als Link auf `www.coastalcopy.com`. Relative Links im Text zeigen danach auf `www.coastalcopy.com`.
4. **Domain**: im Portal unter Blog-Einstellungen die Subdomain eintragen, TXT- und CNAME-Eintrag setzen, prüfen lassen (siehe README).
5. **Weiterleitungen**: `php bin/coastal-redirects.php export.json https://insights.coastalcopy.com insights-index.json` erzeugt Regeln für `/insights/<slug>.html`, `/insights/` und den Feed; in die `.htaccess` des Coastal-Hostings einfügen (z. B. neben den Regeln aus `generate-redirects.php`). Die alten Artikel-Links der Seite `Coastal-Insights.html#<alter-schlüssel>` sind Hash-Links, die kein Server sieht; die Seite leitet sie über `insights/index.json` um. Darum `insights-index.json` bei Coastal als `insights/index.json` einspielen (ersetzt die Datei; die Karten zeigen dann auf die neuen Adressen).
6. **Coastal abschalten**: den wöchentlichen Cron und den Admin des alten Blogs deaktivieren, sobald der OmniWrite-Cron läuft (sonst entstehen doppelte Beiträge und Mails). Auf der Insights-Seite von Coastal Link oder `embed.js` (siehe Blog-Einstellungen) einsetzen.
7. `export.json` löschen (enthält Empfänger-Adressen).
