/* OmniWrite Blog-Komponente: zeigt Liste und Artikel im Markup und Design der einbettenden Seite.
 *
 *   <div data-ow-blog data-ow-site="kuerzel" data-ow-reading-class="reading">
 *     <div data-ow-list data-ow-layout="featured" …>   ← Karten aus <template data-ow-template="card"> (optional <template data-ow-template="empty">)
 *     <section data-ow-article hidden>                 ← Artikel-Ansicht, gefüllt aus data-ow-Feldern
 *   </div>
 *   <script src="https://…/assets/blog-widget.js" async></script>
 *
 * Felder (Attribut data-ow="feld", Wert wird als Text gesetzt): title, excerpt, category, date, read_minutes.
 *   data-ow-html="body_html"      Artikeltext (wird gegen eine Positivliste neu aufgebaut)
 *   data-ow-src="cover_image"     Bild-URL; ohne Wert wird das Element entfernt bzw. in der Artikel-Ansicht versteckt (data-ow-alt="cover_alt" setzt den Alt-Text;
 *                                 data-ow-hide-if-empty am Elternelement versteckt auch den Rahmen)
 *   data-ow-href                  Link auf den Beitrag (#kürzel auf derselben Seite)
 * Optionen am Root: data-ow-months (12 Monatsnamen, durch Komma getrennt, für Datum als "Sept 2, 2026"), data-ow-per-page (max. 50, Standard 24), data-ow-reading-class (Klasse am <body> in der Artikel-Ansicht),
 *   data-ow-title-suffix (Seitentitel = Artikeltitel + Suffix), data-ow-date-locale.
 * Layout "featured" am Listen-Element: erste Karte groß, zwei daneben, danach Reihen zu drei; Klassen über
 *   data-ow-wrap-class, data-ow-featured-class, data-ow-side-class, data-ow-row-class.
 * Alle Inhalte werden mit textContent/Attributen gesetzt, nie mit innerHTML; Artikeltext nur über die Positivliste unten.
 */
