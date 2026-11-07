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
})();
