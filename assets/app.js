// ---------------------------------------------------------------------
// Per-form wiring (BMI live preview, baseline toggle, milestone warning).
// Pulled into a function, keyed off a root element, so it can be re-run
// against content injected into a slide-over — not just the initial page.
// ---------------------------------------------------------------------
function pcdWireFormExtras(root) {
  const h = root.querySelector('#height_cm');
  const w = root.querySelector('#weight_kg');
  const out = root.querySelector('#bmi_preview');

  function calc() {
    if (!h || !w || !out) return;
    const heightCm = parseFloat(h.value);
    const weightKg = parseFloat(w.value);
    if (heightCm > 0 && weightKg > 0) {
      const meters = heightCm / 100;
      out.value = (weightKg / (meters * meters)).toFixed(2);
    } else {
      out.value = '';
    }
  }
  h?.addEventListener('input', calc);
  w?.addEventListener('input', calc);

  // Optional "capture baseline now" section on the Add Beneficiary form.
  const toggle = root.querySelector('#capture_baseline_toggle');
  const fields = root.querySelector('#baseline_fields');
  if (toggle && fields) {
    const sync = (clear) => {
      fields.style.display = toggle.checked ? 'grid' : 'none';
      if (clear && !toggle.checked) {
        fields.querySelectorAll('input:not([type="date"]), select').forEach((el) => {
          el.value = '';
        });
      }
    };
    toggle.addEventListener('change', () => sync(true));
    sync(false);
  }

  // Soft-lock warning when recording Midline/Endline without a prior milestone on file.
  const milestoneSelect = root.querySelector('#milestone_select');
  const milestoneWarning = root.querySelector('#milestone_warning');
  if (milestoneSelect && milestoneWarning) {
    const sync2 = () => {
      const selected = milestoneSelect.selectedOptions[0];
      const gapLabel = selected ? selected.getAttribute('data-gap') : '';
      if (gapLabel) {
        milestoneWarning.textContent = 'Heads up: no ' + gapLabel + ' is on file for this child yet. You can still save this record, but comparisons against ' + gapLabel + ' won\u2019t be possible until it\u2019s added.';
        milestoneWarning.style.display = 'block';
      } else {
        milestoneWarning.style.display = 'none';
      }
    };
    milestoneSelect.addEventListener('change', sync2);
    sync2();
  }
}

// ---------------------------------------------------------------------
// Slide-over panel: opens a page's content (fetched with ?panel=1, which
// tells header.php/footer.php to skip the sidebar/head chrome) inside an
// overlay instead of navigating to it. Forms inside submit over fetch;
// the endpoint replies with JSON (ok/redirect/message) when it detects
// the request came from here (see is_ajax_request() in config.php).
// ---------------------------------------------------------------------
let pcdSlideoverEl = null;
let pcdLastTrigger = null;

