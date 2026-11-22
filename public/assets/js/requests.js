/*
 * The message pop-up used to approve or reject a request (and, on Issued Books, to send a reminder).
 * <form data-composer data-preview-url="..." data-preview-fields="names of fields sent to the preview">
 *   - the preset text is filled in by the server (fn_render_template) with real names and dates;
 *   - changing the preset, date or time fills it in again; "Write my own" empties the message;
 *   - the send button stays disabled while a required field is empty or the message is too long.
 */
(function () {
  'use strict';
  var LMS = window.LMS;
  if (!LMS) return;

  function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function initComposer(form) {
    var dialog = form.closest('dialog');
    var preset = form.elements.template_id;
    var subject = form.elements.subject;
    var body = form.elements.body;
    var reason = form.elements.reason || null;
    var date = form.elements.pickup_date || null;
    var time = form.elements.pickup_time || null;
    var send = form.querySelector('[data-send]');
    var status = form.querySelector('[data-composer-status]');
    var switchReject = form.querySelector('[data-switch-reject]');
    var fields = (form.getAttribute('data-preview-fields') || '').split(/\s+/).filter(Boolean);
    var sequence = 0;
    var autoReason = '';
    var lastReply = null;

    function setStatus(text) { if (status) status.textContent = text; }

    function presetLabel() {
      var opt = preset.options[preset.selectedIndex];
      return opt && preset.value !== 'own' ? opt.textContent : '';
    }

    /* The library is closed on some days: say so next to the date. */
    function dateProblem() {
      if (!date || !date.value) return '';
      var open = (date.getAttribute('data-open-days') || '1,2,3,4,5').split(',').map(Number);
      var d = new Date(date.value + 'T12:00:00');
      var iso = d.getDay() === 0 ? 7 : d.getDay();
      if (date.min && date.value < date.min) return 'The pickup date cannot be in the past.';
      if (open.indexOf(iso) === -1) {
        return 'The library is closed on ' + d.toLocaleDateString('en-US', { weekday: 'long' }) + 's. Choose an open day.';
      }
      return '';
    }

    function validate() {
      var ok = true;
      $all('[required]', form).forEach(function (f) {
        if (!f.disabled && String(f.value).trim() === '') ok = false;
      });
      if (body.value.length > 1000) ok = false;
      var slot = form.querySelector('[data-error-for="pickup_date"]');
      var problem = dateProblem();
      if (date && slot) {
        if (problem) { slot.textContent = problem; slot.hidden = false; date.setAttribute('aria-invalid', 'true'); ok = false; }
        else if (slot.getAttribute('data-client') === '1' || !slot.textContent) { slot.textContent = ''; slot.hidden = true; date.removeAttribute('aria-invalid'); }
        slot.setAttribute('data-client', problem ? '1' : '0');
      }
      if (send) send.disabled = !ok;
      return ok;
    }

    function setBody(text) {
      body.value = text;
      body.dispatchEvent(new Event('input', { bubbles: true }));  // updates the character count and the button
    }

    function render() {
      if (reason && (reason.value === '' || reason.value === autoReason)) {
        reason.value = presetLabel();
        autoReason = reason.value;
      }
      if (preset.value === 'own') {
        setBody('');
        setStatus('Write your own message. Plain text, up to 1,000 characters.');
        validate();
        return;
      }
      var params = new URLSearchParams();
      fields.forEach(function (name) { var el = form.elements[name]; if (el) params.set(name, el.value); });
      var mine = ++sequence;
      setStatus('Filling in names and dates...');
      LMS.getJSON(form.getAttribute('data-preview-url') + '?' + params.toString()).then(function (res) {
        if (mine !== sequence) return;   // a newer change is already on its way
        if (!res.ok) {
          setStatus((res.error || 'The preset could not be filled in.') + ' You can still write the message yourself.');
          validate();
          return;
        }
        subject.value = res.subject;
        setBody(res.body);
        setStatus('Filled in from the preset with real names and dates. You can edit it freely.');
        validate();
      });
    }

    preset.addEventListener('change', render);
    [date, time].forEach(function (el) {
      if (!el) return;
      el.addEventListener('change', function () { validate(); if (preset.value !== 'own' && !dateProblem()) render(); });
    });
    form.addEventListener('input', validate);

    dialog.addEventListener('lms:open', function () {
      if (switchReject) switchReject.hidden = true;
      autoReason = reason ? reason.value : '';
      lastReply = null;
      if (send) send.disabled = true;
      render();
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!validate()) return;
      LMS.clearFormErrors(form);
      if (switchReject) switchReject.hidden = true;
      send.disabled = true;
      var label = send.innerHTML;
      send.textContent = 'Sending...';
      fetch(form.getAttribute('action'), {
        method: 'POST', body: new FormData(form), credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-CSRF-Token': LMS.csrf() }
      }).then(function (res) {
        return res.json().catch(function () { return { ok: false, error: 'The server sent an unexpected answer. Reload the page and try again.' }; });
      }, function () {
        return { ok: false, error: 'The site could not be reached. Check that the server is running, then try again.' };
      }).then(function (res) {
        if (res.ok) { window.location.reload(); return; }
        send.innerHTML = label;
        lastReply = res;
        LMS.showFormErrors(form, res);
        if (switchReject && res.suggest === 'reject') switchReject.hidden = false;
        validate();
      });
    });

    /* Approval refused because no copy is free: open Reject with the All copies on loan preset. */
    if (switchReject) {
      switchReject.addEventListener('click', function () {
        var rejectDialog = document.getElementById('reject-modal');
        if (!rejectDialog || !lastReply) return;
        var data = {
          request_id: form.elements.request_id.value,
          who: dialog.querySelector('[data-fill-text="who"]').textContent,
          template_id: lastReply.template_id,
          reason: 'All copies on loan'
        };
        dialog.close();
        LMS.openModal(rejectDialog, null, data);
      });
    }
  }

  $all('form[data-composer]').forEach(initComposer);
})();