(function () {
  'use strict';
  var script = document.currentScript;
  var scriptSrc = script && script.src ? script.src : (document.querySelector('script[src*="blog-widget.js"]') || {}).src;
  if (!scriptSrc) return;
  var origin = new URL(scriptSrc, location.href).origin;
  var SLUG = /^[A-Za-z0-9][A-Za-z0-9_-]*$/;
  var TAGS = { P: 1, H2: 1, H3: 1, H4: 1, UL: 1, OL: 1, LI: 1, A: 1, STRONG: 1, B: 1, EM: 1, I: 1, BR: 1, BLOCKQUOTE: 1, IMG: 1, FIGURE: 1, FIGCAPTION: 1, CODE: 1, PRE: 1, HR: 1, TABLE: 1, THEAD: 1, TBODY: 1, TR: 1, TH: 1, TD: 1 };

  function init(root) {
    var site = root.getAttribute('data-ow-site') || '';
    if (!/^[a-z0-9-]+$/i.test(site)) return;
    var perPage = Math.max(1, Math.min(50, parseInt(root.getAttribute('data-ow-per-page') || '24', 10) || 24));
    var readingClass = root.getAttribute('data-ow-reading-class') || '';
    var suffix = root.getAttribute('data-ow-title-suffix') || '';
    var list = root.querySelector('[data-ow-list]');
    var article = root.querySelector('[data-ow-article]');
    var cardTpl = root.querySelector('template[data-ow-template="card"]');
    var emptyTpl = root.querySelector('template[data-ow-template="empty"]');
    var baseTitle = document.title;
    var api = origin + '/api/v1/sites/' + encodeURIComponent(site) + '/posts';
    var cache = null;

    function getJson(url) {
      return fetch(url, { credentials: 'omit' }).then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.json(); });
    }
    function locale() { return root.getAttribute('data-ow-date-locale') || (cache && cache.site && cache.site.locale) || undefined; }
    function fmtDate(iso) {
      var d = iso ? new Date(iso) : null;
      var months = (root.getAttribute('data-ow-months') || '').split(',');
      if (d && !isNaN(d) && months.length === 12) return months[d.getUTCMonth()].trim() + ' ' + d.getUTCDate() + ', ' + d.getUTCFullYear(); // eigene Monatsnamen, Schreibweise "Mon T, JJJJ"
      return d && !isNaN(d) ? d.toLocaleDateString(locale(), { year: 'numeric', month: 'short', day: 'numeric' }) : '';
    }
    function value(p, f) {
      if (f === 'date') return fmtDate(p.published_at);
      var v = p[f];
      return v === null || v === undefined ? '' : String(v);
    }
    function okUrl(u) { return typeof u === 'string' && /^https:\/\//i.test(u); }

    // Positivliste: baut den Artikeltext aus dem (serverseitig schon gesäuberten) HTML neu auf.
    function clean(src, dst) {
      Array.prototype.forEach.call(src.childNodes, function (n) {
        if (n.nodeType === 3) { dst.appendChild(document.createTextNode(n.nodeValue)); return; }
        if (n.nodeType !== 1 || !TAGS[n.tagName]) return;
        var e = document.createElement(n.tagName.toLowerCase());
        if (n.tagName === 'A') {
          var h = n.getAttribute('href') || '';
          if (/^(https?:|mailto:|tel:)/i.test(h)) { e.setAttribute('href', h); e.setAttribute('rel', 'noopener'); }
        } else if (n.tagName === 'IMG') {
          var s = n.getAttribute('src') || '';
          if (!okUrl(s)) return;
          e.setAttribute('src', s);
          e.setAttribute('alt', n.getAttribute('alt') || '');
          e.setAttribute('loading', 'lazy');
        }
        clean(n, e);
        dst.appendChild(e);
      });
    }

    function bind(el, p, href, reuse) {
      var nodes = [el].concat(Array.prototype.slice.call(el.querySelectorAll('[data-ow],[data-ow-html],[data-ow-src],[data-ow-href]')));
      nodes.forEach(function (n) {
        var f = n.getAttribute('data-ow');
        if (f) n.textContent = value(p, f);
        var hf = n.getAttribute('data-ow-html');
        if (hf === 'body_html' && p.body_html) {
          n.textContent = '';
          var doc = new DOMParser().parseFromString('<div>' + p.body_html + '</div>', 'text/html');
          clean(doc.body.firstChild, n);
        }
        var sf = n.getAttribute('data-ow-src');
        if (sf) {
          var u = p[sf];
          var has = okUrl(u);
          if (has) { n.setAttribute('src', u); n.setAttribute('alt', value(p, n.getAttribute('data-ow-alt') || 'cover_alt')); n.setAttribute('loading', 'lazy'); }
          if (reuse) {                       // Artikel-Ansicht wird für jeden Beitrag wiederverwendet: nur verstecken (samt Rahmen mit data-ow-hide-if-empty)
            n.hidden = !has;
            if (n.parentNode && n.parentNode.hasAttribute && n.parentNode.hasAttribute('data-ow-hide-if-empty')) n.parentNode.hidden = !has;
          } else if (!has && n.parentNode) n.parentNode.removeChild(n);
        }
        if (n.hasAttribute('data-ow-href')) n.setAttribute('href', href);
      });
    }

    function card(p, extra) {
      var frag = cardTpl.content.cloneNode(true);
      var el = frag.firstElementChild;
      if (!el) return null;
      bind(el, p, '#' + p.slug);
      if (extra) el.className += ' ' + extra;
      return el;
    }
    function div(cls) { var d = document.createElement('div'); d.className = cls || ''; return d; }

    function renderList(posts) {
      if (!list || !cardTpl) return;
      list.textContent = '';
      if (!posts.length) {
        if (emptyTpl) list.appendChild(emptyTpl.content.cloneNode(true));
        return;
      }
      if (list.getAttribute('data-ow-layout') !== 'featured') {
        posts.forEach(function (p) { var c = card(p); if (c) list.appendChild(c); });
        return;
      }
      var wrap = div(list.getAttribute('data-ow-wrap-class'));
      var side = div(list.getAttribute('data-ow-side-class'));
      var f = card(posts[0], list.getAttribute('data-ow-featured-class'));
      if (f) wrap.appendChild(f);
      posts.slice(1, 3).forEach(function (p) { var c = card(p); if (c) side.appendChild(c); });
      if (posts.length > 1) wrap.appendChild(side);
      list.appendChild(wrap);
      for (var i = 3; i < posts.length; i += 3) {
        var row = div(list.getAttribute('data-ow-row-class'));
        posts.slice(i, i + 3).forEach(function (p) { var c = card(p); if (c) row.appendChild(c); });
        list.appendChild(row);
      }
    }

    function setView(view) {
      root.setAttribute('data-ow-view', view);
      if (article) article.hidden = view !== 'article';
      if (readingClass) document.body.classList.toggle(readingClass, view === 'article');
      if (view === 'list') document.title = baseTitle;
    }

    function showArticle(slug) {
      getJson(api + '/' + encodeURIComponent(slug)).then(function (d) {
        if (location.hash.slice(1) !== slug || !d.post || !article) return;
        bind(article, d.post, '#', true);
        document.title = d.post.title + suffix;
        setView('article');
        window.scrollTo(0, 0);
      }).catch(function () { setView('list'); });
    }

    function route() {
      var slug = location.hash.slice(1);
      if (slug && SLUG.test(slug) && article) showArticle(slug);
      else setView('list');
    }

    getJson(api + '?per_page=' + perPage).then(function (d) {
      cache = d;
      renderList(d.posts || []);
      root.setAttribute('data-ow-ready', '');
      window.addEventListener('hashchange', route);
      route();
    }).catch(function () {
      if (list && emptyTpl) { list.textContent = ''; list.appendChild(emptyTpl.content.cloneNode(true)); }
      setView('list');
    });
  }

  function start() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-ow-blog]'), init);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
