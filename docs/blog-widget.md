# Blog-Komponente (`blog-widget.js`)

Zeigt Beitragsliste und Artikel **im Markup und Design der einbettenden Seite**. Die Seite liefert HTML und CSS, die Komponente die Daten aus der öffentlichen API (`/api/v1/sites/<kürzel>/posts`). Keine eigene Domain nötig.

```html
<div data-ow-blog data-ow-site="coastal" data-ow-reading-class="reading" data-ow-title-suffix=" | Coastal Insights">
  <div data-ow-list data-ow-layout="featured" data-ow-wrap-class="insights-wrap" data-ow-featured-class="featured"
       data-ow-side-class="side-stack" data-ow-row-class="grid-row">
    <template data-ow-template="card">
      <a class="post-card" data-ow-href>
        <div class="cover"><img data-ow-src="cover_image" alt=""><span data-ow="category"></span></div>
        <h3 data-ow="title"></h3><p data-ow="excerpt"></p>
        <span data-ow="date"></span> · <span data-ow="read_minutes"></span> min read
      </a>
    </template>
    <template data-ow-template="empty"><p>Bald gibt es hier neue Artikel.</p></template>
  </div>
  <section class="article" data-ow-article hidden>
    <a href="#">← Alle Artikel</a>
    <div data-ow-hide-if-empty><img data-ow-src="cover_image" alt=""></div>
    <h1 data-ow="title"></h1>
    <div data-ow-html="body_html"></div>
  </section>
</div>
<script src="https://omniwrite.urbanlabs.ai/assets/blog-widget.js" async></script>
```

- **Felder:** `data-ow="title|excerpt|category|date|read_minutes"` (Text), `data-ow-html="body_html"` (Artikeltext), `data-ow-src="cover_image"` (Bild; fehlt es, wird das Element entfernt bzw. in der Artikelansicht versteckt), `data-ow-href` (Link `#<slug>`).
- **Ansichten:** Liste und Artikel wechseln über den URL-Hash (`Seite.html#slug`). Am Root setzt die Komponente `data-ow-view="list|article"` und optional eine Klasse am `<body>` (`data-ow-reading-class`), damit die Seite ihren Kopfbereich ausblenden kann.
- **Layout `featured`:** erste Karte groß, zwei daneben, danach Reihen zu drei. Klassen über `data-ow-wrap-class`, `-featured-class`, `-side-class`, `-row-class`. Ohne `data-ow-layout` werden die Karten einfach nacheinander eingefügt.
- **Optionen am Root:** `data-ow-per-page` (bis 50), `data-ow-title-suffix`, `data-ow-date-locale`, `data-ow-months` (zwölf Monatsnamen, z. B. für „Sept 2, 2026“).
- **Sicherheit:** Texte nur per `textContent`; der Artikeltext wird aus dem (serverseitig schon gesäuberten) HTML über eine Positivliste neu aufgebaut, Bilder nur über https, Links nur http(s)/mailto/tel.
- **Suchmaschinen:** Inhalte kommen per JavaScript und sind per Hash adressiert. Die gehosteten Seiten unter `/b/<kürzel>` bleiben die indexierbare Fassung.
