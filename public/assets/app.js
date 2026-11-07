/* Bestätigungsdialog für Schaltflächen mit data-confirm (inline-Handler verbietet die CSP). */
document.addEventListener('click', function (e) {
  var b = e.target.closest && e.target.closest('[data-confirm]');
  if (b && !window.confirm(b.getAttribute('data-confirm'))) e.preventDefault();
});
