/* OmniWrite-Einbettung: <script src="https://…/assets/embed.js" data-site="kuerzel" data-limit="5" async></script>
   Optional: data-target="#id" (Ziel-Element), data-excerpt="true". Auf einer eigenen Domain entfällt data-site.
   Der Inhalt wird nur mit textContent/Attributen aufgebaut, nie mit innerHTML. */
(function () {
  var s = document.currentScript;
  if (!s || !s.src) return;
  var site = s.getAttribute('data-site') || '';
  var limit = Math.max(1, Math.min(20, parseInt(s.getAttribute('data-limit') || '5', 10) || 5));
  var origin = new URL(s.src, location.href).origin;
  var url = origin + (site ? '/api/v1/sites/' + encodeURIComponent(site) + '/posts' : '/api/v1/posts') + '?per_page=' + limit;
  var box = s.getAttribute('data-target') ? document.querySelector(s.getAttribute('data-target')) : null;
  if (!box) { box = document.createElement('div'); s.parentNode.insertBefore(box, s); }
  box.className = (box.className ? box.className + ' ' : '') + 'ow-embed';
  var withExcerpt = s.getAttribute('data-excerpt') === 'true';
  fetch(url, { credentials: 'omit' })
    .then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.json(); })
    .then(function (d) {
      var lang = (d.site && d.site.locale) || 'en';
      var ul = document.createElement('ul');
      ul.className = 'ow-embed-list';
      (d.posts || []).forEach(function (p) {
        if (!/^https?:\/\//i.test(p.url)) return;
        var li = document.createElement('li');
        var a = document.createElement('a');
        a.href = p.url;
        a.textContent = p.title;
        li.appendChild(a);
        if (p.published_at) {
          var t = document.createElement('time');
          t.setAttribute('datetime', p.published_at);
          t.textContent = ' ' + new Date(p.published_at).toLocaleDateString(lang);
          li.appendChild(t);
        }
        if (withExcerpt && p.excerpt) {
          var e = document.createElement('p');
          e.textContent = p.excerpt;
          li.appendChild(e);
        }
        ul.appendChild(li);
      });
      box.appendChild(ul);
    })
    .catch(function () { /* still: ein defekter Einbettungsblock darf die Seite nicht stören */ });
})();
