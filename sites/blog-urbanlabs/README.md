# blog.urbanlabs.ai

Statische Blog-Seite (kein Build, kein PHP). Sie bindet die Beiträge aus OmniWrite über `blog-widget.js` ein (siehe `docs/blog-widget.md`).

## Dateien
- `index.html` – Seite mit Widget (Liste, Artikelansicht, „Mehr laden“)
- `style.css` – Design (Farb-Tokens oben in `:root`)

## Einrichtung
1. In OmniWrite eine Site für den urbanlabs-Blog anlegen und Beiträge veröffentlichen. Deren **Kürzel** in `index.html` bei `data-ow-site="urbanlabs"` eintragen (die Widget-API ist öffentlich, ein API-Key ist nicht nötig).
2. Hostinger hPanel → Websites → Subdomain `blog.urbanlabs.ai` anlegen (Dokumentenstamm z. B. `public_html/blog`).
3. `index.html` und `style.css` per Dateimanager in diesen Ordner hochladen.
4. SSL für die Subdomain aktivieren. Fertig.

Artikel-Links sind Hash-Links (`blog.urbanlabs.ai/#slug`). Die per JavaScript geladenen Inhalte sind für Suchmaschinen eingeschränkt; die indexierbare Fassung liefert OmniWrite unter `/b/<kürzel>`.
