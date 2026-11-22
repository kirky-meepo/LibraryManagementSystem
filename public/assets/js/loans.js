/*
 * Mark returned pop-up on Issued Books.
 * Damaged asks for an amount; Lost shows the replacement cost. After saving, the pop-up shows the fines the
 * database created before it closes, then the page reloads.
 */
(function () {
  'use strict';
  var LMS = window.LMS;
  var form = document.querySelector('form[data-return-form]');
  if (!LMS || !form) return;
  var dialog = form.closest('dialog');
  var ask = form.querySelector('[data-return-ask]');
  var done = form.querySelector('[data-return-done]');
  var submit = form.querySelector('[data-return-submit]');
  var cancel = form.querySelector('[data-return-cancel]');
  var closeBtn = form.querySelector('[data-return-close]');
  var saved = false;

  function condition() {
    var checked = form.querySelector('input[name="condition"]:checked');
    return checked ? checked.value : 'good';
  }
  function sync() {
    Array.prototype.forEach.call(form.querySelectorAll('[data-condition]'), function (el) {
      var on = el.getAttribute('data-condition') === condition();
      el.hidden = !on;
      Array.prototype.forEach.call(el.querySelectorAll('input'), function (i) { i.disabled = !on; i.required = on; });
    });
  }
  form.addEventListener('change', sync);

  dialog.addEventListener('lms:open', function () {
    saved = false;
    ask.hidden = false;
    done.hidden = true;
    submit.hidden = false;
    cancel.hidden = false;
    closeBtn.hidden = true;
    submit.disabled = false;
    sync();
  });
  dialog.addEventListener('close', function () { if (saved) window.location.reload(); });
  closeBtn.addEventListener('click', function () { LMS.closeModal(dialog); });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    LMS.clearFormErrors(form);
    submit.disabled = true;
    fetch(form.getAttribute('action'), {
      method: 'POST', body: new FormData(form), credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-CSRF-Token': LMS.csrf() }
    }).then(function (res) {
      return res.json().catch(function () { return { ok: false, error: 'The server sent an unexpected answer. Reload the page and try again.' }; });
    }, function () {
      return { ok: false, error: 'The site could not be reached. Check that the server is running, then try again.' };
    }).then(function (res) {
      if (!res.ok) { submit.disabled = false; LMS.showFormErrors(form, res); return; }
      saved = true;
      form.querySelector('[data-return-message]').textContent = res.message;
      var list = form.querySelector('[data-return-fines]');
      list.innerHTML = '';
      (res.fines || []).forEach(function (line) { var li = document.createElement('li'); li.textContent = line; list.appendChild(li); });
      ask.hidden = true;
      done.hidden = false;
      submit.hidden = true;
      cancel.hidden = true;
      closeBtn.hidden = false;
      closeBtn.focus();
    });
  });
})();