function pcdEnsureSlideover() {
  if (pcdSlideoverEl) return pcdSlideoverEl;
  const backdrop = document.createElement('div');
  backdrop.className = 'slideover-backdrop';
  backdrop.innerHTML =
    '<div class="slideover-panel" role="dialog" aria-modal="true" aria-label="Form panel">' +
      '<div class="slideover-head">' +
        '<h2 class="slideover-title"></h2>' +
        '<button type="button" class="slideover-close" aria-label="Close">&times;</button>' +
      '</div>' +
      '<div class="slideover-body"><p class="slideover-loading">Loading&hellip;</p></div>' +
    '</div>';
  document.body.appendChild(backdrop);
  backdrop.addEventListener('click', (e) => {
    if (e.target === backdrop) pcdCloseSlideover();
  });
  backdrop.querySelector('.slideover-close').addEventListener('click', pcdCloseSlideover);
  document.addEventListener('keydown', (e) => {
    if (!backdrop.classList.contains('open')) return;
    if (e.key === 'Escape') { pcdCloseSlideover(); return; }
    if (e.key === 'Tab') {
      // Keep keyboard focus inside the open panel.
      const items = Array.from(backdrop.querySelectorAll('a[href], button:not([disabled]), input:not([type="hidden"]):not([disabled]), select, textarea'))
        .filter((el) => el.offsetParent !== null);
      if (!items.length) return;
      const first = items[0];
      const last = items[items.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });
  pcdSlideoverEl = backdrop;
  return backdrop;
}

function pcdCloseSlideover() {
  if (pcdSlideoverEl) pcdSlideoverEl.classList.remove('open');
  if (pcdLastTrigger && document.contains(pcdLastTrigger)) pcdLastTrigger.focus();
}

async function pcdOpenSlideover(url, title) {
  const el = pcdEnsureSlideover();
  if (!el.classList.contains('open')) pcdLastTrigger = document.activeElement;
  el.querySelector('.slideover-title').textContent = title || '';
  const body = el.querySelector('.slideover-body');
  body.innerHTML = '<p class="slideover-loading">Loading&hellip;</p>';
  el.classList.add('open');
  try {
    const res = await fetch(url, { headers: { 'X-Requested-With': 'fetch' } });
    const html = await res.text();
    body.innerHTML = html;
    body.querySelectorAll('form').forEach((f) => {
      if (!f.getAttribute('action')) f.setAttribute('action', url);
    });
    pcdWireFormExtras(body);
    pcdWireSlideoverForms(body);
    pcdLinkLabels(body);
    pcdWireInlineValidation(body);
    const firstField = body.querySelector('input:not([type="hidden"]):not([readonly]), select, textarea');
    if (firstField) firstField.focus();
  } catch (err) {
    body.innerHTML = '<div class="alert danger">Could not load this form. Please check your connection and try again.</div>';
  }
}

function pcdWireSlideoverForms(root) {
  root.querySelectorAll('form').forEach((form) => {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(form, e.submitter || undefined);
      const buttons = form.querySelectorAll('button');
      buttons.forEach((b) => { b.disabled = true; });
      try {
        const res = await fetch(form.getAttribute('action'), {
          method: 'POST',
          body: fd,
          headers: { 'X-Requested-With': 'fetch' },
        });
        const data = await res.json();
        if (data.ok && data.reopen) {
          // "Save & Add Another": show a fresh form in the same panel.
          const title = pcdSlideoverEl.querySelector('.slideover-title').textContent;
          pcdOpenSlideover(data.reopen, title);
        } else if (data.ok) {
          pcdCloseSlideover();
          window.location = data.redirect || window.location.href;
        } else {
          pcdShowSlideoverError(root, form, data.message, !!data.duplicate);
        }
      } catch (err) {
        pcdShowSlideoverError(root, form, 'Something went wrong. Please try again.', false);
      } finally {
        buttons.forEach((b) => { b.disabled = false; });
      }
    });
  });
}

function pcdShowSlideoverError(root, form, message, duplicate) {
  let bar = root.querySelector('.slideover-error');
  if (!bar) {
    bar = document.createElement('div');
    bar.className = 'alert danger slideover-error';
    root.prepend(bar);
  }
  bar.innerHTML = '';
  const msg = document.createElement('span');
  msg.textContent = message || 'Please check the form and try again.';
  bar.appendChild(msg);
  if (duplicate) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn secondary sm';
    btn.style.marginLeft = '10px';
    btn.textContent = 'Save Anyway';
    btn.addEventListener('click', () => {
      let hidden = form.querySelector('input[name="confirm_duplicate"]');
      if (!hidden) {
        hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'confirm_duplicate';
        form.appendChild(hidden);
      }
      hidden.value = '1';
      form.requestSubmit();
    });
    bar.appendChild(btn);
  }
  bar.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function pcdWireSlideoverTriggers() {
  document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-slideover]');
    if (!trigger) return;
    e.preventDefault();
    pcdOpenSlideover(trigger.getAttribute('data-slideover'), trigger.getAttribute('data-slideover-title') || '');
  });
}

// ---------------------------------------------------------------------
// Attendance rows: each row is its own small form (Present/Absent quick
// buttons + a Remarks field + Save). Wired here so a click saves that one
// row over fetch and updates its badge in place, instead of reloading the
// whole Attendance page for every row.
// ---------------------------------------------------------------------
function pcdWireAttendanceRows(root) {
  root.querySelectorAll('form.attendance-row-form').forEach((form) => {
    if (form.dataset.pcdWired) return;
    form.dataset.pcdWired = '1';

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(form, e.submitter || undefined);
      fd.append('ajax', '1');
      const tr = form.closest('tr');
      const buttons = form.querySelectorAll('button');
      buttons.forEach((b) => { b.disabled = true; });
      try {
        const res = await fetch('attendance.php', {
          method: 'POST',
          body: fd,
          headers: { 'X-Requested-With': 'fetch' },
        });
        const data = await res.json();
        if (data.ok && tr) {
          const badge = tr.querySelector('.badge');
          if (badge) {
            badge.textContent = data.status_label;
            badge.className = 'badge ' + data.badge_class;
          }
          tr.querySelectorAll('.status-btn').forEach((btn) => btn.classList.remove('active'));
          const activeBtn = tr.querySelector('.status-btn.' + data.status.toLowerCase());
          if (activeBtn) activeBtn.classList.add('active');
          const hiddenStatus = form.querySelector('input[name="attendance_status"]');
          if (hiddenStatus) hiddenStatus.value = data.status;
          pcdToast('Saved: ' + data.status_label);
        } else if (!data.ok) {
          pcdToast(data.message || 'Could not save attendance. Please try again.', true);
        }
      } catch (err) {
        pcdToast('Could not save attendance. Please check your connection and try again.', true);
      } finally {
        buttons.forEach((b) => { b.disabled = false; });
      }
    });
  });
}

