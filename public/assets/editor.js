/* Beitrags-Editor: kleiner contenteditable-Editor ohne Abhängigkeiten. Der Server säubert das HTML beim Speichern erneut. */
(function () {
  var form = document.getElementById('post-form');
  var rte = document.getElementById('rte');
  if (!form || !rte) return;
  var src = document.getElementById('rte-source');
  var body = document.getElementById('body');
  var sourceMode = false;

  function exec(cmd, val) { rte.focus(); document.execCommand(cmd, false, val || null); }
  function block(tag) { exec('formatBlock', tag); }

  var actions = {
    bold: function () { exec('bold'); },
    italic: function () { exec('italic'); },
    h2: function () { block('h2'); },
    h3: function () { block('h3'); },
    p: function () { block('p'); },
    quote: function () { block('blockquote'); },
    ul: function () { exec('insertUnorderedList'); },
    ol: function () { exec('insertOrderedList'); },
    unlink: function () { exec('unlink'); },
    clean: function () { exec('removeFormat'); block('p'); },
    undo: function () { exec('undo'); },
    redo: function () { exec('redo'); },
    link: function () {
      var url = prompt('https://…, mailto:… oder /seite');
      if (!url) return;
      url = url.trim();
      if (!/^(https:\/\/|\/|mailto:|tel:|#)/i.test(url)) url = 'https://' + url;
      exec('createLink', url);
    },
    source: function () {
      sourceMode = !sourceMode;
      if (sourceMode) { src.value = rte.innerHTML; src.hidden = false; rte.hidden = true; }
      else { rte.innerHTML = src.value; src.hidden = true; rte.hidden = false; }
    }
  };
  document.querySelectorAll('.toolbar [data-cmd]').forEach(function (b) {
    b.addEventListener('mousedown', function (e) { e.preventDefault(); });
    b.addEventListener('click', function () { var a = actions[b.dataset.cmd]; if (a) a(); });
  });
  rte.addEventListener('paste', function (e) { // nur Text: hält fremde Formatierung aus Word/Web draußen
    e.preventDefault();
    var t = (e.clipboardData || window.clipboardData).getData('text/plain');
    document.execCommand('insertText', false, t);
  });
  document.execCommand('defaultParagraphSeparator', false, 'p');

  form.addEventListener('submit', function () { body.value = sourceMode ? src.value : rte.innerHTML; });

  // KI-Hilfen (der Server fragt den Anbieter; gespeichert wird erst mit "Speichern").
  var base = form.getAttribute('data-ai');
  var csrf = form.querySelector('input[name=_csrf]').value;
  var aiMsg = document.getElementById('ai-msg');
  var prev = null;
  function currentBody() { return sourceMode ? src.value : rte.innerHTML; }
  function call(path, fields, ok) {
    var fd = new FormData();
    fd.append('_csrf', csrf);
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch(base + '/' + path, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.text().then(function (t) {
          var j = null;
          try { j = JSON.parse(t); } catch (e) { /* keine JSON-Antwort, z. B. Serverfehler oder Timeout */ }
          if (!r.ok || j === null) throw new Error((j && j.error) || ('HTTP ' + r.status + (r.status === 504 ? ' (Zeitüberschreitung)' : '')));
          return j;
        });
      });
  }
  function busy(el, on) { if (el) el.disabled = on; }
  var run = document.getElementById('ai-run');
  if (run) {
    run.addEventListener('click', function () {
      busy(run, true); aiMsg.textContent = '…';
      call('revise', { title: document.getElementById('title').value, body_html: currentBody(), instruction: document.getElementById('ai-instr').value })
        .then(function (j) {
          prev = currentBody();
          if (sourceMode) src.value = j.body_html; else rte.innerHTML = j.body_html;
          document.getElementById('ai-undo').hidden = false; aiMsg.textContent = '✓';
        })
        .catch(function (e) { aiMsg.textContent = e.message; })
        .then(function () { busy(run, false); });
    });
    document.getElementById('ai-undo').addEventListener('click', function () {
      if (prev === null) return;
      if (sourceMode) src.value = prev; else rte.innerHTML = prev;
      prev = null; this.hidden = true; aiMsg.textContent = '';
    });
    var seo = document.getElementById('ai-seo');
    seo.addEventListener('click', function () {
      busy(seo, true); aiMsg.textContent = '…';
      call('seo', { title: document.getElementById('title').value, body_html: currentBody() })
        .then(function (j) {
          ['excerpt', 'meta_description', 'keywords', 'category', 'cover_alt', 'image_prompt'].forEach(function (k) {
            var el = document.getElementById(k); if (el && j[k]) el.value = j[k];
          });
          aiMsg.textContent = '✓';
        })
        .catch(function (e) { aiMsg.textContent = e.message; })
        .then(function () { busy(seo, false); });
    });
  }
  var img = document.getElementById('img-gen');
  if (img) {
    img.addEventListener('click', function () {
      var msg = document.getElementById('img-msg');
      busy(img, true); msg.textContent = '…';
      call('image', { prompt: document.getElementById('image_prompt').value })
        .then(function (j) { document.getElementById('cover_image').value = j.path; msg.textContent = '✓ ' + j.path; })
        .catch(function (e) { msg.textContent = e.message; })
        .then(function () { busy(img, false); });
    });
  }
})();
