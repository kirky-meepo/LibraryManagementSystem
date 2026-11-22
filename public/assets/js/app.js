/*
 * LibraryInventorySystem: shared front-end behaviour. Plain JavaScript, no libraries.
 *   - mobile menu, toasts, accessible modals (native <dialog>), confirm dialogs
 *   - count-up numbers, card tilt (both off under prefers-reduced-motion)
 *   - live search, character counters, copy buttons, row menus
 * Page-specific code (the request message pop-up) lives in its own file and uses window.LMS.
 */
(function () {
  'use strict';

  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function csrf() { var m = $('meta[name="csrf-token"]'); return m ? m.getAttribute('content') : ''; }

  /* ------------------------------------------------------------------ mobile menu */
  function initNav() {
    var btn = $('[data-nav-toggle]');
    var nav = $('[data-sidebar]');
    if (!btn || !nav) return;
    function setOpen(open) {
      nav.classList.toggle('is-open', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    btn.addEventListener('click', function () { setOpen(!nav.classList.contains('is-open')); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && nav.classList.contains('is-open')) { setOpen(false); btn.focus(); }
    });
    document.addEventListener('click', function (e) {
      if (nav.classList.contains('is-open') && !nav.contains(e.target) && !btn.contains(e.target)) setOpen(false);
    });
  }

  /* ------------------------------------------------------------------ toasts */
  function dismissToast(t) {
    if (!t || t.classList.contains('is-leaving')) return;
    t.classList.add('is-leaving');
    var done = function () { if (t.parentNode) t.parentNode.removeChild(t); };
    if (reduceMotion.matches) { done(); return; }
    t.addEventListener('animationend', done, { once: true });
    setTimeout(done, 450);
  }

  function armToast(t) {
    var close = $('[data-toast-close]', t);
    if (close) close.addEventListener('click', function () { dismissToast(t); });
    if (t.classList.contains('toast-error') || t.classList.contains('toast-warning')) return; // errors stay until closed
    setTimeout(function () { dismissToast(t); }, $('.toast-action', t) ? 12000 : 6000);
  }

  var ICONS = {
    success: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
    error: '<path d="M12 3 2.5 20h19z"/><path d="M12 10v4.5M12 17.5v.01"/>',
    info: '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5M12 8v.01"/>'
  };
  function svg(body) {
    return '<svg class="icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + body + '</svg>';
  }

  /** Show a toast. type: success | error | info | warning. action: {href, label} (optional). */
  function toast(type, message, action) {
    var region = $('[data-toast-region]');
    if (!region) {
      region = document.createElement('div');
      region.className = 'toast-region';
      region.setAttribute('data-toast-region', '');
      region.setAttribute('aria-live', 'polite');
      document.body.appendChild(region);
    }
    var t = document.createElement('div');
    t.className = 'toast toast-' + type;
    t.setAttribute('role', type === 'error' ? 'alert' : 'status');
    var icon = document.createElement('span');
    icon.className = 'toast-icon';
    icon.innerHTML = svg(ICONS[type === 'warning' ? 'error' : type] || ICONS.info);
    var text = document.createElement('p');
    text.className = 'toast-text';
    text.textContent = message;
    t.appendChild(icon);
    t.appendChild(text);
    if (action && action.href) {
      var a = document.createElement('a');
      a.className = 'toast-action';
      a.href = action.href;
      a.textContent = action.label;
      t.appendChild(a);
    }
    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'toast-close';
    close.setAttribute('data-toast-close', '');
    close.innerHTML = svg('<path d="M6 6l12 12M18 6 6 18"/>') + '<span class="sr-only">Close message</span>';
    t.appendChild(close);
    region.appendChild(t);
    armToast(t);
    return t;
  }

  /* ------------------------------------------------------------------ modals */
  var openers = new WeakMap();

  /** Fill a dialog from a plain object: inputs by name, [data-fill-text], [data-fill-show], [data-fill-href]. */
  function fill(dialog, data) {
    Object.keys(data || {}).forEach(function (key) {
      var value = data[key];
      $all('[name="' + key + '"]', dialog).forEach(function (field) {
        if (field.type === 'checkbox') field.checked = !!value;
        else if (field.type === 'radio') field.checked = String(field.value) === String(value);
        else field.value = value === null || value === undefined ? '' : value;
      });
      $all('[data-fill-text="' + key + '"]', dialog).forEach(function (el) { el.textContent = value === null || value === undefined ? '' : value; });
      $all('[data-fill-show="' + key + '"]', dialog).forEach(function (el) { el.hidden = !value; });
      $all('[data-fill-hide="' + key + '"]', dialog).forEach(function (el) { el.hidden = !!value; });
      $all('[data-fill-href="' + key + '"]', dialog).forEach(function (el) { el.setAttribute('href', value || '#'); });
      $all('[data-fill-max="' + key + '"]', dialog).forEach(function (el) { if (value) el.setAttribute('max', value); });
    });
    $all('textarea[maxlength], input[data-count]', dialog).forEach(updateCount);
  }

  function openModal(dialog, trigger, data) {
    if (!dialog || dialog.open) return;
    $all('form[data-ajax]', dialog).forEach(function (f) {
      clearFormErrors(f);
      delete f.dataset.confirmed;
      $all('button', f).forEach(function (b) { b.disabled = false; });
    });
    if (data) fill(dialog, data);
    dialog.classList.remove('is-closing');
    openers.set(dialog, trigger || document.activeElement);
    dialog.showModal();
    // Focus the first visible field (skipping hidden ones), or an [autofocus] field when there is one.
    var visible = function (el) { return !el.disabled && !el.closest('[hidden]') && el.getClientRects().length > 0; };
    var first = $all('[autofocus]', dialog).filter(visible)[0] ||
      $all('input:not([type=hidden]), select, textarea, button:not([data-modal-close])', dialog).filter(visible)[0];
    if (first) first.focus();
    dialog.dispatchEvent(new CustomEvent('lms:open', { detail: data || {} }));
  }

  function closeModal(dialog) {
    if (!dialog || !dialog.open || dialog.classList.contains('is-closing')) return;
    if (reduceMotion.matches) { dialog.close(); return; }
    dialog.classList.add('is-closing');
    var done = function () { dialog.classList.remove('is-closing'); if (dialog.open) dialog.close(); };
    dialog.addEventListener('animationend', done, { once: true });
    setTimeout(done, 300);
  }

  function initModals() {
    $all('dialog.modal').forEach(function (dialog) {
      dialog.addEventListener('cancel', function (e) { e.preventDefault(); closeModal(dialog); });   // Esc
      dialog.addEventListener('click', function (e) { if (e.target === dialog) closeModal(dialog); }); // backdrop
      dialog.addEventListener('close', function () {
        dialog.classList.remove('is-closing');
        var back = openers.get(dialog);
        if (back && typeof back.focus === 'function' && document.contains(back)) back.focus();
      });
    });
    document.addEventListener('click', function (e) {
      var opener = e.target.closest('[data-modal-open]');
      if (opener) {
        e.preventDefault();
        var data = null;
        if (opener.getAttribute('data-fill')) {
          try { data = JSON.parse(opener.getAttribute('data-fill')); } catch (err) { data = null; }
        }
        var menu = opener.closest('details.row-menu');
        if (menu) menu.open = false;
        openModal(document.getElementById(opener.getAttribute('data-modal-open')), opener, data);
        return;
      }
      var closer = e.target.closest('[data-modal-close]');
      if (closer) { e.preventDefault(); closeModal(closer.closest('dialog')); }
    });
  }

  /* Forms with data-confirm="Question?" ask first, in an accessible dialog. */
  function initConfirm() {
    var dialog = null;
    function ensure() {
      if (dialog) return dialog;
      dialog = document.createElement('dialog');
      dialog.className = 'modal';
      dialog.setAttribute('aria-labelledby', 'confirm-title');
      dialog.innerHTML =
        '<div class="modal-form"><div class="modal-head"><h2 id="confirm-title">Please confirm</h2></div>' +
        '<div class="modal-body"><p data-confirm-text></p></div>' +
        '<div class="modal-foot"><button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>' +
        '<button type="button" class="btn btn-primary" data-confirm-yes>Yes</button></div></div>';
      document.body.appendChild(dialog);
      dialog.addEventListener('cancel', function (e) { e.preventDefault(); closeModal(dialog); });
      dialog.addEventListener('close', function () { var b = openers.get(dialog); if (b && b.focus) b.focus(); });
      return dialog;
    }
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!form.hasAttribute('data-confirm') || form.dataset.confirmed === '1') return;
      e.preventDefault();
      var d = ensure();
      $('[data-confirm-text]', d).textContent = form.getAttribute('data-confirm');
      var yes = $('[data-confirm-yes]', d);
      yes.textContent = form.getAttribute('data-confirm-label') || 'Yes, continue';
      yes.className = 'btn ' + (form.hasAttribute('data-confirm-danger') ? 'btn-danger' : 'btn-primary');
      yes.onclick = function () {
        form.dataset.confirmed = '1';
        closeModal(d);
        if (form.requestSubmit) form.requestSubmit(); else form.submit();
      };
      openModal(d, e.submitter || document.activeElement);
    }, true);
  }

  /* ------------------------------------------------------------------ count-up numbers (once, when first seen) */
  function formatNumber(n, decimals, prefix) {
    return prefix + n.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
  }
  function animateCount(el) {
    var target = parseFloat(el.getAttribute('data-count-to')) || 0;
    var decimals = parseInt(el.getAttribute('data-decimals'), 10) || 0;
    var prefix = el.getAttribute('data-prefix') || '';
    var start = null;
    var duration = 900;
    function step(now) {
      if (start === null) start = now;
      var t = Math.min(1, (now - start) / duration);
      var eased = 1 - Math.pow(1 - t, 3);
      el.textContent = formatNumber(t < 1 ? target * eased : target, decimals, prefix);
      if (t < 1) requestAnimationFrame(step);
    }
    el.textContent = formatNumber(0, decimals, prefix);
    requestAnimationFrame(step);
    // If the browser pauses animation frames (hidden tab, power saving), still end on the real number.
    setTimeout(function () { el.textContent = formatNumber(target, decimals, prefix); }, duration + 300);
  }
  function initCountUp() {
    var els = $all('[data-count-to]');
    if (!els.length || reduceMotion.matches || !('IntersectionObserver' in window)) return; // numbers already show their final value
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        io.unobserve(entry.target);
        animateCount(entry.target);
      });
    }, { threshold: 0.4 });
    els.forEach(function (el) { io.observe(el); });
  }

  /* ------------------------------------------------------------------ card tilt toward the cursor */
  function initTilt() {
    if (reduceMotion.matches || window.matchMedia('(hover: none)').matches) return;
    $all('[data-tilt]').forEach(function (card) {
      card.addEventListener('pointermove', function (e) {
        var r = card.getBoundingClientRect();
        var x = (e.clientX - r.left) / r.width - 0.5;
        var y = (e.clientY - r.top) / r.height - 0.5;
        card.style.setProperty('--ry', (x * 6).toFixed(2) + 'deg');
        card.style.setProperty('--rx', (-y * 6).toFixed(2) + 'deg');
      });
      card.addEventListener('pointerleave', function () {
        card.style.setProperty('--rx', '0deg');
        card.style.setProperty('--ry', '0deg');
      });
    });
  }

  /* ------------------------------------------------------------------ live search and auto-submit filters */
  function initLiveSearch() {
    $all('form[data-live-search]').forEach(function (form) {
      var timer = null;
      $all('input[type="search"]', form).forEach(function (input) {
        input.addEventListener('input', function () {
          clearTimeout(timer);
          timer = setTimeout(function () {
            try { sessionStorage.setItem('lms-live-focus', input.name); } catch (e) { /* storage may be off */ }
            if (form.requestSubmit) form.requestSubmit(); else form.submit();
          }, 450);
        });
      });
      var focusName = null;
      try { focusName = sessionStorage.getItem('lms-live-focus'); sessionStorage.removeItem('lms-live-focus'); } catch (e) { focusName = null; }
      if (focusName) {
        var again = form.querySelector('[name="' + focusName + '"]');
        if (again) { again.focus(); var len = again.value.length; try { again.setSelectionRange(len, len); } catch (e) { /* not text */ } }
      }
    });
    document.addEventListener('change', function (e) {
      var el = e.target;
      if (el.matches && el.matches('[data-autosubmit]') && el.form) {
        if (el.form.requestSubmit) el.form.requestSubmit(); else el.form.submit();
      }
    });
  }

  /* ------------------------------------------------------------------ character counters */
  function updateCount(field) {
    var out = document.querySelector('[data-count-for="' + field.id + '"]');
    if (!out) return;
    var max = parseInt(field.getAttribute('maxlength') || field.getAttribute('data-count'), 10) || 0;
    var len = field.value.length;
    out.textContent = len.toLocaleString('en-US') + ' / ' + max.toLocaleString('en-US') + ' characters';
    out.classList.toggle('is-over', max > 0 && len > max);
  }
  function initCounters() {
    $all('textarea[maxlength], input[data-count]').forEach(function (field) {
      updateCount(field);
      field.addEventListener('input', function () { updateCount(field); });
    });
  }

  /* ------------------------------------------------------------------ copy buttons */
  function initCopy() {
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-copy]');
      if (!btn) return;
      var target = document.getElementById(btn.getAttribute('data-copy'));
      if (!target) return;
      var text = target.value !== undefined && target.tagName !== 'CODE' ? target.value : target.textContent;
      var ok = function () { toast('success', 'Copied.'); };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(ok, function () { toast('error', 'Could not copy. Select the text and copy it by hand.'); });
      } else {
        var range = document.createRange();
        range.selectNodeContents(target);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        try { document.execCommand('copy'); ok(); } catch (err) { toast('info', 'The text is selected. Press Ctrl+C to copy it.'); }
      }
    });
  }

  /* ------------------------------------------------------------------ row menus (<details class="row-menu">) */
  /* Tables scroll sideways inside their own box, which would clip a menu; so an open menu is placed
   * against the window instead, below its button (or above it when there is no room below). */
  function placeMenu(d) {
    var list = $('.menu-list', d);
    var button = $('summary', d);
    if (!list || !button) return;
    var r = button.getBoundingClientRect();
    list.style.position = 'fixed';
    list.style.right = Math.max(8, window.innerWidth - r.right) + 'px';
    list.style.left = 'auto';
    list.style.top = (r.bottom + 4) + 'px';
    var h = list.offsetHeight;
    if (r.bottom + 4 + h > window.innerHeight - 8 && r.top - 4 - h > 8) list.style.top = (r.top - 4 - h) + 'px';
  }
  function initMenus() {
    document.addEventListener('toggle', function (e) {
      var d = e.target;
      if (!d.classList || !d.classList.contains('row-menu') || !d.open) return;
      $all('details.row-menu[open]').forEach(function (other) { if (other !== d) other.open = false; });
      placeMenu(d);
    }, true);
    var closeAll = function () { $all('details.row-menu[open]').forEach(function (d) { d.open = false; }); };
    window.addEventListener('resize', closeAll);
    // When the page or a table scrolls, keep each open menu attached to its button (the browser also scrolls
    // a button into view just as it is tapped). Close a menu only when its button has left the screen.
    var queued = false;
    document.addEventListener('scroll', function (e) {
      if (queued || (e.target.closest && e.target.closest('.menu-list'))) return;
      queued = true;
      requestAnimationFrame(function () {
        queued = false;
        $all('details.row-menu[open]').forEach(function (d) {
          var r = $('summary', d).getBoundingClientRect();
          var visible = r.bottom > 0 && r.top < window.innerHeight && r.right > 0 && r.left < window.innerWidth;
          if (visible) placeMenu(d); else d.open = false;
        });
      });
    }, true);
    document.addEventListener('click', function (e) {
      $all('details.row-menu[open]').forEach(function (d) { if (!d.contains(e.target)) d.open = false; });
    });
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      $all('details.row-menu[open]').forEach(function (d) { d.open = false; var s = $('summary', d); if (s) s.focus(); });
    });
  }

  /* ------------------------------------------------------------------ small JSON helper for the pop-ups */
  function post(url, fields) {
    var body = new FormData();
    body.append('_csrf', csrf());
    Object.keys(fields || {}).forEach(function (k) { body.append(k, fields[k] === null || fields[k] === undefined ? '' : fields[k]); });
    return fetch(url, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf(), 'Accept': 'application/json' } })
      .then(function (res) {
        return res.json().catch(function () { return { ok: false, error: 'The server sent an unexpected answer. Reload the page and try again.' }; });
      }, function () {
        return { ok: false, error: 'The site could not be reached. Check that the server is running, then try again.' };
      });
  }
  function getJSON(url) {
    return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (res) { return res.json().catch(function () { return { ok: false, error: 'The server sent an unexpected answer.' }; }); },
            function () { return { ok: false, error: 'The site could not be reached.' }; });
  }

  /* ------------------------------------------------------------------ pop-up forms that save without leaving the page
   * <form data-ajax action="/api/..."> posts with fetch. The endpoint answers {ok:true, redirect?} or
   * {ok:false, error, errors:{field: message}}. Field messages go into [data-error-for="field"], the summary into
   * [data-form-error]. On success the page reloads (or follows redirect) and shows the server's toast.
   */
  function clearFormErrors(form) {
    $all('[aria-invalid="true"]', form).forEach(function (f) { f.removeAttribute('aria-invalid'); });
    $all('[data-error-for]', form).forEach(function (p) { p.textContent = ''; p.hidden = true; });
    var summary = $('[data-form-error]', form);
    if (summary) { summary.hidden = true; var t = $('p', summary); if (t) t.textContent = ''; }
  }
  function showFormErrors(form, res) {
    var first = null;
    Object.keys(res.errors || {}).forEach(function (name) {
      var field = form.querySelector('[name="' + name + '"]');
      var slot = form.querySelector('[data-error-for="' + name + '"]');
      if (field) { field.setAttribute('aria-invalid', 'true'); if (!first) first = field; }
      if (slot) { slot.textContent = res.errors[name]; slot.hidden = false; }
    });
    var summary = $('[data-form-error]', form);
    if (summary && res.error) { var t = $('p', summary); if (t) t.textContent = res.error; summary.hidden = false; }
    else if (!summary && res.error) toast('error', res.error);
    var target = first || (summary && !summary.hidden ? summary : null);
    if (target) {
      if (target === summary) summary.setAttribute('tabindex', '-1');
      target.focus();
    }
  }
  function initAjaxForms() {
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (e.defaultPrevented || !form.matches || !form.matches('form[data-ajax]')) return;
      e.preventDefault();
      clearFormErrors(form);
      var buttons = $all('button[type="submit"], button:not([type])', form);
      buttons.forEach(function (b) { b.disabled = true; });
      fetch(form.getAttribute('action'), {
        method: 'POST', body: new FormData(form), credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf() }
      }).then(function (res) {
        return res.json().catch(function () { return { ok: false, error: 'The server sent an unexpected answer. Reload the page and try again.' }; });
      }, function () {
        return { ok: false, error: 'The site could not be reached. Check that the server is running, then try again.' };
      }).then(function (res) {
        if (res.ok) {
          if (res.secret) { showSecret(res.secret, form, res.redirect); return; }
          if (res.redirect) window.location.href = res.redirect; else window.location.reload();
          return;
        }
        buttons.forEach(function (b) { b.disabled = false; });
        delete form.dataset.confirmed;
        showFormErrors(form, res);
      });
    });
  }

  /* A temporary password is shown once in [data-secret-modal]; closing it clears the text and reloads the page. */
  function showSecret(secret, form, redirect) {
    var d = $('[data-secret-modal]');
    var current = form.closest('dialog');
    if (current && current.open) current.close();
    if (!d) { window.location.reload(); return; }
    $all('[data-secret-title]', d).forEach(function (el) { el.textContent = secret.title || 'Temporary password'; });
    $all('[data-secret-name]', d).forEach(function (el) { el.textContent = secret.name || ''; });
    $('[data-secret-value]', d).textContent = secret.password || '';
    d.addEventListener('close', function () {
      $('[data-secret-value]', d).textContent = '';
      if (redirect) window.location.href = redirect; else window.location.reload();
    }, { once: true });
    openModal(d, null);
  }

  /* Forms whose fields change with a type: [data-type-switch] decides which [data-type-section] is shown.
   * It may be a select, a hidden input or a group of radio buttons (then the checked one counts).
   * Fields in hidden sections are disabled, so they are neither required nor sent. */
  function applyTypeSwitch(scope) {
    $all('[data-type-switch]', scope).forEach(function (sw) {
      if (sw.type === 'radio' && !sw.checked) return;
      var form = sw.form || scope;
      var value = sw.value;
      $all('[data-type-section]', form).forEach(function (section) {
        var on = section.getAttribute('data-type-section').split(' ').indexOf(value) !== -1;
        section.hidden = !on;
        $all('input, select, textarea', section).forEach(function (f) { f.disabled = !on; });
      });
    });
  }
  function initTypeSwitch() {
    applyTypeSwitch(document);
    document.addEventListener('change', function (e) {
      if (e.target.matches && e.target.matches('[data-type-switch]')) applyTypeSwitch(e.target.form || document);
    });
    document.addEventListener('lms:open', function (e) { applyTypeSwitch(e.target); }, true);
  }

  /* Print buttons, and radio groups that show a matching section (Messages: the recipient field for "One person"). */
  function initSmallHooks() {
    document.addEventListener('click', function (e) {
      if (e.target.closest('[data-print]')) window.print();
    });
    var syncAudience = function () {
      var checked = $('input[name="audience"]:checked');
      if (!checked) return;
      $all('[data-audience-section]').forEach(function (section) {
        var on = section.getAttribute('data-audience-section') === checked.value;
        section.hidden = !on;
        $all('input', section).forEach(function (f) { f.disabled = !on; });
      });
    };
    document.addEventListener('change', function (e) { if (e.target.name === 'audience') syncAudience(); });
    syncAudience();
  }

  /* Style guide: buttons that show a sample toast. */
  function initDemoToasts() {
    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-toast-demo]');
      if (b) toast(b.getAttribute('data-toast-demo'), b.getAttribute('data-message') || 'Saved.');
    });
  }

  window.LMS = {
    toast: toast, openModal: openModal, closeModal: closeModal, fill: fill, post: post, getJSON: getJSON, csrf: csrf,
    reduceMotion: reduceMotion, clearFormErrors: clearFormErrors, showFormErrors: showFormErrors, updateCount: updateCount
  };

  function start() {
    initNav();
    $all('[data-toast]').forEach(armToast);
    initModals();
    initConfirm();
    initCountUp();
    initTilt();
    initLiveSearch();
    initCounters();
    initCopy();
    initMenus();
    initAjaxForms();
    initTypeSwitch();
    initSmallHooks();
    initDemoToasts();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
