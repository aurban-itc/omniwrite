# Coastal Copy nach OmniWrite umziehen

Einfachster Weg: Coastal-Blog wird eine OmniWrite-Site mit eigener Subdomain (z. B. `insights.coastalcopy.com`), alte URLs werden per 301 umgeleitet. Das Coastal-Hosting behält nur noch die statische Website.

1. **Account und Site anlegen** im Portal unter **Administration → Neuen Account anlegen** (Owner-Nutzer mit Startpasswort), danach auf der Account-Seite die Website (Slug z. B. `coastal`, Sprache EN).
2. **Export** auf dem Coastal-Hosting (liest dort `_private/config.php`, enthält keine Zugangsdaten):
   `php tools/coastal-export.php /pfad/zu/_private/config.php /pfad/zu/public_html export.json`
   (`tools/coastal-export.php` ist eigenständig; Datei hochladen/kopieren genügt.)
   **Ohne SSH und Cron:** Datei als `coastal-export.php` direkt in `public_html` legen, oben `WEB_TOKEN` auf einen langen Zufallswert setzen (mind. 24 Zeichen), `https://<domain>/coastal-export.php?t=<WEB_TOKEN>` aufrufen; der Download startet, danach löscht sich die Datei selbst (sonst sofort von Hand löschen). Ohne gesetzten Token antwortet sie nur mit 404. Auf Coastal sperrt die `.htaccess` PHP außer `submit.php`: Datei deshalb in `public_html/api/` legen und dort zusätzlich eine `.htaccess` mit `<Files "coastal-export.php">Require all granted</Files>` anlegen, URL `https://<domain>/api/coastal-export.php?t=…`; beide Dateien danach löschen. Beiträge, Themen, Einstellungen (ohne Schlüssel), Empfänger und die verwendeten Bilder landen in `export.json`.
3. **Import** auf dem OmniWrite-Server: ohne SSH im Portal unter **Administration → Coastal-Import** (Ziel-Website wählen, `export.json` hochladen; Upload-Limit des Servers beachten), oder per Kommandozeile `php bin/import-coastal.php coastal export.json`.
   Wiederholbar: vorhandene Slugs, Themen-Titel und E-Mail-Adressen werden übersprungen, Einstellungen nur gesetzt, wenn leer. Status bleiben erhalten (`review` wird `in_review`). Bilder werden in den OmniWrite-Speicher übernommen; fehlende bleiben als Link auf `www.coastalcopy.com`. Relative Links im Text zeigen danach auf `www.coastalcopy.com`.
4. **Domain**: im Portal unter Blog-Einstellungen die Subdomain eintragen, TXT- und CNAME-Eintrag setzen, prüfen lassen (siehe README).
5. **Weiterleitungen**: `php bin/coastal-redirects.php export.json https://insights.coastalcopy.com insights-index.json` erzeugt Regeln für `/insights/<slug>.html`, `/insights/` und den Feed; in die `.htaccess` des Coastal-Hostings einfügen (z. B. neben den Regeln aus `generate-redirects.php`). Die alten Artikel-Links der Seite `Coastal-Insights.html#<alter-schlüssel>` sind Hash-Links, die kein Server sieht; die Seite leitet sie über `insights/index.json` um. Darum `insights-index.json` bei Coastal als `insights/index.json` einspielen (ersetzt die Datei; die Karten zeigen dann auf die neuen Adressen).
6. **Coastal abschalten**: den wöchentlichen Cron und den Admin des alten Blogs deaktivieren, sobald der OmniWrite-Cron läuft (sonst entstehen doppelte Beiträge und Mails). Auf der Insights-Seite von Coastal Link oder `embed.js` (siehe Blog-Einstellungen) einsetzen.
7. `export.json` löschen (enthält Empfänger-Adressen).

## Bilder fehlen nach einem Deployment (404 unter /media/…)

Liegt `storage.path` im Deploy-Ordner (Standard: `storage/` im Projekt), kann ein Deployment die Bilder entfernen. Dann zeigen übernommene Beiträge ins Leere.

1. In `config/config.php` `storage.path` auf einen Ordner außerhalb des Deploy-Ordners setzen (beschreibbar für PHP), z. B. `/home/<user>/omniwrite-storage`.
2. Den Export erneut erzeugen und unter `/admin/import` hochladen. Bestehende Beiträge bleiben, nur fehlende Bilder (Titelbild und Bilder im Text an gleicher Stelle) werden neu angelegt. Das geschieht nur bei Beiträgen, die seit dem Import nicht bearbeitet wurden (gleiche Änderungszeit wie im Export, auch Veröffentlichen oder Zurückziehen zählt als Änderung). Bei bearbeiteten Beiträgen gibt es eine Warnung; zum Wiederherstellen den Beitrag löschen und den Export erneut importieren.
