/* Scout Call Buttons: opens and closes the "Let's talk" sheet. Taps on the
   call, text and book links are reported by Scout Analytics when it is on
   (data-scout-track). */
(function () {
  var root = document.querySelector('[data-scc]');
  if (!root) return;
  var sheet = root.querySelector('dialog');
  var opener = root.querySelector('[data-scc-open]');
  if (!sheet || !opener) return;
  if (typeof sheet.showModal !== 'function') {
    // Very old browsers: send them to the first option instead.
    opener.addEventListener('click', function () { var a = sheet.querySelector('a'); if (a) window.location.href = a.href; });
    return;
  }
  opener.addEventListener('click', function () {
    sheet.showModal();
    if (window.scoutTrack) window.scoutTrack('contact_open', { link_label: 'Lets talk' });
  });
  sheet.addEventListener('click', function (e) {
    if (e.target.closest('[data-scc-close]')) { sheet.close(); return; }
    if (e.target !== sheet) return;
    // A click on the dialog itself is either its padding or the backdrop; close only for the backdrop.
    var r = sheet.getBoundingClientRect();
    if (e.clientY < r.top || e.clientY > r.bottom || e.clientX < r.left || e.clientX > r.right) sheet.close();
  });
  sheet.addEventListener('close', function () { opener.focus(); });
})();
