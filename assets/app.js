// ---------------------------------------------------------------------
// Per-form wiring (BMI live preview, baseline toggle, milestone warning).
// Pulled into a function, keyed off a root element, so it can be re-run
// against content injected into a slide-over — not just the initial page.
// ---------------------------------------------------------------------
function pcdWireFormExtras(root) {
  // Height (cm or ft/in) and weight (kg or lb) with a unit selector. This
  // only drives the unit toggle and the live BMI preview; the server does
  // the real conversion to cm / kg from the unit that is selected on submit
  // (parse_height_cm / parse_weight_kg in config.php).
  const out = root.querySelector('#bmi_preview');
  const statusPreview = root.querySelector('#status_preview');
  const eligibilityWarning = root.querySelector('#eligibility_warning');
  const CM_PER_IN = 2.54;
  const KG_PER_LB = 0.45359237;
  const hBox = root.querySelector('[data-measure="height"]');
  const wBox = root.querySelector('[data-measure="weight"]');

  if (hBox && wBox && !hBox.dataset.pcdWired) {
    hBox.dataset.pcdWired = '1';
    const hUnit = hBox.querySelector('[data-m="height-unit"]');
    const cmIn = hBox.querySelector('[data-m="cm"]');
    const ftinWrap = hBox.querySelector('[data-m="ftin"]');
    const ftIn = hBox.querySelector('[data-m="ft"]');
    const inIn = hBox.querySelector('[data-m="in"]');
    const wUnit = wBox.querySelector('[data-m="weight-unit"]');
    const wIn = wBox.querySelector('[data-m="weight"]');
    const required = cmIn.required;
    const round1 = (n) => Math.round(n * 10) / 10;
    const round2 = (n) => Math.round(n * 100) / 100;
    const form = root.querySelector('#baseline_fields')?.closest('form');
    const submitButtons = form ? Array.from(form.querySelectorAll('button[type="submit"], button:not([type])')) : [];

    function updateEligibility(bmi, status) {
      if (!eligibilityWarning) return;
      const value = round2(bmi);
      const nearCutoff = (value >= 18.5 && value <= 18.6) || (value >= 22.9 && value < 23);
      const ineligible = status === 'Normal' && !nearCutoff;
      eligibilityWarning.hidden = !ineligible;
      submitButtons.forEach((button) => { button.disabled = ineligible; });
    }

    // Canonical values (cm / kg) of what the user last typed. Switching units
    // re-displays these instead of converting the displayed, rounded number
    // back and forth, so toggling never drifts the value.
    let canonCm = null;
    let canonKg = null;

    const readCm = () => {
      if (hUnit.value === 'cm') {
        const v = parseFloat(cmIn.value);
        return v > 0 ? v : null;
      }
      const ft = parseFloat(ftIn.value);
      const inch = parseFloat(inIn.value);
      if (isNaN(ft) && isNaN(inch)) return null;
      const total = (isNaN(ft) ? 0 : ft) * 12 + (isNaN(inch) ? 0 : inch);
      return total > 0 ? total * CM_PER_IN : null;
    };
    const readKg = () => {
      const v = parseFloat(wIn.value);
      if (!(v > 0)) return null;
      return wUnit.value === 'lb' ? v * KG_PER_LB : v;
    };

    function showBmi() {
      if (!out) return;
      const cm = readCm();
      const kg = readKg();
      if (cm && kg) {
        const m = Math.round(cm * 100) / 10000;       // same 2-decimal cm the server stores
        const k = Math.round(kg * 100) / 100;         // same 2-decimal kg the server stores
        const bmi = k / (m * m);
        out.value = bmi.toFixed(2);
        // Asian BMI cut-offs - keep in sync with bmi_status.php
        const st = statusPreview;
        if (st) {
          const r = Math.round(bmi * 100) / 100;
          st.value = r < 14 ? 'Severely Wasted' : r < 16 ? 'Severely Underweight' : r < 18.5 ? 'Moderately Underweight' : r < 23 ? 'Normal' : r < 25 ? 'Overweight' : 'Obese';
          updateEligibility(r, st.value);
        }
      } else {
        out.value = '';
        const st = statusPreview;
        if (st) st.value = '';
        updateEligibility(0, '');
      }
    }

    function applyHeightUnit() {
      const ftin = hUnit.value === 'ftin';
      cmIn.style.display = ftin ? 'none' : '';
      ftinWrap.style.display = ftin ? 'flex' : 'none';
      cmIn.required = required && !ftin;
      ftIn.required = required && ftin;
      if (ftin) {
        if (canonCm) {
          const totalIn = round1(canonCm / CM_PER_IN);
          let ft = Math.floor(totalIn / 12);
          let inch = round1(totalIn - ft * 12);
          if (inch >= 12) { ft += 1; inch = 0; }
          ftIn.value = ft;
          inIn.value = inch;
        }
      } else if (canonCm) {
        cmIn.value = round2(canonCm);
      }
      showBmi();
    }

    function applyWeightUnit() {
      if (canonKg) wIn.value = wUnit.value === 'lb' ? round1(canonKg / KG_PER_LB) : round2(canonKg);
      // Range hints for the browser's own validation, in the chosen unit.
      wIn.min = wUnit.value === 'lb' ? '6.6' : '3';
      wIn.max = wUnit.value === 'lb' ? '440.9' : '200';
      showBmi();
    }

    [cmIn, ftIn, inIn].forEach((el) => el.addEventListener('input', () => { canonCm = readCm(); showBmi(); }));
    wIn.addEventListener('input', () => { canonKg = readKg(); showBmi(); });
    hUnit.addEventListener('change', applyHeightUnit);
    wUnit.addEventListener('change', applyWeightUnit);
    // A form opened with saved values (edit measurement) starts in cm / kg;
    // remember those exact numbers so switching units never re-rounds them.
    canonCm = readCm();
    canonKg = readKg();
    applyHeightUnit();
    applyWeightUnit();
  }

  // Baseline measurement on the Add Beneficiary form is always required:
  // date, height and weight. Friendly messages for the required fields.
  const fields = root.querySelector('#baseline_fields');
  if (fields) {
    const messages = {
      baseline_date: 'Enter the date the baseline was measured.',
      baseline_height_cm: 'Baseline height is required.',
      baseline_height_ft: 'Baseline height is required (feet).',
      baseline_weight_value: 'Baseline weight is required.',
    };
    fields.querySelectorAll('input[required]').forEach((el) => {
      const msg = messages[el.name];
      if (!msg) return;
      el.addEventListener('invalid', () => { if (el.validity.valueMissing) el.setCustomValidity(msg); });
      el.addEventListener('input', () => el.setCustomValidity(''));
    });
    const baselineForm = fields.closest('form');
    if (baselineForm && eligibilityWarning) {
      baselineForm.addEventListener('submit', (event) => {
        if (!eligibilityWarning.hidden) {
          event.preventDefault();
          eligibilityWarning.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      });
    }
  }

  // Age is calculated from the birthdate (no input), like BMI. Mirrors
  // age_label() in config.php.
  const birthInput = root.querySelector('#birth_date_input');
  const agePreview = root.querySelector('#age_preview');
  if (birthInput && agePreview) {
    const showAge = () => {
      const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(birthInput.value);
      if (!m) { agePreview.value = ''; return; }
      const by = +m[1], bm = +m[2], bd = +m[3];
      const now = new Date();
      let months = (now.getFullYear() - by) * 12 + (now.getMonth() + 1 - bm);
      if (now.getDate() < bd) months -= 1;
      if (months < 0) { agePreview.value = ''; return; }
      const years = Math.floor(months / 12);
      agePreview.value = years >= 1
        ? years + (years === 1 ? ' year old' : ' years old')
        : months + (months === 1 ? ' month old' : ' months old');
    };
    birthInput.addEventListener('input', showAge);
    birthInput.addEventListener('change', showAge);
    showAge();
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
// Remarks boxes (textarea[data-autogrow]) grow with what is typed so the
// whole entry is always visible, with no inner scrollbar. Re-run after a
// slide-over loads, and whenever a box becomes visible (a hidden box has no
// measurable height).
// ---------------------------------------------------------------------
function pcdWireAutogrow(root) {
  root.querySelectorAll('textarea[data-autogrow]').forEach((ta) => {
    const fit = () => {
      if (ta.offsetParent === null) return;   // hidden: measure later
      ta.style.height = 'auto';
      ta.style.height = ta.scrollHeight + 2 + 'px';
    };
    if (!ta.dataset.pcdGrow) {
      ta.dataset.pcdGrow = '1';
      ta.addEventListener('input', fit);
      ta.addEventListener('focus', fit);
      window.addEventListener('resize', fit);
    }
    fit();
  });
}

// ---------------------------------------------------------------------
// Auto-capitalize first letters as the user types, in every form that
// saves data (method="post"):
//   - single-line text fields (names, places, titles): first letter of each
//     word  ->  "juan dela cruz" becomes "Juan Dela Cruz"
//   - remarks / description / notes and any textarea: first letter of each
//     sentence  ->  "ate all. went home" becomes "Ate all. Went home"
// Only first letters change; the rest of what was typed is left alone.
// Opt a field out with data-no-capitalize (e.g. usernames). Search/filter
// forms use GET, so they're untouched. Keep the regexes in sync with
// capitalize_words() / capitalize_sentences() in config.php, which does
// the same on the server.
// ---------------------------------------------------------------------
function pcdCapitalizeWords(text) {
  return text.replace(/(^|[\s\-\/(])(\p{Ll})/gu, (m, lead, ch) => lead + ch.toLocaleUpperCase());
}

function pcdCapitalizeSentences(text) {
  return text.replace(/(^\s*|[.!?]\s+|[\r\n]+\s*)(\p{Ll})/gu, (m, lead, ch) => lead + ch.toLocaleUpperCase());
}

function pcdWireAutoCapitalize(root) {
  const selector = [
    'form[method="post" i] input:not([type])',
    'form[method="post" i] input[type="text"]',
    'form[method="post" i] textarea',
  ].join(', ');

  root.querySelectorAll(selector).forEach((el) => {
    if (el.dataset.pcdCapWired || el.readOnly || el.disabled || el.hasAttribute('data-no-capitalize')) return;
    el.dataset.pcdCapWired = '1';

    const sentenceMode = el.tagName === 'TEXTAREA' || /remark|description|note/i.test(el.name || '');
    const fix = sentenceMode ? pcdCapitalizeSentences : pcdCapitalizeWords;

    el.addEventListener('input', (e) => {
      if (e.isComposing) return; // don't interfere with IME composition
      const before = el.value;
      const after = fix(before);
      if (after === before) return;
      // Replacing a letter with its capital normally keeps the length; restore
      // the caret anyway so typing in the middle of text doesn't jump to the end.
      const start = el.selectionStart;
      const end = el.selectionEnd;
      el.value = after;
      if (start !== null && after.length === before.length) el.setSelectionRange(start, end);
    });
  });
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
    pcdWireAutoCapitalize(body);
    pcdWireAutogrow(body);
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
    // Cancel inside a slide-over just closes it. Its href points at the page
    // that is already open behind the panel, so following it does nothing.
    const cancel = e.target.closest('.slideover-panel .cancel-button');
    if (cancel) {
      e.preventDefault();
      pcdCloseSlideover();
      return;
    }
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

function pcdWireAttendanceDrafts(root) {
  root.addEventListener('click', (e) => {
    const button = e.target.closest('[data-attendance-status]');
    if (!button) return;

    const row = button.closest('tr');
    const statusInput = row?.querySelector('input[name^="attendance["]');
    if (!statusInput) return;

    const previousStatus = statusInput.value;
    const previousCountStatus = previousStatus || statusInput.dataset.initialStatus || '';
    const selectedStatus = button.dataset.attendanceStatus;
    if (previousStatus !== selectedStatus) {
      [previousCountStatus, selectedStatus].forEach((status, index) => {
        if (!['PRESENT', 'ABSENT'].includes(status)) return;
        const count = root.querySelector(`[data-attendance-count="${status}"]`);
        if (count) count.textContent = String(Math.max(0, Number(count.textContent) + (index === 0 ? -1 : 1)));
      });
    }
    statusInput.value = selectedStatus;
    row.classList.toggle('attendance-pending-save', statusInput.value !== (statusInput.dataset.initialStatus || ''));
    row.querySelectorAll('[data-attendance-status]').forEach((item) => {
      const active = item === button;
      item.classList.toggle('active', active);
      item.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
  });

  const saveForm = root.querySelector('#attendance-save-form');
  if (saveForm) {
    saveForm.addEventListener('submit', (e) => {
      const missingInput = Array.from(root.querySelectorAll('input[name^="attendance["]'))
        .find((input) => !['PRESENT', 'ABSENT'].includes(input.value));
      if (missingInput) {
        e.preventDefault();
        const row = missingInput.closest('tr');
        let notice = document.querySelector('.attendance-save-alert');
        if (!notice) {
          notice = document.createElement('div');
          notice.className = 'alert danger attendance-alert attendance-save-alert';
          notice.setAttribute('role', 'alert');
          const pageTitle = document.querySelector('.page-title');
          pageTitle?.parentNode.insertBefore(notice, pageTitle);
        }
        notice.textContent = 'Choose Present or Absent for every beneficiary before saving.';
        row?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        row?.querySelector('[data-attendance-status]')?.focus();
        return;
      }
      root.querySelectorAll('.attendance-pending-save').forEach((row) => row.classList.remove('attendance-pending-save'));
    });
    window.addEventListener('beforeunload', (e) => {
      if (!root.querySelector('.attendance-pending-save')) return;
      e.preventDefault();
      e.returnValue = '';
    });
  }
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

function pcdWireRequiredFilters(root) {
  root.querySelectorAll('form[data-required-filters]').forEach((form) => {
    const fields = Array.from(form.querySelectorAll('[data-filter-required]'));
    const button = form.querySelector('button[type="submit"]');
    const notice = form.querySelector('.filter-requirement-notice');
    const showRequiredLabels = form.hasAttribute('data-required-labels');
    if (!fields.length || !button) return;

    const update = () => {
      fields.forEach((field) => {
        const missing = field.value.trim() === '';
        const label = form.querySelector(`label[for="${field.id}"]`);
        if (showRequiredLabels && label) {
          label.classList.toggle('is-required-missing', missing);
          field.setAttribute('aria-invalid', missing ? 'true' : 'false');
        }
        if (showRequiredLabels) {
          field.setCustomValidity('');
          return;
        }
        if (field.name === 'site_id') {
          field.setCustomValidity('');
          return;
        }
        const fieldLabel = label?.textContent.trim().toLowerCase() || 'filter';
        field.setCustomValidity(missing ? `Choose a ${fieldLabel} before applying filters.` : '');
      });
      const complete = fields.every((field) => field.value.trim() !== '' && field.checkValidity());
      button.disabled = !complete;
      if (notice) notice.hidden = complete;
    };

    fields.forEach((field) => {
      field.addEventListener('input', update);
      field.addEventListener('change', update);
    });
    form.addEventListener('submit', (event) => {
      update();
      if (button.disabled) {
        event.preventDefault();
        if (showRequiredLabels) {
          form.querySelector('[aria-invalid="true"]')?.focus();
        } else {
          notice?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
      }
    });
    update();
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

// ---------------------------------------------------------------------
// Program Setup: Duration (days) is calculated from Start/End Date
// (inclusive of both days) but stays editable. Once the user types their
// own value it is left alone; clearing the field hands control back to the
// automatic calculation. Also blocks an End Date before the Start Date.
// ---------------------------------------------------------------------
function pcdWireProgramDates(root) {
  const form = root.querySelector('#program-form');
  if (!form || form.dataset.pcdDatesWired) return;
  form.dataset.pcdDatesWired = '1';
  const start = form.querySelector('#program_start_date');
  const end = form.querySelector('#program_end_date');
  const dur = form.querySelector('#program_duration_days');
  const hint = form.querySelector('#program_duration_hint');
  if (!start || !end || !dur) return;
  const defaultHint = hint ? hint.textContent : '';
  let manual = false;

  const setHint = (msg) => { if (hint) hint.textContent = msg || defaultHint; };
  const dayCount = () => {
    if (!start.value || !end.value) return null;
    const a = Date.parse(start.value + 'T00:00:00Z');
    const b = Date.parse(end.value + 'T00:00:00Z');
    if (isNaN(a) || isNaN(b)) return null;
    return Math.round((b - a) / 86400000) + 1;
  };

  function validateDates() {
    const bad = start.value && end.value && end.value < start.value;
    end.setCustomValidity(bad ? 'End Date cannot be earlier than Start Date.' : '');
    end.classList.toggle('is-invalid', !!bad);
    let err = end.nextElementSibling;
    if (err && !err.classList.contains('field-error')) err = null;
    if (bad && !err) {
      err = document.createElement('span');
      err.className = 'field-error';
      err.setAttribute('role', 'alert');
      end.insertAdjacentElement('afterend', err);
    }
    if (err) { if (bad) err.textContent = end.validationMessage; else err.remove(); }
    return !bad;
  }

  function recalc() {
    const ok = validateDates();
    if (manual) return;
    const n = ok ? dayCount() : null;
    dur.value = n !== null && n >= 1 ? String(n) : '';
  }

  start.addEventListener('input', recalc);
  start.addEventListener('change', recalc);
  end.addEventListener('input', recalc);
  end.addEventListener('change', recalc);

  dur.addEventListener('input', () => {
    manual = dur.value.trim() !== '';
    const v = Number(dur.value);
    dur.setCustomValidity(manual && (!Number.isInteger(v) || v < 1 || v > 3650)
      ? 'Duration must be a whole number of days between 1 and 3650.' : '');
    const n = dayCount();
    setHint(manual && n !== null && n !== v ? 'Edited manually (the dates span ' + n + ' days).' : '');
    if (!manual) recalc();
  });

  form.addEventListener('submit', (e) => {
    recalc();
    const invalid = [end, dur].find((el) => !el.checkValidity());
    if (invalid) {
      e.preventDefault();
      invalid.reportValidity();
    }
  });
}

// Long milestone remarks are clipped to two lines; click one to show the full text
// (click again to collapse). Only remarks that are really clipped become clickable.
function pcdWireRemarkToggles(root) {
  const items = root.querySelectorAll('[data-remark-toggle]');
  const measure = () => {
    items.forEach((el) => {
      if (el.classList.contains('is-open')) return;
      const clipped = el.scrollHeight > el.clientHeight + 1;
      el.classList.toggle('is-truncatable', clipped);
      if (clipped) {
        el.setAttribute('role', 'button');
        el.setAttribute('tabindex', '0');
        el.setAttribute('aria-expanded', 'false');
        el.title = 'Click to show the full remarks';
      } else {
        el.removeAttribute('role');
        el.removeAttribute('tabindex');
        el.removeAttribute('aria-expanded');
        el.removeAttribute('title');
      }
    });
  };
  const toggle = (el) => {
    if (!el.classList.contains('is-truncatable')) return;
    const open = el.classList.toggle('is-open');
    el.setAttribute('aria-expanded', open ? 'true' : 'false');
    el.title = open ? 'Click to collapse' : 'Click to show the full remarks';
  };
  items.forEach((el) => {
    if (el.dataset.pcdWired) return;
    el.dataset.pcdWired = '1';
    el.addEventListener('click', () => toggle(el));
    el.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(el); }
    });
  });
  measure();
  window.addEventListener('load', measure);
  window.addEventListener('resize', measure);
}

document.addEventListener('DOMContentLoaded', () => {
  pcdWireRemarkToggles(document);
  pcdWireProgramDates(document);
  pcdLinkLabels(document);
  pcdWireInlineValidation(document);
  pcdWireAutoCapitalize(document);
  pcdWireAutogrow(document);
  pcdWireAutosubmit(document);
  pcdWireRequiredFilters(document);
  pcdWireConfirmForms(document);
  pcdWireFormExtras(document);
  pcdWireAttendanceRows(document);
  pcdWireAttendanceDrafts(document);
  pcdWireSlideoverTriggers();
});