// ---------------------------------------------------------------------
// Small non-blocking message (replaces browser alert() popups).
// ---------------------------------------------------------------------
function pcdToast(message, isError) {
  let stack = document.querySelector('.toast-stack');
  if (!stack) {
    stack = document.createElement('div');
    stack.className = 'toast-stack';
    stack.setAttribute('role', 'status');
    stack.setAttribute('aria-live', 'polite');
    document.body.appendChild(stack);
  }
  const t = document.createElement('div');
  t.className = 'toast' + (isError ? ' error' : '');
  t.textContent = message;
  stack.appendChild(t);
  setTimeout(() => t.remove(), isError ? 5000 : 1800);
}

// ---------------------------------------------------------------------
// Connect each <label> to the field that follows it, so clicking a label
// focuses the input and screen readers announce the field name.
// ---------------------------------------------------------------------
let pcdIdCounter = 0;
function pcdLinkLabels(root) {
  root.querySelectorAll('label:not([for])').forEach((label) => {
    if (label.querySelector('input, select, textarea')) return; // checkbox wrapped in its label
    const sib = label.nextElementSibling;
    if (!sib) return;
    const field = sib.matches('input, select, textarea') ? sib : sib.querySelector('input, select, textarea');
    if (!field || field.type === 'hidden') return;
    if (!field.id) field.id = 'pcd-field-' + (++pcdIdCounter);
    label.setAttribute('for', field.id);
  });
}

// ---------------------------------------------------------------------
// Inline validation: flag a bad value next to its field as soon as the user
// leaves it, instead of waiting for a failed submit.
// ---------------------------------------------------------------------
function pcdWireInlineValidation(root) {
  root.querySelectorAll('input, select, textarea').forEach((el) => {
    if (el.dataset.pcdValidated || el.type === 'hidden' || el.readOnly) return;
    el.dataset.pcdValidated = '1';
    const check = () => {
      const next = el.nextElementSibling;
      if (next && next.classList.contains('field-error')) next.remove();
      el.classList.remove('is-invalid');
      if (el.value === '' && !el.required) return;
      if (!el.checkValidity()) {
        el.classList.add('is-invalid');
        const msg = document.createElement('span');
        msg.className = 'field-error';
        msg.setAttribute('role', 'alert');
        msg.textContent = el.validationMessage;
        el.insertAdjacentElement('afterend', msg);
      }
    };
    el.addEventListener('blur', check);
    el.addEventListener('change', check);
  });
}

// ---------------------------------------------------------------------
// Filter forms marked data-autosubmit apply as soon as a dropdown or date
// changes (same behaviour on every list page). Text search still uses Enter
// or the button.
// ---------------------------------------------------------------------
function pcdWireAutosubmit(root) {
  root.querySelectorAll('form[data-autosubmit]').forEach((form) => {
    form.addEventListener('change', (e) => {
      if (e.target.matches('select, input[type="date"]')) form.requestSubmit();
    });
  });
}

// Forms that need a confirmation before a bulk change.
function pcdWireConfirmForms(root) {
  root.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (e) => {
      if (!window.confirm(form.getAttribute('data-confirm'))) e.preventDefault();
    });
  });
}

document.addEventListener('DOMContentLoaded', () => {
  pcdLinkLabels(document);
  pcdWireInlineValidation(document);
  pcdWireAutosubmit(document);
  pcdWireConfirmForms(document);
  pcdWireFormExtras(document);
  pcdWireAttendanceRows(document);
  pcdWireSlideoverTriggers();
});
