/**
 * Hotel Billing System — frontend (fetch + operation/json params)
 * PHP is used only for /api/*.php backends.
 */
(function () {
  'use strict';

  const BASE_PATH = detectBasePath();
  const API_BASE = `${BASE_PATH}/api`;
  const DEMO_GCASH_NUMBER = '0953 385 2421';
  const DEMO_BANK_ACCOUNT = 'BDO · 0012 3456 7890';

  function detectBasePath() {
    let path = window.location.pathname.replace(/\\/g, '/');
    path = path.replace(/\/(admin|frontdesk|guest|kitchen|laundry|housekeeping|api)(\/.*)?$/i, '');
    path = path.replace(/\/[^/]+\.html?$/i, '');
    return path.replace(/\/$/, '') || '';
  }

  async function renderAdminBillingDiscounts(main) {
    await renderWorkflowTabs(main, [
      { id: 'billing', label: 'Billing', template: 'billing', render: renderBillingFd },
      { id: 'discounts', label: 'Discounts', template: 'discounts', render: renderDiscounts },
    ], 'billing', 'Billing and discounts');
  }

  async function renderGuestManagement(main) {
    await renderWorkflowTabs(main, [
      { id: 'guest-list', label: 'Guest List', template: 'guests', render: renderGuests },
      { id: 'register', label: 'Register Guest', template: 'register-guest', render: renderGuestRegister },
    ], 'guest-list', 'Guest list and registration');
  }

  function money(n) {
    return '₱' + Number(n || 0).toLocaleString('en-PH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  }

  function quantityLabel(value) {
    const quantity = Number(value);
    return Number.isFinite(quantity)
      ? quantity.toLocaleString('en-US', { useGrouping: false, maximumFractionDigits: 6 })
      : '0';
  }

  function e(str) {
    return String(str ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function payMethodBadge(method) {
    if (method === 'online' || method === 'gcash') return '<span class="badge badge-info">GCash</span>';
    if (method === 'bank' || method === 'bank_transfer') return '<span class="badge badge-warn">Bank Transfer</span>';
    return '<span class="badge badge-ok">Cash (counter)</span>';
  }

  function paymentDestinationMarkup(method) {
    if (method === 'gcash') {
      return `<strong>Demo GCash recipient:</strong> Aurora Grand Hotel · ${DEMO_GCASH_NUMBER}
        <small>Sample details for testing only. Verify the hotel's official payment details before sending money.</small>`;
    }
    if (method === 'bank_transfer') {
      return `<strong>Demo bank transfer recipient:</strong> Aurora Grand Hotel · ${DEMO_BANK_ACCOUNT}
        <small>Sample details for testing only. Verify the hotel's official payment details before sending money.</small>`;
    }
    return '';
  }

  /* 12-hour time picker with hour, minute, second and AM/PM; the hidden input posts HH:MM:SS */
  function parseTimeParts(value, fallback) {
    const m = String(value || fallback || '15:00:00').match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?/) || ['', '15', '00', '00'];
    return { h: Number(m[1]) % 24, m: Number(m[2]), s: Number(m[3] || 0) };
  }

  function timeOptions(selected, fallback = '15:00', name = 'time', disabled = false, policy = '') {
    const t = parseTimeParts(selected, fallback);
    const pad = (n) => String(n).padStart(2, '0');
    const dis = disabled ? 'disabled' : '';
    if (policy === 'check-in') {
      const minute = t.h === 15 ? t.m : 0;
      return `<div class="time-picker time-picker-policy" data-time-picker data-time-policy="check-in">
        <input type="hidden" name="${e(name)}" value="15:${pad(minute)}:00" ${dis}>
        <span>3:</span><select data-tp="m" aria-label="Check-in minute" ${dis}>${Array.from({ length: 60 }, (_, i) =>
          `<option value="${i}" ${i === minute ? 'selected' : ''}>${pad(i)}</option>`).join('')}</select><span> PM</span>
      </div>`;
    }
    if (policy === 'check-out' || policy === 'transfer') {
      const hour = policy === 'check-out' ? 14 : 15;
      const label = policy === 'check-out' ? '2:00 PM' : '3:00 PM';
      return `<div class="time-picker time-picker-policy" data-time-picker data-time-policy="${policy}">
        <input type="hidden" name="${e(name)}" value="${pad(hour)}:00:00" ${dis}><span>${label}</span>
      </div>`;
    }
    const h12 = t.h % 12 === 0 ? 12 : t.h % 12;
    const range = (n, cur, from = 0) =>
      Array.from({ length: n }, (_, i) => i + from)
        .map((v) => `<option value="${v}" ${v === cur ? 'selected' : ''}>${pad(v)}</option>`)
        .join('');
    return `<div class="time-picker" data-time-picker>
      <input type="hidden" name="${e(name)}" value="${pad(t.h)}:${pad(t.m)}:${pad(t.s)}" ${dis}>
      <select data-tp="h" aria-label="Hour" ${dis}>${range(12, h12, 1)}</select><span>:</span>
      <select data-tp="m" aria-label="Minute" ${dis}>${range(60, t.m)}</select><span>:</span>
      <select data-tp="s" aria-label="Second" ${dis}>${range(60, t.s)}</select>
      <select data-tp="p" aria-label="AM or PM" ${dis}><option value="AM" ${t.h < 12 ? 'selected' : ''}>AM</option><option value="PM" ${t.h >= 12 ? 'selected' : ''}>PM</option></select>
      ${disabled ? '' : '<button type="button" class="btn btn-ghost btn-sm" data-tp-now title="Use the current time">Now</button>'}
    </div>`;
  }

  function syncTimePicker(box) {
    if (box.dataset.timePolicy === 'check-in') {
      const pad = (n) => String(n).padStart(2, '0');
      box.querySelector('input[type="hidden"]').value = `15:${pad(box.querySelector('[data-tp="m"]').value)}:00`;
      return;
    }
    const get = (k) => box.querySelector(`[data-tp="${k}"]`).value;
    let h = Number(get('h')) % 12;
    if (get('p') === 'PM') h += 12;
    const pad = (n) => String(n).padStart(2, '0');
    box.querySelector('input[type="hidden"]').value = `${pad(h)}:${pad(get('m'))}:${pad(get('s'))}`;
  }

  document.addEventListener('change', (ev) => {
    const box = ev.target.closest?.('[data-time-picker]');
    if (box && ev.target.dataset.tp) syncTimePicker(box);
  });
  document.addEventListener('click', (ev) => {
    const btn = ev.target.closest?.('[data-tp-now]');
    if (!btn) return;
    const box = btn.closest('[data-time-picker]');
    const d = new Date();
    box.querySelector('[data-tp="h"]').value = d.getHours() % 12 === 0 ? 12 : d.getHours() % 12;
    box.querySelector('[data-tp="m"]').value = d.getMinutes();
    box.querySelector('[data-tp="s"]').value = d.getSeconds();
    box.querySelector('[data-tp="p"]').value = d.getHours() < 12 ? 'AM' : 'PM';
    syncTimePicker(box);
  });

  function fmtTime(value) {
    if (!value) return '';
    const [h, m, s] = String(value).split(':').map(Number);
    if (Number.isNaN(h)) return '';
    return `${h % 12 === 0 ? 12 : h % 12}:${String(m || 0).padStart(2, '0')}:${String(s || 0).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`;
  }

  function fmtStayPoint(date, time) {
    if (!date) return '—';
    return e(`${date}${time ? ' ' + fmtTime(time) : ''}`);
  }

  function fmtBillingDateTime(value) {
    if (!value) return '—';
    const raw = String(value).trim();
    const date = new Date(raw.includes('T') ? raw : raw.replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return e(raw);

    const dateText = new Intl.DateTimeFormat('en-PH', {
      weekday: 'long',
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    }).format(date);
    if (!/\d{1,2}:\d{2}/.test(raw)) return e(dateText);

    const timeText = new Intl.DateTimeFormat('en-PH', {
      hour: 'numeric',
      minute: '2-digit',
      hourCycle: 'h12',
    }).format(date);
    return e(`${dateText} at ${timeText}`);
  }

  function fmtShortDate(value) {
    const raw = String(value || '').slice(0, 10);
    const date = new Date(`${raw}T12:00:00`);
    return Number.isNaN(date.getTime())
      ? e(raw || '—')
      : e(new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }).format(date));
  }

  function paymentReceiptExportUrl(paymentIds) {
    const ids = [...new Set((Array.isArray(paymentIds) ? paymentIds : [paymentIds])
      .map((id) => String(id).trim()).filter((id) => /^\d+$/.test(id) && Number(id) > 0))];
    return ids.length
      ? `${API_BASE}/payment_receipt_export.php?payment_ids=${encodeURIComponent(ids.join(','))}`
      : '#';
  }

  function invoiceExportUrl(billId) {
    return `${API_BASE}/invoice_export.php?bill_id=${encodeURIComponent(billId)}`;
  }

  /* Pick-from-list room features (only the catalog below, plus any already saved on the record) */
  const ROOM_FEATURE_CATALOG = ['WiFi', 'TV', 'AC', 'Mini Bar', 'Sofa', 'Jacuzzi', 'Living Area', 'Balcony', 'Safe', 'Coffee Maker', 'Hot Shower', 'Breakfast'];

  function featurePicker(name, value = '', id = '') {
    const chosen = String(value || '').split(',').map((s) => s.trim()).filter(Boolean);
    const all = [...ROOM_FEATURE_CATALOG, ...chosen.filter((c) => !ROOM_FEATURE_CATALOG.some((k) => k.toLowerCase() === c.toLowerCase()))];
    const isOn = (f) => chosen.some((c) => c.toLowerCase() === f.toLowerCase());
    return `<div class="feature-picker" data-feature-picker ${id ? `id="${e(id)}"` : ''}>
      <input type="hidden" name="${e(name)}" value="${e(chosen.join(', '))}">
      <div class="feature-summary muted"></div>
      <div class="feature-chips">${all.map((f) => `<label class="feature-chip ${isOn(f) ? 'on' : ''}"><input type="checkbox" value="${e(f)}" ${isOn(f) ? 'checked' : ''}><span>${e(f)}</span></label>`).join('')}</div>
    </div>`;
  }

  function syncFeaturePicker(box) {
    const on = [...box.querySelectorAll('input[type="checkbox"]')].filter((i) => i.checked).map((i) => i.value);
    box.querySelector('input[type="hidden"]').value = on.join(', ');
    box.querySelector('.feature-summary').textContent = on.length ? `${on.length} selected: ${on.join(', ')}` : 'No features selected - tap to choose.';
    box.querySelectorAll('.feature-chip').forEach((chip) => chip.classList.toggle('on', chip.querySelector('input').checked));
  }

  function mountFeaturePickers(root) {
    root.querySelectorAll('[data-feature-picker-slot]').forEach((slot) => {
      slot.outerHTML = featurePicker(slot.dataset.featurePickerSlot, slot.dataset.value || '', slot.id);
    });
    root.querySelectorAll('[data-feature-picker]').forEach(syncFeaturePicker);
  }

  document.addEventListener('change', (ev) => {
    const box = ev.target.closest?.('[data-feature-picker]');
    if (box) syncFeaturePicker(box);
  });
  function fillTimeSelects(root) {
    root.querySelectorAll('select[data-time-select]').forEach((sel) => {
      sel.outerHTML = timeOptions(null, sel.dataset.default || '15:00', sel.name, false, sel.dataset.timePolicy || '');
    });
  }

  /* One calendar control (date + time incl. seconds) that drives the existing date + time inputs.
     Fields inside create/transfer forms cannot be set in the past. */
  function localStamp(d = new Date()) {
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
  }

  function enhanceDateTimeFields(root) {
    const nativeValue = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
    root.querySelectorAll('.dt-field').forEach((field) => {
      if (field.dataset.timePolicy) return;
      const dateIn = field.querySelector('input[type="date"]');
      const timeHidden = field.querySelector('.time-picker input[type="hidden"]');
      if (!dateIn || !timeHidden || dateIn.readOnly || dateIn._dt) return;
      const strict = !!dateIn.closest('#booking-create, #transfer-form, #public-appointment-form, #public-appointment-change-form');
      const pastBlocked = strict && ['check_in', 'transfer_date'].includes(dateIn.name);

      const dt = document.createElement('input');
      dt.type = 'datetime-local';
      dt.step = 1;
      dt.required = dateIn.required;
      dt.className = 'dt-single';
      dt.setAttribute('aria-label', field.querySelector('.dt-label')?.textContent || field.parentElement.querySelector('label')?.textContent || 'Date and time');
      const hint = document.createElement('small');
      hint.className = 'field-hint dt-hint';
      const dateControl = dateIn.closest('.calendar-control');
      if (dateControl) {
        dateControl.after(dt);
        dateControl.hidden = true;
      } else {
        dateIn.after(dt);
      }
      field.appendChild(hint);
      dateIn.style.display = 'none';
      dateIn.required = false;
      const tp = timeHidden.closest('.time-picker');
      if (tp) tp.style.display = 'none';
      dateIn._dt = dt;

      const timeVal = () => timeHidden.value || '00:00:00';
      const lowest = () => {
        const parts = [];
        if (dateIn.min) parts.push(`${dateIn.min}T00:00:00`);
        if (pastBlocked) parts.push(localStamp());
        if (dt.dataset.minExtra) parts.push(dt.dataset.minExtra);
        return parts.sort().pop() || '';
      };
      const highest = () => (dateIn.max ? `${dateIn.max}T23:59:59` : '');
      const refreshBounds = () => {
        const min = lowest();
        const max = highest();
        if (dt.min !== min) dt.min = min;
        if (dt.max !== max) dt.max = max;
      };
      const push = (fire) => {
        const [d, t] = (dt.value || '').split('T');
        nativeValue.set.call(dateIn, d || '');
        timeHidden.value = t ? (t.length === 5 ? `${t}:00` : t) : timeHidden.value;
        if (fire) dateIn.dispatchEvent(new Event('change', { bubbles: true }));
      };
      const clamp = (announce) => {
        refreshBounds();
        let msg = '';
        if (dt.value && dt.min && dt.value < dt.min) { dt.value = dt.min; msg = 'Past dates and times are not allowed - moved to the earliest available time.'; }
        if (dt.value && dt.max && dt.value > dt.max) { dt.value = dt.max; msg = 'That date is beyond the allowed range - moved to the latest available time.'; }
        if (announce) hint.textContent = msg;
        return !msg;
      };

      Object.defineProperty(dateIn, 'value', {
        get() { return nativeValue.get.call(this); },
        set(v) {
          nativeValue.set.call(this, v);
          dt.value = v ? `${v}T${timeVal()}` : '';
          if (strict) clamp(false);
          push(false);
        },
        configurable: true,
      });
      ['min', 'max'].forEach((prop) => {
        const desc = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, prop);
        Object.defineProperty(dateIn, prop, {
          get() { return desc.get.call(this); },
          set(v) { desc.set.call(this, v); refreshBounds(); },
          configurable: true,
        });
      });

      dt.value = dateIn.value ? `${dateIn.value}T${timeVal()}` : '';
      if (strict && dt.value) clamp(false);
      push(false);
      refreshBounds();
      dt.addEventListener('focus', refreshBounds);
      dt.addEventListener('change', () => {
        if (!dt.value) { nativeValue.set.call(dateIn, ''); dateIn.dispatchEvent(new Event('change', { bubbles: true })); return; }
        clamp(true);
        push(true);
      });
      dt.addEventListener('refresh', () => { clamp(false); push(false); });
    });
  }

  const calendarDateFormatter = new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
  const calendarMonthFormatter = new Intl.DateTimeFormat('en-US', {
    month: 'long',
    year: 'numeric',
  });

  function dateFromCalendarValue(value) {
    const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
    return match ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])) : null;
  }

  let calendarInputSequence = 0;

  function enhanceCalendarInputs(root) {
    if (!root?.querySelectorAll) return;
    const inputs = [
      ...(root.matches?.('input[type="date"], input[type="datetime-local"]') ? [root] : []),
      ...root.querySelectorAll('input[type="date"], input[type="datetime-local"]'),
    ];
    inputs.forEach((input) => {
      if (input.dataset.calendarEnhanced === 'true') return;
      if (input._dt) {
        input.dataset.calendarEnhanced = 'true';
        return;
      }
      input.dataset.calendarEnhanced = 'true';
      const isDateTime = input.type === 'datetime-local';
      const nativeValue = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
      const nativeMin = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'min');
      const nativeMax = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'max');
      const nativeRequired = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'required');
      const nativeDisabled = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'disabled');
      const control = document.createElement('div');
      control.className = 'calendar-control';
      control.dataset.datetime = String(isDateTime);
      const trigger = document.createElement('button');
      trigger.type = 'button';
      trigger.className = 'calendar-trigger';
      trigger.setAttribute('aria-haspopup', 'dialog');
      trigger.setAttribute('aria-expanded', 'false');
      trigger.innerHTML = '<span class="calendar-trigger-value"></span><span class="calendar-trigger-icon" aria-hidden="true"></span>';
      const panel = document.createElement('div');
      panel.className = 'calendar-popover';
      panel.id = `calendar-picker-${++calendarInputSequence}`;
      panel.setAttribute('role', 'dialog');
      panel.setAttribute('aria-label', isDateTime ? 'Choose date and time' : 'Choose date');
      panel.hidden = true;
      const fieldLabel = input.id
        ? document.querySelector(`label[for="${CSS.escape(input.id)}"]`)?.textContent
        : input.closest('label')?.textContent;
      trigger.setAttribute('aria-controls', panel.id);
      trigger.setAttribute('aria-label', `Choose ${fieldLabel?.trim() || (isDateTime ? 'date and time' : 'date')}`);
      input.before(control);
      control.append(input, trigger);
      control.calendarPanel = panel;
      document.body.append(panel);
      input.classList.add('calendar-native');
      const wasRequired = input.required;
      let requiredState = wasRequired;
      nativeRequired.set.call(input, false);
      trigger.setAttribute('aria-required', String(wasRequired));

      let visibleMonth = dateFromCalendarValue(input.value) || dateFromCalendarValue(input.min) || new Date();
      visibleMonth = new Date(visibleMonth.getFullYear(), visibleMonth.getMonth(), 1);
      let hasError = false;

      const currentValue = () => nativeValue.get.call(input) || '';
      const formatDisplay = () => {
        const value = currentValue();
        if (!value) return `Select ${isDateTime ? 'date and time' : 'date'}…`;
        const date = dateFromCalendarValue(value);
        if (!date) return value;
        if (!isDateTime) return calendarDateFormatter.format(date);
        const time = value.slice(11);
        const [hour = '00', minute = '00', second = '00'] = time.split(':');
        const hourNumber = Number(hour);
        const twelveHour = hourNumber % 12 || 12;
        const timeDisplay = `${String(twelveHour).padStart(2, '0')}:${minute}${input.step === '1' ? `:${second}` : ''}`;
        return `${calendarDateFormatter.format(date)} · ${timeDisplay} ${hourNumber < 12 ? 'AM' : 'PM'}`;
      };
      const updateTrigger = () => {
        trigger.querySelector('.calendar-trigger-value').textContent = formatDisplay();
        trigger.disabled = input.disabled;
        trigger.setAttribute('aria-invalid', String(hasError));
      };
      const bounds = () => ({
        minDate: dateFromCalendarValue(nativeMin.get.call(input)),
        maxDate: dateFromCalendarValue(nativeMax.get.call(input)),
      });
      const dateAllowed = (date) => {
        const { minDate, maxDate } = bounds();
        const day = date.getFullYear() * 10000 + (date.getMonth() + 1) * 100 + date.getDate();
        const min = minDate ? minDate.getFullYear() * 10000 + (minDate.getMonth() + 1) * 100 + minDate.getDate() : null;
        const max = maxDate ? maxDate.getFullYear() * 10000 + (maxDate.getMonth() + 1) * 100 + maxDate.getDate() : null;
        return (min === null || day >= min) && (max === null || day <= max);
      };
      const isoDate = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
      const timeParts = () => {
        const time = currentValue().slice(11);
        const [hour = '15', minute = '00', second = '00'] = time.split(':');
        return { hour: Number(hour), minute: Number(minute), second: Number(second) };
      };
      const applyValue = (date, time = timeParts()) => {
        let next = isoDate(date);
        if (isDateTime) {
          const pad = (n) => String(n).padStart(2, '0');
          const seconds = input.step === '1' ? time.second : 0;
          next += `T${pad(time.hour)}:${pad(time.minute)}${input.step === '1' ? `:${pad(seconds)}` : ''}`;
          const { min, max } = input;
          if (min && next < min) next = min;
          if (max && next > max) next = max;
        }
        nativeValue.set.call(input, next);
        hasError = false;
        updateTrigger();
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
      };
      const selectOptions = (start, end, selected, pad = false) => Array.from(
        { length: end - start + 1 },
        (_, index) => {
          const value = start + index;
          return `<option value="${value}" ${value === selected ? 'selected' : ''}>${pad ? String(value).padStart(2, '0') : value}</option>`;
        },
      ).join('');
      const renderPanel = () => {
        const selected = dateFromCalendarValue(currentValue());
        const today = new Date();
        const firstWeekday = new Date(visibleMonth.getFullYear(), visibleMonth.getMonth(), 1).getDay();
        const daysInMonth = new Date(visibleMonth.getFullYear(), visibleMonth.getMonth() + 1, 0).getDate();
        const cells = [];
        for (let i = 0; i < firstWeekday; i += 1) cells.push('<span class="calendar-day-placeholder" aria-hidden="true"></span>');
        for (let day = 1; day <= daysInMonth; day += 1) {
          const date = new Date(visibleMonth.getFullYear(), visibleMonth.getMonth(), day);
          const selectedDay = selected && isoDate(date) === isoDate(selected);
          const isToday = isoDate(date) === isoDate(today);
          cells.push(`<button type="button" class="calendar-day${selectedDay ? ' is-selected' : ''}${isToday ? ' is-today' : ''}" data-calendar-day="${day}" ${dateAllowed(date) ? '' : 'disabled'} aria-pressed="${selectedDay}">${day}</button>`);
        }
        const { hour, minute, second } = timeParts();
        const hour12 = hour % 12 || 12;
        panel.innerHTML = `
          <div class="calendar-head">
            <button type="button" class="calendar-nav" data-calendar-month="-1" aria-label="Previous month">‹</button>
            <strong>${calendarMonthFormatter.format(visibleMonth)}</strong>
            <button type="button" class="calendar-nav" data-calendar-month="1" aria-label="Next month">›</button>
          </div>
          <div class="calendar-weekdays" aria-hidden="true"><span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span></div>
          <div class="calendar-days">${cells.join('')}</div>
          ${isDateTime ? `<div class="calendar-time">
            <span>Time</span>
            <select data-calendar-time="hour" aria-label="Hour">${selectOptions(1, 12, hour12, true)}</select>
            <span>:</span>
            <select data-calendar-time="minute" aria-label="Minute">${selectOptions(0, 59, minute, true)}</select>
            ${input.step === '1' ? `<span>:</span><select data-calendar-time="second" aria-label="Second">${selectOptions(0, 59, second, true)}</select>` : ''}
            <select data-calendar-time="period" aria-label="AM or PM"><option value="AM" ${hour < 12 ? 'selected' : ''}>AM</option><option value="PM" ${hour >= 12 ? 'selected' : ''}>PM</option></select>
          </div>` : ''}
          <div class="calendar-foot">
            <button type="button" class="calendar-action" data-calendar-clear ${wasRequired ? 'disabled' : ''}>Clear</button>
            <button type="button" class="calendar-action" data-calendar-today>Today</button>
          </div>`;
      };
      const closePanel = (returnFocus = false) => {
        panel.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        control.classList.remove('is-open');
        if (returnFocus) trigger.focus();
      };
      const positionPanel = () => {
        if (panel.hidden) return;
        const rect = trigger.getBoundingClientRect();
        panel.style.left = '12px';
        panel.style.top = '12px';
        const panelWidth = panel.offsetWidth;
        const panelHeight = panel.offsetHeight;
        const left = Math.max(12, Math.min(rect.left, window.innerWidth - panelWidth - 12));
        const spaceBelow = window.innerHeight - rect.bottom - 12;
        const spaceAbove = rect.top - 12;
        const placeBelow = panelHeight <= spaceBelow || spaceBelow >= spaceAbove;
        const preferredTop = placeBelow ? rect.bottom + 8 : rect.top - panelHeight - 8;
        const top = Math.max(12, Math.min(preferredTop, window.innerHeight - panelHeight - 12));
        panel.style.left = `${left}px`;
        panel.style.top = `${top}px`;
      };
      const openPanel = () => {
        if (input.disabled) return;
        const selected = dateFromCalendarValue(currentValue()) || dateFromCalendarValue(input.min) || new Date();
        visibleMonth = new Date(selected.getFullYear(), selected.getMonth(), 1);
        renderPanel();
        panel.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
        control.classList.add('is-open');
        positionPanel();
        panel.querySelector('.calendar-day:not(:disabled)')?.focus({ preventScroll: true });
      };
      control.positionCalendar = positionPanel;
      trigger.addEventListener('click', () => panel.hidden ? openPanel() : closePanel());
      panel.addEventListener('click', (event) => {
        const monthButton = event.target.closest('[data-calendar-month]');
        if (monthButton) {
          visibleMonth.setMonth(visibleMonth.getMonth() + Number(monthButton.dataset.calendarMonth));
          renderPanel();
          positionPanel();
          return;
        }
        const dayButton = event.target.closest('[data-calendar-day]');
        if (dayButton) {
          const selectedDate = new Date(visibleMonth.getFullYear(), visibleMonth.getMonth(), Number(dayButton.dataset.calendarDay));
          const time = timeParts();
          const hour12Select = panel.querySelector('[data-calendar-time="hour"]');
          if (hour12Select) {
            let hour = Number(hour12Select.value) % 12;
            if (panel.querySelector('[data-calendar-time="period"]').value === 'PM') hour += 12;
            time.hour = hour;
            time.minute = Number(panel.querySelector('[data-calendar-time="minute"]').value);
            time.second = Number(panel.querySelector('[data-calendar-time="second"]')?.value || 0);
          }
          applyValue(selectedDate, time);
          closePanel(true);
          return;
        }
        if (event.target.closest('[data-calendar-clear]')) {
          if (wasRequired) return;
          nativeValue.set.call(input, '');
          hasError = false;
          updateTrigger();
          input.dispatchEvent(new Event('input', { bubbles: true }));
          input.dispatchEvent(new Event('change', { bubbles: true }));
          closePanel(true);
          return;
        }
        if (event.target.closest('[data-calendar-today]')) {
          const today = new Date();
          if (dateAllowed(today)) applyValue(today);
          closePanel(true);
        }
      });
      panel.addEventListener('change', (event) => {
        if (!event.target.matches('[data-calendar-time]')) return;
        const selected = dateFromCalendarValue(currentValue()) || dateFromCalendarValue(input.min) || new Date();
        const hour12 = Number(panel.querySelector('[data-calendar-time="hour"]').value) % 12;
        const hour = hour12 + (panel.querySelector('[data-calendar-time="period"]').value === 'PM' ? 12 : 0);
        applyValue(selected, {
          hour,
          minute: Number(panel.querySelector('[data-calendar-time="minute"]').value),
          second: Number(panel.querySelector('[data-calendar-time="second"]')?.value || 0),
        });
        renderPanel();
        positionPanel();
      });
      trigger.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          openPanel();
        }
      });
      panel.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closePanel(true);
      });
      input.addEventListener('input', updateTrigger);
      input.addEventListener('change', updateTrigger);
      input.addEventListener('refresh', () => { updateTrigger(); if (!panel.hidden) renderPanel(); });

      const defineSyncedProperty = (property, descriptor, refresh) => {
        Object.defineProperty(input, property, {
          configurable: true,
          get() { return descriptor.get.call(this); },
          set(value) {
            descriptor.set.call(this, value);
            refresh();
          },
        });
      };
      defineSyncedProperty('value', nativeValue, updateTrigger);
      defineSyncedProperty('min', nativeMin, () => { updateTrigger(); if (!panel.hidden) renderPanel(); });
      defineSyncedProperty('max', nativeMax, () => { updateTrigger(); if (!panel.hidden) renderPanel(); });
      Object.defineProperty(input, 'required', {
        configurable: true,
        get() { return requiredState; },
        set(value) {
          requiredState = Boolean(value);
          nativeRequired.set.call(this, false);
          trigger.setAttribute('aria-required', String(requiredState));
          if (!panel.hidden) renderPanel();
        },
      });
      defineSyncedProperty('disabled', nativeDisabled, updateTrigger);
      Object.defineProperty(input, 'calendarValidate', {
        configurable: true,
        value() {
          if (!input.disabled && requiredState && !currentValue()) {
            hasError = true;
            updateTrigger();
            openPanel();
            return false;
          }
          hasError = false;
          updateTrigger();
          return true;
        },
      });
      updateTrigger();
    });
  }

  const calendarObserver = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
      if (mutation.type === 'childList') {
        mutation.removedNodes.forEach((node) => {
          if (node.nodeType !== Node.ELEMENT_NODE) return;
          const removedControls = [
            ...(node.matches?.('.calendar-control') ? [node] : []),
            ...node.querySelectorAll('.calendar-control'),
          ];
          removedControls.forEach((control) => control.calendarPanel?.remove());
        });
        mutation.addedNodes.forEach((node) => {
          if (node.nodeType === Node.ELEMENT_NODE) enhanceCalendarInputs(node);
        });
        return;
      }
      const input = mutation.target;
      if (input instanceof HTMLInputElement && input.calendarValidate) {
        input.dispatchEvent(new Event('refresh'));
      }
    });
  });
  calendarObserver.observe(document.body, {
    childList: true,
    subtree: true,
    attributes: true,
    attributeFilter: ['min', 'max', 'required', 'disabled'],
  });
  window.addEventListener('resize', () => {
    document.querySelectorAll('.calendar-control.is-open').forEach((control) => control.positionCalendar?.());
  });
  document.addEventListener('click', (event) => {
    document.querySelectorAll('.calendar-control.is-open').forEach((control) => {
      const panel = control.calendarPanel;
      if (!control.contains(event.target) && !panel.contains(event.target)) {
        const trigger = control.querySelector('.calendar-trigger');
        panel.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        control.classList.remove('is-open');
      }
    });
  });
  document.addEventListener('submit', (event) => {
    const invalidCalendar = [...event.target.querySelectorAll('input.calendar-native')].find((input) =>
      typeof input.calendarValidate === 'function' && !input.calendarValidate()
    );
    if (invalidCalendar) event.preventDefault();
  }, true);

  function statusBadge(status) {
    const map = {
      active: 'ok',
      available: 'ok',
      paid: 'ok',
      answered: 'ok',
      checked_in: 'info',
      reserved: 'info',
      partial: 'warn',
      pending: 'warn',
      preparing: 'info',
      ready: 'info',
      to_be_delivered: 'warn',
      delivered: 'ok',
      not_delivered: 'danger',
      in_progress: 'info',
      completed: 'ok',
      rejected: 'danger',
      damaged: 'danger',
      stained: 'warn',
      missing: 'danger',
      open: 'warn',
      maintenance: 'warn',
      cleaning: 'warn',
      occupied: 'info',
      inactive: 'muted',
      cancelled: 'danger',
      overdue: 'danger',
      checked_out: 'muted',
    };
    const cls = map[status] || 'muted';
    const label = String(status || '')
      .replace(/_/g, ' ')
      .replace(/\b\w/g, (c) => c.toUpperCase());
    return `<span class="badge badge-${cls}">${e(label)}</span>`;
  }

  function roomRequestsDropdown(room) {
    const requests = room.requests || [];
    const roomLabel = room.room_number ? `Room ${room.room_number}` : 'Guest account';
    const options = requests.map((request) => {
      const type = ({ extra: 'Amenities', food: 'Food', laundry: 'Laundry' })[request.request_type] || request.request_type;
      const quantity = quantityLabel(request.quantity);
      return `<option value="${e(request.id)}">${e(type)}: ${e(request.item_name)} × ${e(quantity)} · ${e(String(request.status || 'pending').replace(/_/g, ' '))}</option>`;
    }).join('');
    return `<select class="room-request-dropdown" aria-label="Requested services for ${e(roomLabel)}">
      <option value="">${requests.length ? `Requested services (${requests.length})` : 'No requested services'}</option>
      ${options}
    </select>`;
  }

  function paymentStatusDescription(status) {
    return {
      pending: 'No payment has been recorded. The balance is still due.',
      partial: 'A partial payment was recorded. The remaining balance is due.',
      paid: 'The balance has been paid in full.',
      overdue: 'Payment is overdue and a balance remains.',
      void: 'This bill has been voided.',
    }[status] || '';
  }

  async function requestJson(url, options = {}) {
    let response;
    try {
      response = await fetch(url, { credentials: 'same-origin', ...options });
    } catch (error) {
      throw new Error(error.message || 'Could not connect to the server.');
    }

    let result;
    try {
      result = await response.json();
    } catch {
      throw new Error(`The server returned an invalid response (HTTP ${response.status}).`);
    }
    if (!response.ok || !result.success) {
      throw new Error(result.message || `Request failed (HTTP ${response.status}).`);
    }
    return result;
  }

  /** GET with operation + optional JSON parameters */
  async function apiGet(file, operation, data = {}) {
    const params = new URLSearchParams({ operation });
    if (data && Object.keys(data).length) {
      params.set('json', JSON.stringify(data));
    }
    return requestJson(`${API_BASE}/${file}?${params.toString()}`);
  }

  /** POST with operation + JSON parameters */
  async function apiPost(file, operation, data = {}) {
    const body = new URLSearchParams();
    body.set('operation', operation);
    body.set('json', JSON.stringify(data || {}));
    return requestJson(`${API_BASE}/${file}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body,
    });
  }

  /** POST multipart (room photos) — operation + form fields */
  async function apiPostForm(file, operation, formData) {
    formData.set('operation', operation);
    return requestJson(`${API_BASE}/${file}`, {
      method: 'POST',
      body: formData,
    });
  }

  function qs(name) {
    return new URLSearchParams(window.location.search).get(name) || '';
  }

  function showAlert(container, type, message) {
    if (!container) return;
    container.innerHTML = `<div class="alert alert-${type}">${e(message)}</div>`;
  }

  const DEMO_ACCOUNTS = [
    { label: 'Admin', email: 'admin@gmail.com', password: 'admin123' },
    { label: 'Front Desk', email: 'frontdesk@gmail.com', password: 'frontdesk123' },
    { label: 'Guest', email: 'guest@gmail.com', password: 'guest123' },
    { label: 'Kitchen', email: 'kitchen@gmail.com', password: 'kitchen123' },
    { label: 'Amenities & Laundry', email: 'laundry@gmail.com', password: 'laundry123' },
    { label: 'Housekeeping', email: 'housekeeping@gmail.com', password: 'housekeeping123' },
  ];

  async function loginWithAccount(email, password) {
    const res = await apiPost('auth.php', 'login', { email, password });
    window.location.href = res.data.home;
  }

  const NAV = {
    admin: [
      ['dashboard', 'Dashboard', 'index.html'],
      ['housekeeping', 'Request Assignments & Room Inspections', 'housekeeping.html'],
      ['room-management', 'Rooms, Stays & Transfers', 'room-management.html'],
      ['users', 'Users & Roles', 'users.html'],
      ['room-types', 'Room Management System', 'room-types.html'],
      ['guests', 'Guest Management', 'guests.html'],
      ['rooms', 'Rooms & Hotel Services', 'rooms.html'],
      ['requested-items', 'Guest Requests & Items', 'requested-items.html'],
      ['stay-management', 'Check-In / Check-Out', 'checkin.html'],
      ['appointments', 'Room Appointments', 'appointments.html'],
      ['billing', 'Billing & Discounts', 'billing.html'],
      ['reports', 'Reports', 'reports.html'],
      ['audit', 'System Audit Logs', 'audit.html'],
      ['profile', 'My Profile', 'profile.html'],
    ],
    frontdesk: [
      ['dashboard', 'Dashboard', 'index.html'],
      ['operations', 'Request Assignments & Room Inspections', 'operations.html'],
      ['room-management', 'Rooms, Stays & Transfers', 'room-management.html'],
      ['guests', 'Guest Management', 'guests.html'],
      ['stay-management', 'Check-In / Check-Out', 'checkin.html'],
      ['appointments', 'Room Appointments', 'appointments.html'],
      ['billing', 'Guest Accounts & Charges', 'billing.html'],
      ['reports', 'Reports', 'reports.html'],
      ['rooms', 'Rooms & Hotel Services', 'rooms.html'],
      ['payments', 'Payments & Invoices', 'payments.html'],
      ['audit', 'Staff Audit Logs', 'audit.html'],
      ['profile', 'My Profile', 'profile.html'],
    ],
    kitchen: [['dashboard', 'Kitchen Requests', 'index.html'], ['profile', 'My Profile', 'profile.html']],
    laundry: [['dashboard', 'Amenities & Laundry Requests', 'index.html'], ['profile', 'My Profile', 'profile.html']],
    housekeeping: [['dashboard', 'Housekeeping Tasks', 'index.html'], ['profile', 'My Profile', 'profile.html']],
    guest: [
      ['dashboard', 'My Stay', 'index.html'],
      ['bill', 'Current Bill', 'bill.html'],
      ['requests', 'Request Items', 'requests.html'],
      ['payments', 'Payment History', 'payments.html'],
      ['invoices', 'Invoices', 'invoices.html'],
      ['booking', 'Booking Info', 'booking.html'],
      ['transfers', 'Room Transfers', 'transfers.html'],
      ['profile', 'My Profile', 'profile.html'],
    ],
  };

  function roleLabel(role) {
    return {
      admin: 'Admin', frontdesk: 'Front Desk', guest: 'Guest',
      kitchen: 'Kitchen', laundry: 'Amenities & Laundry Staff', housekeeping: 'Housekeeping',
    }[role] || role;
  }

  function renderAppShell({ title, role, active, user, hotelName }) {
    const nav = (NAV[role] || [])
      .map(
        ([key, label, href]) =>
          `<a class="nav-link ${active === key ? 'active' : ''}" href="${e(href)}">${e(label)}</a>`
      )
      .join('');

    return `
<div class="app-shell">
  <div class="sidebar-overlay no-print" id="sidebar-overlay" aria-hidden="true"></div>
  <aside class="sidebar no-print" id="sidebar">
    <div class="sidebar-head">
      <div class="logo">${e(hotelName || 'Aurora Grand Hotel')}</div>
      <button type="button" class="sidebar-close" id="sidebar-close" aria-label="Close menu"><span></span><span></span></button>
    </div>
    <div class="role-chip">${e(roleLabel(role))}</div>
    <nav class="nav">
      ${nav}
      <a class="nav-link nav-signout" href="#" id="btn-logout">Sign Out</a>
    </nav>
  </aside>
  <div class="app-content">
    <header class="mobile-header no-print">
      <button type="button" class="hamburger" id="hamburger-btn" aria-label="Open menu" aria-expanded="false">
        <span class="hamburger-line"></span><span class="hamburger-line"></span><span class="hamburger-line"></span>
      </button>
      <div class="mobile-header-info">
        <span class="mobile-header-title">${e(title)}</span>
        <span class="mobile-header-meta">${e(roleLabel(role))} · ${e(user?.full_name || '')}</span>
      </div>
    </header>
    <main class="main">
      <div class="topbar">
        <div>
          <h1>${e(title)}</h1>
          <div class="meta">Signed in as ${e(user?.full_name || '')}</div>
        </div>
      </div>
      <div id="flash"></div>
      <div id="main-div"></div>
    </main>
  </div>
</div>`;
  }

  function bindShellUi() {
    const hamburger = document.getElementById('hamburger-btn');
    const overlay = document.getElementById('sidebar-overlay');
    const sidebarClose = document.getElementById('sidebar-close');
    const open = () => {
      document.body.classList.add('sidebar-open');
      hamburger?.setAttribute('aria-expanded', 'true');
    };
    const close = () => {
      document.body.classList.remove('sidebar-open');
      hamburger?.setAttribute('aria-expanded', 'false');
    };
    hamburger?.addEventListener('click', () =>
      document.body.classList.contains('sidebar-open') ? close() : open()
    );
    overlay?.addEventListener('click', close);
    sidebarClose?.addEventListener('click', close);
    document.querySelectorAll('.nav .nav-link:not(.nav-signout)').forEach((link) => {
      link.addEventListener('click', () => {
        if (window.innerWidth <= 992) close();
      });
    });
    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape' && document.body.classList.contains('sidebar-open')) close();
    });
    document.getElementById('btn-logout')?.addEventListener('click', async (ev) => {
      ev.preventDefault();
      try {
        await apiPost('auth.php', 'logout');
      } catch (_) {}
      window.location.href = `${BASE_PATH}/index.html`;
    });
    bindGlobalUi(document.getElementById('app') || document.body);
  }

  /** Shared UX helpers (merged from former app.js) */
  function bindGlobalUi(root) {
    const scope = root || document;

    scope.querySelectorAll('input[name="phone"], input[inputmode="numeric"]').forEach((input) => {
      if (input.dataset.boundPhone === '1') return;
      input.dataset.boundPhone = '1';
      input.addEventListener('input', () => {
        input.value = input.value.replace(/\D/g, '').slice(0, 15);
      });
    });

    scope.querySelectorAll('input[type="file"][data-preview], input[type="file"].file-input').forEach((input) => {
      if (input.dataset.boundPreview === '1') return;
      input.dataset.boundPreview = '1';
      input.addEventListener('change', () => {
        const previewId = input.getAttribute('data-preview');
        const preview = previewId
          ? document.getElementById(previewId)
          : input.parentElement?.querySelector('img.room-photo-preview');
        const file = input.files && input.files[0];
        if (!preview || !file) return;
        const url = URL.createObjectURL(file);
        preview.src = url;
        preview.classList.remove('hidden');
      });
    });

    scope.querySelectorAll('form').forEach((form) => {
      if (form.dataset.boundPw === '1') return;
      const neu = form.querySelector('input[name="new_password"]');
      const confirm = form.querySelector('input[name="confirm_password"]');
      if (!neu || !confirm) return;
      form.dataset.boundPw = '1';
      const check = () => {
        if (confirm.value && neu.value !== confirm.value) {
          confirm.setCustomValidity('Passwords do not match');
        } else {
          confirm.setCustomValidity('');
        }
      };
      neu.addEventListener('input', check);
      confirm.addEventListener('input', check);
    });

    scope.querySelectorAll('[data-confirm]').forEach((el) => {
      if (el.dataset.boundConfirm === '1') return;
      el.dataset.boundConfirm = '1';
      el.addEventListener('click', (ev) => {
        const msg = el.getAttribute('data-confirm') || 'Are you sure?';
        if (!window.confirm(msg)) ev.preventDefault();
      });
    });
  }

  async function requireRole(roles) {
    const me = await apiGet('auth.php', 'me');
    if (!me.data?.authenticated) {
      window.location.href = `${BASE_PATH}/login.html`;
      throw new Error('auth');
    }
    const user = me.data.user;
    if (!roles.includes(user.role)) {
      window.location.href = me.data.home || `${BASE_PATH}/index.html`;
      throw new Error('role');
    }
    return me.data;
  }

  function mountPageTemplate(main, pageKey) {
    const key = pageKey || document.body.dataset.page || '';
    const tpl = document.getElementById('page-' + key);
    if (!tpl || !tpl.content) return false;
    main.replaceChildren(tpl.content.cloneNode(true));
    return true;
  }

  async function renderWorkflowTabs(main, tabs, fallbackTab, ariaLabel = 'Workflow') {
    const queryTab = qs('tab');
    const activeTab = tabs.some((tab) => tab.id === queryTab) ? queryTab : fallbackTab;
    main.innerHTML = `
      <div class="workflow-tabs" role="tablist" aria-label="${e(ariaLabel)}">
        ${tabs.map((tab) => `<button class="btn btn-secondary workflow-tab${tab.id === activeTab ? ' active' : ''}" type="button" role="tab" id="tab-${e(tab.id)}" aria-selected="${tab.id === activeTab}" aria-controls="workflow-tab-panel" data-workflow-tab="${e(tab.id)}">${e(tab.label)}</button>`).join('')}
      </div>
      <div id="workflow-tab-error" aria-live="polite"></div>
      <div class="workflow-tab-panel" id="workflow-tab-panel" role="tabpanel"></div>`;
    const panel = document.getElementById('workflow-tab-panel');
    let renderVersion = 0;
    const showTab = async (tabId) => {
      const tab = tabs.find((item) => item.id === tabId);
      if (!tab) return;
      const currentVersion = ++renderVersion;
      const errorBox = document.getElementById('workflow-tab-error');
      if (errorBox) errorBox.replaceChildren();
      const buttons = [...main.querySelectorAll('[data-workflow-tab]')];
      buttons.forEach((button) => { button.disabled = false; });
      try {
        main.querySelectorAll('[data-workflow-tab]').forEach((button) => {
          const selected = button.dataset.workflowTab === tabId;
          button.classList.toggle('active', selected);
          button.setAttribute('aria-selected', String(selected));
        });
        const template = document.getElementById(`page-${tab.template}`);
        if (!template?.content) throw new Error(`Missing workflow template: ${tab.template}`);
        panel.replaceChildren(template.content.cloneNode(true));
        await tab.render(panel, {
          skipMount: true,
          isCurrent: () => currentVersion === renderVersion,
        });
      } catch (error) {
        if (currentVersion === renderVersion && errorBox) {
          errorBox.innerHTML = `<div class="alert alert-error" role="alert">${e(error.message || 'Could not load this section.')}</div>`;
        }
      }
    };
    main.querySelectorAll('[data-workflow-tab]').forEach((button) => {
      button.addEventListener('click', () => showTab(button.dataset.workflowTab));
    });
    await showTab(activeTab);
  }

  async function renderStayManagement(main) {
    await renderWorkflowTabs(main, [
      { id: 'checkin', label: 'Check-In', template: 'checkin', render: renderCheckIn },
      { id: 'checkout', label: 'Check-Out', template: 'checkout', render: renderCheckOut },
      { id: 'appointments', label: 'Online Appointments', template: 'appointments', render: renderStaffAppointments },
    ], 'checkin', 'Check-In, Check-Out, and Online Appointments');
  }

  async function renderRoomStayManagement(main) {
    await renderWorkflowTabs(main, [
      { id: 'room-schedule', label: 'Room Availability & Scheduling', template: 'room-management', render: renderRoomManagement },
      { id: 'extend-stay', label: 'Extend Stay', template: 'extend-stay', render: renderExtendStay },
      { id: 'transfers', label: 'Transfers', template: 'transfers', render: (panel, options) => renderTransfersStaff(panel, true, options) },
    ], 'room-schedule', 'Room availability, stay extension, and transfers');
  }

  async function renderRoomsServices(main, session, canEditRooms) {
    await renderWorkflowTabs(main, [
      { id: 'rooms', label: 'Rooms & Rates', template: 'rooms', render: (panel, options) => renderRooms(panel, session, canEditRooms, options) },
      { id: 'services', label: 'Hotel Services', template: 'services', render: (panel, options) => renderServices(panel, { ...options, canManage: document.body.dataset.role === 'admin' }) },
      { id: 'archive', label: 'Archive Management', template: 'archive', render: (panel, options) => renderArchiveManagement(panel, options) },
    ], 'rooms', 'Rooms and hotel services');
  }

  async function renderPaymentsInvoices(main) {
    await renderWorkflowTabs(main, [
      { id: 'payments', label: 'Payments', template: 'payments', render: renderPaymentsFd },
      { id: 'invoices', label: 'Invoices', template: 'invoices', render: renderInvoicesFd },
    ], 'payments', 'Payments and invoices');
  }

  async function renderFrontdeskOperations(main) {
    await renderWorkflowTabs(main, [
      { id: 'requests', label: 'Food / Laundry / Amenities Assignments', template: 'requested-items', render: (panel, options) => renderRequestedItems(panel, options) },
      { id: 'inspections', label: 'Checkout Room Inspections', template: 'housekeeping', render: (panel, options) => renderHousekeepingManager(panel, options) },
    ], 'requests', 'Request assignments and checkout room inspections');
  }

  /* ---------- Realtime (WebSocket + polling fallback) ---------- */

  const realtime = {
    version: null,
    refresh: null,
    socket: null,
    socketOpen: false,
    busy: false,
    lastCheck: 0,
    started: false,
  };

  async function realtimeCheck() {
    if (realtime.busy || !realtime.refresh) return;
    realtime.busy = true;
    try {
      const params = new URLSearchParams({ operation: 'getVersion' });
      const res = await requestJson(`${API_BASE}/realtime.php?${params.toString()}`);
      const version = res.data?.version ?? null;
      if (realtime.version === null) {
        realtime.version = version;
      } else if (version !== realtime.version) {
        realtime.version = version;
        await realtimeRefresh();
      }
    } catch (err) {
      /* server unreachable or signed out: try again on the next tick */
    } finally {
      realtime.busy = false;
      realtime.lastCheck = Date.now();
    }
  }

  /** Re-render the current page in place, keeping filters, typed values and focus. */
  async function realtimeRefresh() {
    const main = document.getElementById('main-div');
    if (!main || !realtime.refresh) return;
    const saved = [];
    main.querySelectorAll('input[id], select[id], textarea[id]').forEach((el) => {
      if (['password', 'file'].includes(el.type)) return;
      saved.push({ id: el.id, value: el.type === 'checkbox' || el.type === 'radio' ? el.checked : el.value, checkbox: el.type === 'checkbox' || el.type === 'radio' });
    });
    const active = document.activeElement;
    const focus = active && active.id && main.contains(active)
      ? { id: active.id, start: active.selectionStart, end: active.selectionEnd }
      : null;
    const scrollY = window.scrollY;
    const mainScroll = main.scrollTop;
    try {
      await realtime.refresh();
    } catch (err) {
      return;
    }
    saved.forEach((item) => {
      const el = document.getElementById(item.id);
      if (!el || !main.contains(el)) return;
      const current = item.checkbox ? el.checked : el.value;
      if (current === item.value) return;
      if (item.checkbox) el.checked = item.value;
      else el.value = item.value;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    });
    if (focus) {
      const el = document.getElementById(focus.id);
      if (el) {
        el.focus();
        try {
          if (focus.start !== null) el.setSelectionRange(focus.start, focus.end);
        } catch (err) {
          /* not a text field */
        }
      }
    }
    window.scrollTo(0, scrollY);
    main.scrollTop = mainScroll;
  }

  function realtimeConnect() {
    if (location.protocol === 'https:' || !('WebSocket' in window)) return;
    try {
      const socket = new WebSocket(`ws://${location.hostname}:8081`);
      realtime.socket = socket;
      socket.onopen = () => { realtime.socketOpen = true; };
      socket.onmessage = (event) => {
        try {
          if (JSON.parse(event.data).type === 'changed') realtimeCheck();
        } catch (err) { /* ignore */ }
      };
      socket.onclose = () => {
        realtime.socketOpen = false;
        setTimeout(realtimeConnect, 4000);
      };
      socket.onerror = () => socket.close();
    } catch (err) {
      setTimeout(realtimeConnect, 4000);
    }
  }

  /** Start live updates; `refresh` re-renders the current page. */
  function startRealtime(refresh) {
    realtime.refresh = refresh;
    if (realtime.started) return;
    realtime.started = true;
    realtimeCheck();
    realtimeConnect();
    // Polling safety net: fast when the socket server is down, slow when it is up.
    setInterval(() => {
      if (document.hidden) return;
      const wait = realtime.socketOpen ? 30000 : 4000;
      if (Date.now() - realtime.lastCheck >= wait) realtimeCheck();
    }, 1000);
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) realtimeCheck();
    });
  }
  async function bootAppPage(title, roles, active, renderFn) {
    const app = document.getElementById('app');
    try {
      const session = await requireRole(roles);
      app.innerHTML = renderAppShell({
        title,
        role: session.user.role,
        active,
        user: session.user,
        hotelName: session.hotel_name,
      });
      bindShellUi();
      const main = document.getElementById('main-div');
      mountPageTemplate(main);
      await renderFn(main, session);
      startRealtime(async () => {
        const target = document.getElementById('main-div');
        mountPageTemplate(target);
        await renderFn(target, session);
      });
    } catch (err) {
      if (err.message === 'auth' || err.message === 'role') return;
      app.innerHTML = `<div class="alert alert-error">${e(err.message)}</div>`;
    }
  }

  /* ---------- Public pages ---------- */

  function applyPublicPhotoBg() {
    const lobbyPhoto = `${BASE_PATH}/systemphotos/lobby.jpg`;
    document.body.classList.add('public-fullscreen-photo');
    document.body.style.setProperty('--home-bg-image', `url('${lobbyPhoto}')`);
  }

  async function pageHome() {
    const main = document.getElementById('main-div');
    applyPublicPhotoBg();
    if (!mountPageTemplate(main, 'home')) {
      main.innerHTML = '<div class="alert alert-error">Home page template missing.</div>';
      return;
    }

    document.getElementById('demo-cards').innerHTML = DEMO_ACCOUNTS.map(
      (d) => `
      <div class="demo-card">
        <span class="demo-role">${e(d.label)}</span>
        <div class="demo-email">${e(d.email)}</div>
        <div class="demo-pass">${e(d.password)}</div>
        <button type="button" class="btn btn-secondary btn-sm demo-login-btn" data-email="${e(d.email)}" data-password="${e(d.password)}">Sign in as ${e(d.label)}</button>
      </div>`
    ).join('');

    document.querySelectorAll('.demo-login-btn').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const password = btn.getAttribute('data-password') || '';
        btn.disabled = true;
        try {
          await loginWithAccount(btn.getAttribute('data-email'), password);
        } catch (err) {
          btn.disabled = false;
          alert(err.message || 'Login failed');
        }
      });
    });

    const roomBox = document.getElementById('home-room-types');
    const availableBox = document.getElementById('home-available-rooms');

    async function loadRoomTypes() {
      if (!roomBox) return;
      try {
        const res = await apiGet('room_types.php', 'getPublicRoomTypes');
        const types = res.data || [];
        if (!types.length) {
          roomBox.innerHTML =
            '<p class="empty">No room types configured yet. Import <code>sql/schema.sql</code> to load Standard, Deluxe, and Suite.</p>';
          return;
        }
        roomBox.innerHTML = types
          .map((t) => {
            const slug = String(t.name || '')
              .toLowerCase()
              .replace(/[^a-z0-9]+/g, '-');
            const rate =
              t.rate_from != null
                ? t.rate_to != null && Number(t.rate_to) !== Number(t.rate_from)
                  ? `${money(t.rate_from)} – ${money(t.rate_to)}`
                  : `${money(t.rate_from)} / night`
                : 'Rate TBA';
            const roomFeatures = t.sample_room_features || '—';
            return `
        <article class="home-room-card home-room-${e(slug)}">
          <div class="home-room-card-top">
            <h3>${e(t.name)}</h3>
            <span class="home-room-rate">${rate}</span>
          </div>
          <p class="muted">${e(t.description || 'Comfortable rooms for your stay.')}</p>
          <ul class="home-room-meta">
            <li><span>Rooms</span><strong>${e(t.room_count || 0)}</strong></li>
            <li><span>Room Features</span><strong>${e(roomFeatures)}</strong></li>
          </ul>
        </article>`;
          })
          .join('');
      } catch (err) {
        roomBox.innerHTML = `<p class="empty">Could not load room types. ${e(err.message)}</p>`;
      }
    }

    async function loadAvailableRooms() {
      if (!availableBox) return;
      try {
        const res = await apiGet('rooms.php', 'getPublicAvailableRooms');
        const rooms = res.data || [];
        if (!rooms.length) {
          availableBox.innerHTML =
            '<p class="empty">No rooms are available right now. Please check back soon or contact the front desk.</p>';
          return;
        }
        availableBox.innerHTML = rooms
          .map((r) => {
            const typeName = r.room_type_name || r.room_type || 'Room';
            const slug = String(typeName)
              .toLowerCase()
              .replace(/[^a-z0-9]+/g, '-');
            return `
        <article class="home-available-card home-room-${e(slug)}">
          <div class="home-available-photo">
            <img src="${e(r.photo_url)}" alt="Room ${e(r.room_number)} · ${e(typeName)}" loading="lazy">
            <span class="home-available-badge">${statusBadge('available')}</span>
          </div>
          <div class="home-available-body">
            <div class="home-available-top">
              <h3>Room ${e(r.room_number)}</h3>
              <span class="home-room-rate">${money(r.rate_per_night)} / night</span>
            </div>
            <p class="muted">${e(typeName)}${r.room_type_description ? ' · ' + e(r.room_type_description) : ''}</p>
            <ul class="home-room-meta">
              <li><span>Floor</span><strong>${e(r.floor ?? '—')}</strong></li>
              <li><span>Capacity</span><strong>${e(r.max_adults ?? '—')} adults · ${e(r.max_children ?? 0)} kids</strong></li>
              <li><span>Room Features</span><strong>${e(r.room_features || '—')}</strong></li>
            </ul>
          </div>
        </article>`;
          })
          .join('');
      } catch (err) {
        availableBox.innerHTML = `<p class="empty">Could not load available rooms. ${e(err.message)}</p>`;
      }
    }

    await Promise.all([loadRoomTypes(), loadAvailableRooms()]);
  }

  function localDateValue(date = new Date()) {
    const pad = (value) => String(value).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
  }

  function appointmentDateLabel(value) {
    if (!value) return '—';
    const date = new Date(`${value}T00:00:00`);
    return Number.isNaN(date.getTime()) ? e(value) : e(date.toLocaleDateString());
  }

  function appointmentDateTimeLabel(date, time) {
    const [hourText, minuteText] = String(time || '').split(':');
    const hour = Number(hourText || 0);
    const period = hour >= 12 ? 'PM' : 'AM';
    const hour12 = hour % 12 || 12;
    return `${appointmentDateLabel(date)} · ${hour12}:${String(minuteText || '00').padStart(2, '0')} ${period}`;
  }

  function appointmentRoomOptions(select, options, selectedId = '') {
    select.innerHTML = '<option value="">Select a room type…</option>' + options.map((option) =>
      `<option value="${e(option.room_type_id)}" data-available="${e(option.available_rooms)}" data-max-adults="${e(option.max_adults_per_room)}" data-max-children="${e(option.max_children_per_room)}">
        ${e(option.room_type)} · ${e(option.available_rooms)} available · from ${money(option.rate_from)}/night
      </option>`
    ).join('');
    select.disabled = options.length === 0;
    if (selectedId && options.some((option) => String(option.room_type_id) === String(selectedId))) {
      select.value = String(selectedId);
    }
  }

  const appointmentAvailabilityRequests = new WeakMap();

  function updateAppointmentDepositEstimate(form) {
    if (form.id !== 'public-appointment-form') return;
    const output = document.getElementById('appointment-deposit-estimate');
    if (!output) return;
    const checkIn = form.elements.check_in.value;
    const checkOut = form.elements.check_out.value;
    const nights = checkIn && checkOut
      ? Math.round((Date.parse(`${checkOut}T00:00:00Z`) - Date.parse(`${checkIn}T00:00:00Z`)) / 86400000)
      : 0;
    const selectedIds = new Set([...form.querySelectorAll('[data-room-id]:checked')].map((input) => String(input.value)));
    const selectedRooms = (form._appointmentRoomOptions || []).filter((room) => selectedIds.has(String(room.id)));
    if (nights < 1 || selectedRooms.length === 0) {
      output.textContent = 'Select rooms and stay dates to calculate your estimated total and deposit.';
      return;
    }
    const estimatedTotal = selectedRooms.reduce((sum, room) => sum + Number(room.rate_per_night || 0), 0) * nights;
    output.innerHTML = `Estimated room total (${nights} night${nights === 1 ? '' : 's'}): <strong>${money(estimatedTotal)}</strong><br>
      25% deposit due now: <strong>${money(Math.round(estimatedTotal * 25) / 100)}</strong>`;
  }

  function updatePublicAppointmentSubmit(form) {
    const submit = form.querySelector('[type="submit"]');
    if (!submit) return;
    const fields = form.elements;
    const field = (name) => fields.namedItem(name);
    if (form.id === 'public-appointment-form') {
      ['first_name', 'last_name'].forEach((name) => {
        const nameField = field(name);
        const errorHint = form.querySelector(`[data-appointment-name-error="${name}"]`);
        if (!nameField || !errorHint) return;
        const validName = /^[\p{L}\p{M}]+(?:[ .'-][\p{L}\p{M}]+)*$/u.test(nameField.value)
          && Array.from(nameField.value).length <= 120;
        const invalidName = Boolean(nameField.value) && !validName;
        nameField.setCustomValidity(invalidName ? errorHint.textContent : '');
        errorHint.hidden = !invalidName;
      });
    }
    updateAppointmentDepositEstimate(form);
    if (form.id === 'public-appointment-change-form' && field('change_type')?.value === 'cancel') {
      submit.disabled = !form.checkValidity();
      return;
    }
    const checkIn = field('check_in')?.value || '';
    const checkOut = field('check_out')?.value || '';
    const checkInTime = field('check_in_time')?.value || '15:00:00';
    const checkOutTime = field('check_out_time')?.value || '14:00:00';
    const start = `${checkIn}T${checkInTime}`;
    const end = `${checkOut}T${checkOutTime}`;
    const checkInIsPast = Boolean(checkIn && checkInTime && start < localStamp());
    const contactMissing = form.id === 'public-appointment-form'
      && ['first_name', 'last_name', 'email', 'phone'].some((name) => !field(name)?.value.trim());
    const roomSelectionInvalid = !form._appointmentSelectionValid;
    const cooldownActive = Number(form.dataset.appointmentCooldownUntil || 0) > Date.now();
    const paymentMethod = form.id === 'public-appointment-form' ? field('payment_method')?.value : '';
    const paymentReference = form.id === 'public-appointment-form' ? field('payment_reference')?.value.trim() || '' : '';
    const paymentReferenceInvalid = form.id === 'public-appointment-form' && !paymentReference;
    submit.disabled = contactMissing
      || !checkIn
      || !checkOut
      || checkInIsPast
      || end <= start
      || (form.id === 'public-appointment-form' && !/@gmail\.com$/i.test(field('email')?.value.trim() || ''))
      || roomSelectionInvalid
      || paymentReferenceInvalid
      || cooldownActive
      || !form.checkValidity();
  }

  function renderAppointmentRoomGallery(form, options) {
    const gallery = form.querySelector('[data-appointment-room-gallery]');
    if (!gallery) return;
    form._appointmentRoomOptions = options;
    if (form.id === 'public-appointment-form' || form.id === 'public-appointment-change-form') {
      const adults = Number(form.elements.adults.value || 0);
      const children = Number(form.elements.children.value || 0);
      const checkIn = form.elements.check_in.value;
      const checkOut = form.elements.check_out.value;
      const selectedIds = new Set(JSON.parse(form.elements.room_ids.value || '[]').map(String));
      gallery.innerHTML = checkIn && checkOut && checkOut > checkIn && adults > 0 && options.length ? `
        <h3>Available individual rooms</h3>
        <button type="button" class="btn btn-secondary btn-sm" data-select-all-rooms>Select all available rooms</button>
        <div class="appointment-room-cards">${options.map((room) => `
          <label class="appointment-room-card${selectedIds.has(String(room.id)) ? ' is-selected' : ''}">
            <input type="checkbox" name="appointment-room-choice" value="${e(room.id)}" data-room-id="${e(room.id)}" ${selectedIds.has(String(room.id)) ? 'checked' : ''}>
            <img src="${e(room.photo_url)}" alt="${e(room.room_type)} room ${e(room.room_number)}" loading="lazy">
            <span class="appointment-room-card-copy">
              <strong>Room ${e(room.room_number)} · ${e(room.room_type)}</strong>
              <span>${money(room.rate_per_night)} / night · up to ${e(room.max_adults)} adults + ${e(room.max_children)} children</span>
              ${room.room_features ? `<small>${e(room.room_features)}</small>` : ''}
              <span class="appointment-room-card-action">${selectedIds.has(String(room.id)) ? 'Selected' : 'Select this room'}</span>
            </span>
          </label>`).join('')}</div>` : '';
      if (!gallery.innerHTML) {
        gallery.innerHTML = `<h3>Choose a room</h3><p class="muted">${checkIn && checkOut && checkOut > checkIn
          ? 'No rooms are currently available for these dates. Try different dates.'
          : 'Select a check-in date and a later check-out date, then check availability to choose a room.'}</p>`;
      }
      updateAppointmentSelection(form);
      return;
    }
    const selected = form.elements.room_type_id.value;
    gallery.innerHTML = options.length ? `
      <div class="appointment-room-cards">
        ${options.map((option) => `<button type="button" class="appointment-room-card${String(option.room_type_id) === String(selected) ? ' is-selected' : ''}" data-appointment-room="${e(option.room_type_id)}" aria-pressed="${String(option.room_type_id) === String(selected)}">
          <img src="${e(option.photo_url)}" alt="${e(option.room_type)} room" loading="lazy">
          <span class="appointment-room-card-copy">
            <strong>${e(option.room_type)}</strong>
            <span>${money(option.rate_from)} / night · ${e(option.available_rooms)} available</span>
            ${option.description ? `<small>${e(option.description)}</small>` : ''}
            ${option.room_features ? `<small>${e(option.room_features)}</small>` : ''}
            <span class="appointment-room-card-action">Choose this room type</span>
          </span>
        </button>`).join('')}
      </div>` : '';
  }

  function updateAppointmentSelection(form) {
    if (!['public-appointment-form', 'public-appointment-change-form'].includes(form.id)) return;
    const adults = Number(form.elements.adults.value || 0);
    const children = Number(form.elements.children.value || 0);
    const options = form._appointmentRoomOptions || [];
    const selectedIds = [...form.querySelectorAll('[data-room-id]:checked')].map((input) => String(input.value));
    const selectedSet = new Set(selectedIds);
    form.querySelectorAll('[data-room-id]').forEach((input) => {
      const selectedRoom = selectedSet.has(String(input.value));
      input.checked = selectedRoom;
      const card = input.closest('.appointment-room-card');
      card?.classList.toggle('is-selected', selectedRoom);
      const action = card?.querySelector('.appointment-room-card-action');
      if (action) action.textContent = selectedRoom ? 'Selected' : 'Select this room';
    });
    const selected = selectedIds.map((id) => options.find((room) => String(room.id) === id)).filter(Boolean);
    const adultCapacity = selected.reduce((total, room) => total + Number(room.max_adults), 0);
    const childCapacity = selected.reduce((total, room) => total + Number(room.max_children), 0);
    const valid = selected.length > 0 && selected.length <= adults && adults <= adultCapacity && children <= childCapacity;
    form._appointmentSelectionValid = valid;
    form.elements.room_ids.value = JSON.stringify(selected.map((room) => Number(room.id)));
    form.elements.room_count.value = String(selected.length);
    const hint = form.querySelector('[data-appointment-availability]');
    if (hint && options.length && adults > 0) {
      hint.textContent = valid
        ? `${selected.length} room(s) selected for ${adults} adult(s) and ${children} child(ren). Availability is checked again by staff.`
        : 'Select enough rooms to fit every guest. Each room requires at least one adult.';
    }
    updatePublicAppointmentSubmit(form);
  }

  async function loadAppointmentAvailability(form, selectedId = '') {
    const requestId = (appointmentAvailabilityRequests.get(form) || 0) + 1;
    appointmentAvailabilityRequests.set(form, requestId);
    const checkIn = form.elements.check_in.value;
    const checkOut = form.elements.check_out.value;
    const typeSelect = form.elements.room_type_id;
    const countInput = form.elements.room_count;
    const hint = form.querySelector('[data-appointment-availability]');
    if (checkIn && checkIn < localDateValue()) {
      if (typeSelect) {
        typeSelect.innerHTML = '<option value="">Select future dates first</option>';
        typeSelect.disabled = true;
      }
      if (countInput && form.id !== 'public-appointment-form') countInput.disabled = true;
      if (form.id === 'public-appointment-form') form.elements.room_ids.value = '[]';
      renderAppointmentRoomGallery(form, []);
      if (hint) hint.textContent = 'Your saved check-in date has passed. Choose a future check-in date to check room availability.';
      updatePublicAppointmentSubmit(form);
      return;
    }
    if (!checkIn || !checkOut || checkOut <= checkIn) {
      if (typeSelect) {
        typeSelect.innerHTML = '<option value="">Select valid dates first</option>';
        typeSelect.disabled = true;
      }
      if (countInput && form.id !== 'public-appointment-form') countInput.disabled = true;
      renderAppointmentRoomGallery(form, []);
      if (hint) hint.textContent = 'Choose a check-in and a later check-out date to check live availability.';
      updatePublicAppointmentSubmit(form);
      return;
    }
    if (hint) hint.textContent = 'Checking available rooms…';
    try {
      const response = await apiGet('appointments.php', 'getPublicAvailability', {
        check_in: checkIn,
        check_out: checkOut,
        appointment_code: form.elements.appointment_code?.value || '',
      });
      if (appointmentAvailabilityRequests.get(form) !== requestId) return;
      const roomTypes = response.data?.room_types || [];
      const rooms = response.data?.rooms || [];
      if (form.id === 'public-appointment-form' && Array.isArray(form._appointmentDraftRoomIds)) {
        const availableIds = new Set(rooms.map((room) => String(room.id)));
        form.elements.room_ids.value = JSON.stringify(
          form._appointmentDraftRoomIds.filter((id) => availableIds.has(String(id)))
        );
        form._appointmentDraftRoomIds = null;
      }
      if (typeSelect) {
        appointmentRoomOptions(typeSelect, roomTypes, selectedId);
        if (countInput) countInput.disabled = typeSelect.disabled;
      } else if (countInput) {
        countInput.disabled = false;
      }
      renderAppointmentRoomGallery(
        form,
        ['public-appointment-form', 'public-appointment-change-form'].includes(form.id) ? rooms : roomTypes
      );
      if (['public-appointment-form', 'public-appointment-change-form'].includes(form.id)) {
        updateAppointmentSelection(form);
      } else {
        if (hint) hint.textContent = roomTypes.length
          ? 'Availability is checked again when staff reviews your request.'
          : 'No rooms are available for those dates. Try different dates.';
        updateAppointmentRoomLimits(form);
      }
      updatePublicAppointmentSubmit(form);
    } catch (error) {
      if (appointmentAvailabilityRequests.get(form) !== requestId) return;
      if (typeSelect) {
        typeSelect.innerHTML = '<option value="">Availability could not be checked</option>';
        typeSelect.disabled = true;
      }
      if (countInput && form.id !== 'public-appointment-form') countInput.disabled = true;
      if (hint) hint.textContent = error.message;
      updatePublicAppointmentSubmit(form);
    }
  }

  function updateAppointmentRoomLimits(form) {
    const typeSelect = form.elements.room_type_id;
    const countInput = form.elements.room_count;
    if (!typeSelect || !countInput) return;
    const option = typeSelect.selectedOptions[0];
    if (!option?.value) {
      countInput.disabled = true;
      countInput.max = '1';
      updatePublicAppointmentSubmit(form);
      return;
    }
    countInput.disabled = false;
    countInput.max = option.dataset.available || '1';
    if (Number(countInput.value) > Number(countInput.max)) countInput.value = countInput.max;
    const adults = form.elements.adults;
    const children = form.elements.children;
    adults.max = String(Number(option.dataset.maxAdults || 1) * Number(countInput.value || 1));
    children.max = String(Number(option.dataset.maxChildren || 0) * Number(countInput.value || 1));
    if (Number(adults.value) > Number(adults.max)) adults.value = adults.max;
    if (Number(children.value) > Number(children.max)) children.value = children.max;
    updatePublicAppointmentSubmit(form);
  }

  function bindAppointmentDateBounds(form) {
    const checkIn = form.elements.check_in;
    const checkOut = form.elements.check_out;
    if (!checkIn || !checkOut) return;
    checkIn.min = localDateValue();
    let lastAvailabilityRange = null;
    const refreshAvailability = () => {
      const range = `${checkIn.value}|${checkOut.value}`;
      updatePublicAppointmentSubmit(form);
      if (range === lastAvailabilityRange) return;
      lastAvailabilityRange = range;
      const expiredDraftNotice = document.getElementById('appointment-alert');
      if (form.id === 'public-appointment-form'
        && checkIn.value >= localDateValue()
        && expiredDraftNotice?.textContent.includes('Your saved check-in date has passed.')) {
        expiredDraftNotice.replaceChildren();
      }
      loadAppointmentAvailability(form);
    };
    const updateCheckoutMinimum = () => {
      if (!checkIn.value) return;
      const nextDay = new Date(`${checkIn.value}T00:00:00`);
      nextDay.setDate(nextDay.getDate() + 1);
      checkOut.min = localDateValue(nextDay);
      if (checkOut.value && checkOut.value < checkOut.min) checkOut.value = '';
    };
    checkIn.addEventListener('change', () => {
      updateCheckoutMinimum();
      refreshAvailability();
    });
    checkOut.addEventListener('change', refreshAvailability);
    form.querySelector('[data-check-appointment-availability]')?.addEventListener('click', () => {
      if (!checkIn.value || !checkOut.value) {
        const hint = form.querySelector('[data-appointment-availability]');
        if (hint) hint.textContent = 'Select both a check-in date and a later check-out date first.';
        checkIn.focus();
        return;
      }
      loadAppointmentAvailability(form);
    });
    const roomType = form.elements.room_type_id;
    roomType?.addEventListener('change', () => {
      updateAppointmentRoomLimits(form);
      renderAppointmentRoomGallery(form, form._appointmentRoomOptions || []);
    });
    form.elements.room_count?.addEventListener('input', () => updateAppointmentRoomLimits(form));
    form.elements.adults?.addEventListener('input', () => {
      if (['public-appointment-form', 'public-appointment-change-form'].includes(form.id)) renderAppointmentRoomGallery(form, form._appointmentRoomOptions || []);
      else updateAppointmentRoomLimits(form);
    });
    form.elements.children?.addEventListener('input', () => {
      if (['public-appointment-form', 'public-appointment-change-form'].includes(form.id)) renderAppointmentRoomGallery(form, form._appointmentRoomOptions || []);
      else updateAppointmentRoomLimits(form);
    });
    form.addEventListener('input', () => updatePublicAppointmentSubmit(form));
    form.addEventListener('change', (event) => {
      if (event.target.closest('[data-time-picker]')) {
        requestAnimationFrame(() => updatePublicAppointmentSubmit(form));
      } else {
        updatePublicAppointmentSubmit(form);
      }
    });
    const roomGallery = form.querySelector('[data-appointment-room-gallery]');
    roomGallery?.addEventListener('change', (event) => {
      if (!['public-appointment-form', 'public-appointment-change-form'].includes(form.id)) return;
      const checkbox = event.target.closest('[data-room-id]');
      if (!checkbox) return;
      updateAppointmentSelection(form);
    });
    roomGallery?.addEventListener('click', (event) => {
      if (['public-appointment-form', 'public-appointment-change-form'].includes(form.id)) {
        if (event.target.closest('[data-select-all-rooms]')) {
          form.querySelectorAll('[data-room-id]').forEach((checkbox) => { checkbox.checked = true; });
          updateAppointmentSelection(form);
        }
        return;
      }
      const card = event.target.closest('[data-appointment-room]');
      if (!card) return;
      const select = form.elements.room_type_id;
      select.value = card.dataset.appointmentRoom;
      select.dispatchEvent(new Event('change', { bubbles: true }));
    });
  }

  async function renderPublicAppointment() {
    const main = document.getElementById('main-div');
    if (!mountPageTemplate(main, 'appointment')) {
      main.innerHTML = '<div class="alert alert-error">Appointment form template missing.</div>';
      return;
    }
    const form = document.getElementById('public-appointment-form');
    const alertBox = document.getElementById('appointment-alert');
    const submitButton = form.querySelector('[type="submit"]');
    const cooldownStatus = document.getElementById('appointment-cooldown-status');
    const draftStorageKey = 'public-appointment-draft-v1';
    const cooldownStorageKey = 'public-appointment-cooldown-until-v1';
    const resultStorageKey = 'public-appointment-result-v1';
    let cooldownTimer = null;
    let storageErrorShown = false;
    const showStorageError = () => {
      if (storageErrorShown) return;
      storageErrorShown = true;
      showAlert(alertBox, 'error', 'This browser could not save appointment details for refresh. Keep this tab open while completing the request.');
    };
    const readSessionValue = (key) => {
      try {
        return window.sessionStorage.getItem(key);
      } catch {
        showStorageError();
        return null;
      }
    };
    const writeSessionValue = (key, value) => {
      try {
        window.sessionStorage.setItem(key, value);
        return true;
      } catch {
        showStorageError();
        return false;
      }
    };
    const removeSessionValue = (key) => {
      try {
        window.sessionStorage.removeItem(key);
      } catch {
        showStorageError();
      }
    };
    const draftFields = [
      'first_name', 'last_name', 'email', 'phone', 'check_in', 'check_in_time',
      'check_out', 'check_out_time', 'adults', 'children', 'payment_method',
      'payment_reference', 'notes',
    ];
    const saveDraft = () => {
      const values = {};
      draftFields.forEach((name) => {
        const field = form.elements.namedItem(name);
        if (!field) return;
        if (field instanceof RadioNodeList) {
          values[name] = [...field].find((option) => option.checked)?.value || '';
        } else {
          values[name] = field.value;
        }
      });
      values.room_ids = [...form.querySelectorAll('[data-room-id]:checked')].map((room) => room.value);
      writeSessionValue(draftStorageKey, JSON.stringify(values));
    };
    const restoreDraft = () => {
      const saved = readSessionValue(draftStorageKey);
      if (!saved) return;
      try {
        const values = JSON.parse(saved);
        if (!values || typeof values !== 'object' || Array.isArray(values)) return;
        draftFields.forEach((name) => {
          if (typeof values[name] !== 'string') return;
          const field = form.elements.namedItem(name);
          if (!field) return;
          if (field instanceof RadioNodeList) {
            [...field].forEach((option) => { option.checked = option.value === values[name]; });
          } else {
            field.value = values[name];
          }
        });
        form._appointmentDraftRoomIds = Array.isArray(values.room_ids)
          ? values.room_ids.map(String)
          : [];
        const checkInPicker = form.elements.check_in_time.closest('[data-time-picker]');
        if (checkInPicker) {
          const minute = checkInPicker.querySelector('[data-tp="m"]');
          if (minute) minute.value = String(parseTimeParts(form.elements.check_in_time.value).m);
          syncTimePicker(checkInPicker);
        }
      } catch {
        removeSessionValue(draftStorageKey);
        showAlert(alertBox, 'error', 'Saved appointment details could not be read and were cleared. Please re-enter them.');
      }
    };
    const showAppointmentResult = (result) => {
      alertBox.innerHTML = `<div class="alert alert-success" role="status">
        <strong>Request submitted.</strong> ${e(result.message)}
        <div class="appointment-code-display"><span>Your appointment code</span><strong>${e(result.data.appointment_code)}</strong></div>
        <p>Estimated room total: <strong>${money(result.data.estimated_total)}</strong><br>
          25% deposit: <strong>${money(result.data.deposit_amount)}</strong> · ${e(result.data.payment_method === 'gcash' ? 'GCash' : 'Bank transfer')} transfer reference is awaiting verification.</p>
        <p>Keep this code private. Your appointment request does not create an account. Give the code to Front Desk to create or link your guest account when you arrive.</p>
        <a class="btn btn-secondary" href="manage-appointment.html">Manage this appointment</a>
      </div>`;
    };
    const setCooldown = (until) => {
      form.dataset.appointmentCooldownUntil = String(until);
      writeSessionValue(cooldownStorageKey, String(until));
      if (cooldownTimer) window.clearTimeout(cooldownTimer);
      const tick = () => {
        const remainingSeconds = Math.max(0, Math.ceil((until - Date.now()) / 1000));
        if (remainingSeconds > 0) {
          const minutes = Math.floor(remainingSeconds / 60);
          const seconds = String(remainingSeconds % 60).padStart(2, '0');
          if (cooldownStatus) cooldownStatus.textContent = `Request submitted. You can submit another appointment in ${minutes}:${seconds}. Your details remain saved in this browser session.`;
          if (submitButton) submitButton.textContent = `Wait ${minutes}:${seconds}`;
          updatePublicAppointmentSubmit(form);
          cooldownTimer = window.setTimeout(tick, 1000);
          return;
        }
        delete form.dataset.appointmentCooldownUntil;
        removeSessionValue(cooldownStorageKey);
        if (cooldownStatus) cooldownStatus.textContent = 'You can submit another appointment request now. Your entered details remain saved in this browser session.';
        if (submitButton) submitButton.textContent = 'Submit appointment request';
        updatePublicAppointmentSubmit(form);
      };
      tick();
    };
    fillTimeSelects(form);
    enhanceDateTimeFields(form);
    restoreDraft();
    if (form.elements.check_in.value && form.elements.check_in.value < localDateValue()) {
      form.elements.check_in.value = '';
      form.elements.check_out.value = '';
      form.elements.room_ids.value = '[]';
      form._appointmentDraftRoomIds = [];
      saveDraft();
      showAlert(alertBox, 'info', 'Your saved check-in date has passed. Choose new future dates to check room availability.');
    }
    const paymentMethod = form.elements.payment_method;
    const paymentReference = form.elements.payment_reference;
    const paymentDestination = document.getElementById('appointment-payment-destination');
    const paymentReferenceHint = document.getElementById('appointment-payment-reference-hint');
    const updatePaymentInstructions = () => {
      const method = paymentMethod.value;
      if (paymentDestination) paymentDestination.innerHTML = paymentDestinationMarkup(method);
      if (paymentReferenceHint) {
        paymentReferenceHint.textContent = 'Enter the transfer reference exactly as shown on your receipt. Letters, numbers, and symbols are accepted.';
      }
      paymentReference.inputMode = 'text';
      paymentReference.placeholder = 'Enter your transfer reference';
      updatePublicAppointmentSubmit(form);
    };
    paymentMethod.forEach((radio) => radio.addEventListener('change', updatePaymentInstructions));
    form._persistAppointmentDraft = saveDraft;
    form.addEventListener('input', saveDraft);
    form.addEventListener('change', saveDraft);
    updatePaymentInstructions();
    bindAppointmentDateBounds(form);
    renderAppointmentRoomGallery(form, []);
    const checkInDate = form.elements.check_in.value;
    if (checkInDate) {
      const minimumCheckout = new Date(`${checkInDate}T00:00:00`);
      minimumCheckout.setDate(minimumCheckout.getDate() + 1);
      form.elements.check_out.min = localDateValue(minimumCheckout);
    }
    updatePublicAppointmentSubmit(form);
    if (form.elements.check_in.value && form.elements.check_out.value) {
      loadAppointmentAvailability(form);
    }
    const savedResult = readSessionValue(resultStorageKey);
    if (savedResult) {
      try {
        const result = JSON.parse(savedResult);
        if (result?.data?.appointment_code && result?.message) showAppointmentResult(result);
      } catch {
        removeSessionValue(resultStorageKey);
      }
    }
    const savedCooldown = Number(readSessionValue(cooldownStorageKey) || 0);
    if (savedCooldown > Date.now()) setCooldown(savedCooldown);
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (!form.reportValidity()) return;
      submitButton.disabled = true;
      showAlert(alertBox, 'info', 'Submitting your appointment request…');
      const payload = Object.fromEntries(new FormData(form));
      ['room_count', 'adults', 'children'].forEach((key) => { payload[key] = Number(payload[key]); });
      payload.room_ids = JSON.parse(payload.room_ids || '[]');
      try {
        const response = await apiPost('appointments.php', 'createPublicAppointment', payload);
        showAppointmentResult(response);
        saveDraft();
        writeSessionValue(resultStorageKey, JSON.stringify(response));
        setCooldown(Date.now() + 5 * 60 * 1000);
      } catch (error) {
        showAlert(alertBox, 'error', error.message);
        updatePublicAppointmentSubmit(form);
      }
    });
  }

  function appointmentDetailsMarkup(appointment) {
    const changePending = appointment.change_status === 'pending';
    const canChange = ['pending', 'confirmed'].includes(appointment.status) && !changePending;
    return `<section class="panel appointment-detail">
      <div class="row-between"><div><h2>${e(appointment.first_name)} ${e(appointment.last_name)}</h2><p class="muted">Appointment code: <strong>${e(appointment.appointment_code)}</strong></p></div>${statusBadge(appointment.status)}</div>
      ${appointment.room_photo_url ? `<img class="appointment-detail-photo" src="${e(appointment.room_photo_url)}" alt="${e(appointment.room_type_name)} room">` : ''}
      <div class="appointment-detail-grid">
        <p><strong>Email</strong><span>${e(appointment.email)}</span></p><p><strong>Phone</strong><span>${e(appointment.phone)}</span></p>
        <p><strong>Room preference</strong><span>${e(appointment.room_type_name)} · ${e(appointment.room_count)} room(s)</span></p>
        <p><strong>Check-in</strong><span>${appointmentDateTimeLabel(appointment.check_in, appointment.check_in_time)}</span></p>
        <p><strong>Check-out</strong><span>${appointmentDateTimeLabel(appointment.check_out, appointment.check_out_time)}</span></p>
        ${appointment.assigned_room_numbers ? `<p><strong>${appointment.status === 'pending' ? 'Requested room(s)' : 'Assigned room(s)'}</strong><span>${e(appointment.assigned_room_numbers)}</span></p>` : ''}
        <p><strong>Guests</strong><span>${e(appointment.adults)} adults · ${e(appointment.children)} children</span></p>
        <p><strong>Estimated room total</strong><span>${money(appointment.estimated_total || 0)}</span></p>
        <p><strong>25% deposit</strong><span>${money(appointment.deposit_amount || 0)} · ${e(appointment.payment_method === 'gcash' ? 'GCash' : appointment.payment_method === 'bank_transfer' ? 'Bank transfer' : 'Not applicable')}</span></p>
        <p><strong>Transfer verification</strong><span>${e(appointment.payment_status || 'not_required')}${appointment.payment_reference ? ` · Ref ${e(appointment.payment_reference)}` : ''}${appointment.payment_reviewed_by_name ? ` · Reviewed by ${e(appointment.payment_reviewed_by_name)}` : ''}</span></p>
        <p><strong>Submitted</strong><span>${e(appointment.created_at)}</span></p>
        ${appointment.reviewed_at ? `<p><strong>Reviewed</strong><span>${e(appointment.reviewed_at)}${appointment.reviewed_by_name ? ` · ${e(appointment.reviewed_by_name)}` : ''}</span></p>` : ''}
      </div>
      ${appointment.notes ? `<p><strong>Appointment description:</strong> ${e(appointment.notes)}</p>` : ''}
      ${appointment.review_note ? `<p class="field-hint"><strong>Staff note:</strong> ${e(appointment.review_note)}</p>` : ''}
      ${appointment.payment_review_note ? `<p class="field-hint"><strong>Transfer review note:</strong> ${e(appointment.payment_review_note)}</p>` : ''}
      <p class="field-hint">This request does not create a guest account. Give the appointment code to Front Desk when you arrive so staff can create or link your account.</p>
      ${appointment.change_id ? `<div class="appointment-change-history"><h3>Latest change request</h3>
        <p>${e(appointment.change_type)} · ${statusBadge(appointment.change_status)}</p>
        ${appointment.current_room_type_name ? `<p><strong>Previous appointment:</strong> ${e(appointment.current_room_type_name)} · ${e(appointment.current_room_count)} room(s), ${appointmentDateTimeLabel(appointment.current_check_in, appointment.current_check_in_time)} – ${appointmentDateTimeLabel(appointment.current_check_out, appointment.current_check_out_time)}</p>` : ''}
        ${appointment.change_type === 'reschedule' ? `<p><strong>Requested appointment:</strong> ${e(appointment.proposed_room_type_name)} · ${e(appointment.proposed_room_count)} room(s), ${appointmentDateTimeLabel(appointment.proposed_check_in, appointment.proposed_check_in_time)} – ${appointmentDateTimeLabel(appointment.proposed_check_out, appointment.proposed_check_out_time)}</p>` : ''}
        ${appointment.change_reason ? `<p>Reason: ${e(appointment.change_reason)}</p>` : ''}
        ${appointment.change_review_note ? `<p>Staff note: ${e(appointment.change_review_note)}</p>` : ''}
        ${appointment.change_reviewed_at ? `<p>Reviewed ${e(appointment.change_reviewed_at)}${appointment.change_reviewed_by_name ? ` by ${e(appointment.change_reviewed_by_name)}` : ''}</p>` : ''}
      </div>` : ''}
      ${canChange ? `<form id="public-appointment-change-form" class="form-stack">
        <h3>Request a change</h3>
        <input type="hidden" name="appointment_code" value="${e(appointment.appointment_code)}">
        <div><label for="appointment-change-type">What would you like to do?</label>
          <select id="appointment-change-type" name="change_type" required><option value="reschedule">Request a reschedule</option><option value="cancel">Request cancellation</option></select></div>
        <div id="appointment-reschedule-fields" class="form-grid appointment-grid">
          <div class="dt-field" data-time-policy="check-in"><label for="appointment-new-check-in">New check-in date and time</label><div class="dt-row"><input id="appointment-new-check-in" name="check_in" type="date" required><select data-time-select data-time-policy="check-in" data-default="15:00" name="check_in_time"></select></div></div>
          <div class="dt-field" data-time-policy="check-out"><label for="appointment-new-check-out">New check-out date and time</label><div class="dt-row"><input id="appointment-new-check-out" name="check_out" type="date" required><select data-time-select data-time-policy="check-out" data-default="14:00" name="check_out_time"></select></div></div>
          <div class="full"><small class="field-hint" data-appointment-availability>Choose new dates, then select one or more available rooms.</small><div class="appointment-room-gallery" data-appointment-room-gallery aria-live="polite"></div></div>
          <input type="hidden" name="room_ids" value="[]">
          <input type="hidden" name="room_count" value="0">
          <div><label for="appointment-new-adults">Adults (total)</label><input id="appointment-new-adults" name="adults" type="number" min="1" max="20" value="${e(appointment.adults)}" required></div>
          <div><label for="appointment-new-children">Children (total)</label><input id="appointment-new-children" name="children" type="number" min="0" max="20" value="${e(appointment.children)}" required></div>
        </div>
        <div><label for="appointment-change-reason">Reason (optional)</label><textarea id="appointment-change-reason" name="reason" rows="2" maxlength="1000"></textarea></div>
        <button class="btn btn-primary" type="submit">Send change request for review</button>
      </form>` : changePending ? '<p class="field-hint">A change request is waiting for staff review. You can submit another after it is reviewed.</p>' : ''}
    </section>`;
  }

  async function renderPublicAppointmentManagement() {
    const main = document.getElementById('main-div');
    if (!mountPageTemplate(main, 'manage-appointment')) {
      main.innerHTML = '<div class="alert alert-error">Appointment management template missing.</div>';
      return;
    }
    const alertBox = document.getElementById('manage-appointment-alert');
    const details = document.getElementById('appointment-details');
    let activeAppointment = null;
    const showAppointment = (appointment) => {
      activeAppointment = appointment;
      details.innerHTML = appointmentDetailsMarkup(appointment);
      details.hidden = false;
      const changeForm = document.getElementById('public-appointment-change-form');
      if (!changeForm) return;
      const rescheduleFields = document.getElementById('appointment-reschedule-fields');
      const changeType = document.getElementById('appointment-change-type');
      const syncChangeType = () => {
        const isReschedule = changeType.value === 'reschedule';
        rescheduleFields.hidden = !isReschedule;
        rescheduleFields.querySelectorAll('input:not([type="hidden"]), select').forEach((input) => { input.required = isReschedule; });
        const roomIds = changeForm.elements.room_ids;
        const roomCount = changeForm.elements.room_count;
        if (roomIds) roomIds.disabled = !isReschedule;
        if (roomCount) roomCount.disabled = !isReschedule;
        updatePublicAppointmentSubmit(changeForm);
      };
      syncChangeType();
      changeType.addEventListener('change', syncChangeType);
      fillTimeSelects(changeForm);
      enhanceDateTimeFields(changeForm);
      bindAppointmentDateBounds(changeForm);
      changeForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = changeForm.querySelector('[type="submit"]');
        button.disabled = true;
        try {
          const payload = Object.fromEntries(new FormData(changeForm));
          ['room_count', 'adults', 'children'].forEach((key) => {
            if (payload[key] !== undefined && payload[key] !== '') payload[key] = Number(payload[key]);
          });
          payload.room_ids = JSON.parse(payload.room_ids || '[]');
          await apiPost('appointments.php', 'requestPublicChange', payload);
          showAlert(alertBox, 'success', 'Your change request is awaiting staff approval.');
          await lookup(activeAppointment.appointment_code);
        } catch (error) {
          showAlert(alertBox, 'error', error.message);
          button.disabled = false;
        }
      });
    };
    const lookup = async (code) => {
      const response = await apiGet('appointments.php', 'getPublicAppointment', { appointment_code: code });
      showAppointment(response.data);
      return response.data;
    };
    document.getElementById('appointment-lookup-form').addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const code = String(form.elements.appointment_code.value || '').trim().toUpperCase();
      form.elements.appointment_code.value = code;
      details.hidden = true;
      try {
        await lookup(code);
        alertBox.replaceChildren();
      } catch (error) {
        showAlert(alertBox, 'error', error.message);
      }
    });
  }

  async function renderStaffAppointments() {
    const alertBox = document.getElementById('appointments-alert');
    const requestBody = document.getElementById('appointment-requests-body');
    const changesBody = document.getElementById('appointment-changes-body');
    const dateRange = (start, end) => `${appointmentDateLabel(start)} – ${appointmentDateLabel(end)}`;
    const load = async () => {
      const response = await apiGet('appointments.php', 'getStaffAppointments');
      const appointments = response.data.appointments || [];
      const changes = response.data.changes || [];
      requestBody.innerHTML = appointments.length ? appointments.map((item) => `<tr>
        <td data-label="Guest / Contact"><strong>${e(item.full_name)}</strong><small>${e(item.email)} · ${e(item.phone)}</small><small>Code: <strong>${e(item.appointment_code)}</strong></small>${item.notes ? `<small class="appointment-description"><strong>Appointment description:</strong> ${e(item.notes)}</small>` : ''}</td>
        <td data-label="Stay / Room">${e(item.room_type_label)} · ${e(item.room_count)} room(s)<small>Check-in: ${appointmentDateTimeLabel(item.check_in, item.check_in_time)}</small><small>Check-out: ${appointmentDateTimeLabel(item.check_out, item.check_out_time)}</small></td>
        <td data-label="Guests">${e(item.adults)} adults · ${e(item.children)} children</td>
        <td data-label="Deposit">${money(item.estimated_total || 0)} estimated total<small>25% due: <strong>${money(item.deposit_amount || 0)}</strong></small><small>${e(item.payment_method === 'gcash' ? 'GCash' : item.payment_method === 'bank_transfer' ? 'Bank transfer' : 'No transfer')}</small>${item.payment_reference ? `<small>Ref: ${e(item.payment_reference)}</small>` : ''}<small>Verification: ${e(item.payment_status || 'not_required')}${item.payment_reviewed_by_name ? ` · ${e(item.payment_reviewed_by_name)}` : ''}</small>${item.payment_review_note ? `<small>${e(item.payment_review_note)}</small>` : ''}${item.payment_status === 'pending' ? `<div class="appointment-review-controls"><input type="text" maxlength="1000" placeholder="Reason if rejecting" aria-label="Transfer review note for ${e(item.appointment_code)}"><button type="button" class="btn btn-primary btn-sm" data-review-payment="${e(item.id)}" data-decision="verified">Verify transfer</button><button type="button" class="btn btn-danger btn-sm" data-review-payment="${e(item.id)}" data-decision="rejected">Reject transfer</button></div>` : ''}</td>
        <td data-label="Status">${statusBadge(item.status)}${item.review_note ? `<small>${e(item.review_note)}</small>` : ''}</td>
        <td data-label="Requested">${e(item.created_at)}</td>
        <td data-label="Review">${document.body.dataset.role === 'admin' ? `<a class="btn btn-secondary btn-sm" href="${BASE_PATH}/admin/appointment-view.html?id=${encodeURIComponent(item.id)}">View request</a>` : ''}${item.status === 'pending' ? item.payment_status === 'verified' || item.payment_status === 'not_required' ? `<div class="appointment-review-controls">
          <input type="text" maxlength="1000" placeholder="Optional note" aria-label="Review note for ${e(item.appointment_code)}">
          <button type="button" class="btn btn-primary btn-sm" data-review-appointment="${e(item.id)}" data-decision="confirmed">Confirm</button>
          <button type="button" class="btn btn-danger btn-sm" data-review-appointment="${e(item.id)}" data-decision="rejected">Reject</button>
        </div>` : `<small>Verify the 25% deposit before confirming.</small><div class="appointment-review-controls"><input type="text" maxlength="1000" placeholder="Optional note" aria-label="Review note for ${e(item.appointment_code)}"><button type="button" class="btn btn-danger btn-sm" data-review-appointment="${e(item.id)}" data-decision="rejected">Reject appointment</button></div>` : e(item.reviewed_by_name ? `Reviewed by ${item.reviewed_by_name}` : '—')}</td>
      </tr>`).join('') : '<tr><td colspan="7" class="empty">No appointment requests yet.</td></tr>';
      changesBody.innerHTML = changes.length ? changes.map((item) => `<tr>
        <td data-label="Guest / Code"><strong>${e(item.full_name)}</strong><small>${e(item.appointment_code)}</small><small>${e(item.email)} · ${e(item.phone)}</small></td>
        <td data-label="Current appointment">${e(item.current_room_type)} · ${e(item.current_room_count)} room(s)<small>Check-in: ${appointmentDateTimeLabel(item.current_check_in, item.current_check_in_time)}</small><small>Check-out: ${appointmentDateTimeLabel(item.current_check_out, item.current_check_out_time)}</small></td>
        <td data-label="Requested change"><strong>${item.change_type === 'cancel' ? 'Cancellation' : 'Reschedule'}</strong>
          ${item.change_type === 'reschedule' ? `<small>${e(item.proposed_room_type_label)} · ${e(item.proposed_room_count)} room(s)</small><small>Check-in: ${appointmentDateTimeLabel(item.proposed_check_in, item.proposed_check_in_time)} · Check-out: ${appointmentDateTimeLabel(item.proposed_check_out, item.proposed_check_out_time)}</small><small>${e(item.proposed_adults)} adults · ${e(item.proposed_children)} children</small>` : ''}
          ${item.reason ? `<small>Reason: ${e(item.reason)}</small>` : ''}</td>
        <td data-label="Status">${statusBadge(item.status)}${item.review_note ? `<small>${e(item.review_note)}</small>` : ''}</td>
        <td data-label="Review">${item.status === 'pending' ? `<div class="appointment-review-controls">
          <input type="text" maxlength="1000" placeholder="Optional note" aria-label="Review note for change request ${e(item.id)}">
          <button type="button" class="btn btn-primary btn-sm" data-review-change="${e(item.id)}" data-decision="accepted">Accept</button>
          <button type="button" class="btn btn-danger btn-sm" data-review-change="${e(item.id)}" data-decision="rejected">Reject</button>
        </div>` : e(item.reviewed_by_name ? `Reviewed by ${item.reviewed_by_name}` : '—')}</td>
      </tr>`).join('') : '<tr><td colspan="5" class="empty">No cancellation or reschedule requests yet.</td></tr>';
    };
    try {
      await load();
    } catch (error) {
      showAlert(alertBox, 'error', error.message);
    }
    const handleReviewClick = (body) => body.addEventListener('click', async (event) => {
      const appointmentButton = event.target.closest('[data-review-appointment]');
      const changeButton = event.target.closest('[data-review-change]');
      const paymentButton = event.target.closest('[data-review-payment]');
      const button = paymentButton || appointmentButton || changeButton;
      if (!button || !body.contains(button)) return;
      const note = button.parentElement.querySelector('input')?.value || '';
      if (paymentButton && button.dataset.decision === 'rejected' && !note.trim()) {
        showAlert(alertBox, 'error', 'Enter a reason before rejecting a transfer.');
        return;
      }
      const operation = paymentButton ? 'reviewAppointmentPayment' : appointmentButton ? 'reviewAppointment' : 'reviewChangeRequest';
      const id = button.dataset.reviewPayment || button.dataset.reviewAppointment || button.dataset.reviewChange;
      button.disabled = true;
      try {
        await apiPost('appointments.php', operation, { id: Number(id), decision: button.dataset.decision, review_note: note });
        showAlert(alertBox, 'success', paymentButton ? 'Appointment transfer review saved.' : 'Appointment review saved.');
        await load();
      } catch (error) {
        showAlert(alertBox, 'error', error.message);
        button.disabled = false;
      }
    });
    handleReviewClick(requestBody);
    handleReviewClick(changesBody);
  }

  async function renderAdminAppointmentView(main) {
    if (!mountPageTemplate(main)) {
      main.innerHTML = '<div class="alert alert-error">Appointment details template missing.</div>';
      return;
    }
    const alertBox = document.getElementById('appointment-view-alert');
    const detail = document.getElementById('appointment-view-details');
    const appointmentId = Number(qs('id'));
    if (!Number.isInteger(appointmentId) || appointmentId <= 0) {
      showAlert(alertBox, 'error', 'Choose an appointment from the request list to view its details.');
      return;
    }
    try {
      const response = await apiGet('appointments.php', 'getStaffAppointments');
      const appointment = (response.data.appointments || []).find((item) => Number(item.id) === appointmentId);
      if (!appointment) {
        showAlert(alertBox, 'error', 'This appointment request could not be found.');
        return;
      }
      detail.innerHTML = `
        <section class="panel">
          <div class="row-between"><div><h2>${e(appointment.full_name)}</h2><p class="muted">Appointment code: <strong>${e(appointment.appointment_code)}</strong></p></div>${statusBadge(appointment.status)}</div>
          <div class="appointment-detail-grid">
            <p><strong>Email</strong><span>${e(appointment.email)}</span></p>
            <p><strong>Phone</strong><span>${e(appointment.phone)}</span></p>
            <p><strong>Requested rooms</strong><span>${e(appointment.room_type_label || appointment.room_type_name)} · ${e(appointment.room_count)} room(s)${appointment.room_numbers ? `<br>Room ${e(appointment.room_numbers)}` : ''}</span></p>
            <p><strong>Check-in</strong><span>${appointmentDateTimeLabel(appointment.check_in, appointment.check_in_time)}</span></p>
            <p><strong>Check-out</strong><span>${appointmentDateTimeLabel(appointment.check_out, appointment.check_out_time)}</span></p>
            <p><strong>Guests</strong><span>${e(appointment.adults)} adults · ${e(appointment.children)} children</span></p>
            <p><strong>Estimated total</strong><span>${money(appointment.estimated_total || 0)}</span></p>
            <p><strong>25% deposit</strong><span>${money(appointment.deposit_amount || 0)}</span></p>
            <p><strong>Payment</strong><span>${e(appointment.payment_method === 'gcash' ? 'GCash' : appointment.payment_method === 'bank_transfer' ? 'Bank transfer' : 'Not applicable')} · ${e(appointment.payment_status || 'not_required')}</span></p>
            <p><strong>Transfer reference</strong><span>${e(appointment.payment_reference || '—')}</span></p>
            <p><strong>Requested</strong><span>${e(appointment.created_at || '—')}</span></p>
            <p><strong>Reviewed by</strong><span>${e(appointment.reviewed_by_name || 'Not reviewed')}</span></p>
          </div>
          ${appointment.notes ? `<p><strong>Appointment description</strong><br>${e(appointment.notes)}</p>` : ''}
          ${appointment.review_note ? `<p class="field-hint"><strong>Staff note:</strong> ${e(appointment.review_note)}</p>` : ''}
          ${appointment.payment_review_note ? `<p class="field-hint"><strong>Transfer note:</strong> ${e(appointment.payment_review_note)}</p>` : ''}
        </section>`;
    } catch (error) {
      showAlert(alertBox, 'error', error.message);
    }
  }

  async function pageLogin() {
    const main = document.getElementById('main-div');
    applyPublicPhotoBg();
    const prefill = qs('email');
    const auto = qs('auto') === '1';
    const demos = DEMO_ACCOUNTS;
    const prefillDemo = demos.find((d) => d.email === prefill);

    if (!mountPageTemplate(main, 'login')) {
      main.innerHTML = '<div class="alert alert-error">Login page template missing.</div>';
      return;
    }

    const flash = document.getElementById('flash');
    const emailInput = document.getElementById('login-email');
    const passwordInput = document.getElementById('login-password');
    if (prefill) emailInput.value = prefill;
    if (prefillDemo?.password) passwordInput.value = prefillDemo.password;

    document.getElementById('login-demo-actions').innerHTML = demos
      .map(
        (d) => `
          <button type="button" class="btn btn-secondary login-demo-btn" data-email="${e(d.email)}" data-password="${e(d.password)}">
            <span class="demo-role">${e(d.label)}</span>
            <span class="login-demo-email">${e(d.email)}</span>
          </button>`
      )
      .join('');

    async function doLogin(email, password, btn) {
      if (btn) {
        btn.disabled = true;
        btn.classList.add('is-loading');
      }
      showAlert(flash, 'info', 'Signing in…');
      try {
        await loginWithAccount(email, password);
      } catch (err) {
        showAlert(flash, 'error', err.message);
        if (btn) {
          btn.disabled = false;
          btn.classList.remove('is-loading');
        }
      }
    }

    document.getElementById('login-form').addEventListener('submit', async (ev) => {
      ev.preventDefault();
      await doLogin(emailInput.value.trim(), passwordInput.value, ev.submitter);
    });

    document.querySelectorAll('.login-demo-btn').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const email = btn.getAttribute('data-email') || '';
        const password = btn.getAttribute('data-password') || '';
        emailInput.value = email;
        passwordInput.value = password;
        await doLogin(email, password, btn);
      });
    });

    if (auto && prefillDemo?.password) {
      await doLogin(prefillDemo.email, prefillDemo.password, null);
    }
  }

  async function pageRegister() {
    window.location.href = `${BASE_PATH}/login.html`;
  }

  /* ---------- Shared table helpers ---------- */

  function tableWrap(html) {
    return `<div class="table-wrap"><table>${html}</table></div>`;
  }

  const TABLE_PAGE_SIZE = 10;

  /**
   * Paginate an array of row HTML strings into a tbody.
   * @param {HTMLElement} tbody
   * @param {string[]} rowHtmls - one HTML string per <tr>
   * @param {{ emptyHtml?: string, pageSize?: number, pagerId?: string, onRender?: (tbody: HTMLElement, page: number) => void }} options
   */
  function paginateTable(tbody, rowHtmls, options = {}) {
    if (!tbody) return;
    const pageSize = options.pageSize || TABLE_PAGE_SIZE;
    const emptyHtml = options.emptyHtml || '<tr><td class="empty" colspan="99">No data.</td></tr>';
    const wrap = tbody.closest('.table-wrap') || tbody.parentElement;
    let pager = wrap?.parentElement?.querySelector(`:scope > .table-pager[data-for="${tbody.id}"]`)
      || document.getElementById((tbody.id || 'table') + '-pager');
    if (!pager) {
      pager = document.createElement('div');
      pager.className = 'table-pager';
      if (tbody.id) {
        pager.id = tbody.id + '-pager';
        pager.setAttribute('data-for', tbody.id);
      }
      if (wrap?.parentElement) wrap.after(pager);
      else tbody.closest('table')?.after(pager);
    }

    let page = 1;
    const rows = Array.isArray(rowHtmls) ? rowHtmls.filter(Boolean) : [];

    function paint() {
      const total = rows.length;
      if (!total) {
        tbody.innerHTML = emptyHtml;
        pager.hidden = true;
        pager.innerHTML = '';
        options.onRender?.(tbody, 1);
        return;
      }
      const pages = Math.max(1, Math.ceil(total / pageSize));
      if (page > pages) page = pages;
      if (page < 1) page = 1;
      const start = (page - 1) * pageSize;
      const slice = rows.slice(start, start + pageSize);
      tbody.innerHTML = slice.join('');
      pager.hidden = false;
      const from = start + 1;
      const to = Math.min(start + pageSize, total);
      pager.innerHTML = `
      <p class="table-pager-info">Showing <strong>${from}–${to}</strong> of <strong>${total}</strong></p>
      <div class="table-pager-actions">
        <button type="button" class="btn btn-secondary btn-sm" data-pager-back ${page <= 1 ? 'disabled' : ''}>Back</button>
        <span class="table-pager-page">Page ${page} / ${pages}</span>
        <button type="button" class="btn btn-secondary btn-sm" data-pager-next ${page >= pages ? 'disabled' : ''}>Next</button>
      </div>`;
      pager.querySelector('[data-pager-back]')?.addEventListener('click', () => {
        page -= 1;
        paint();
      });
      pager.querySelector('[data-pager-next]')?.addEventListener('click', () => {
        page += 1;
        paint();
      });
      options.onRender?.(tbody, page);
    }

    paint();
  }

  function setStatValues(container, values) {
    if (!container) return;
    const vals = container.querySelectorAll('.value');
    values.forEach((v, i) => {
      if (vals[i]) vals[i].innerHTML = v;
    });
  }

  function fillFormValues(form, data) {
    if (!form || !data) return;
    Object.entries(data).forEach(([name, val]) => {
      const el = form.elements[name];
      if (!el) return;
      if (el.type === 'checkbox') el.checked = !!val;
      else el.value = val ?? '';
    });
  }

  /* ---------- Admin / Front Desk / Guest page renderers ---------- */

  async function renderAdminDashboard(main) {
    mountPageTemplate(main);
    const scheduleMessage = sessionStorage.getItem('room-schedule-flash');
    if (scheduleMessage) {
      showAlert(document.getElementById('flash'), 'success', scheduleMessage);
      sessionStorage.removeItem('room-schedule-flash');
    }
    const res = await apiGet('dashboard.php', 'adminStats');
    const s = res.data;
    setStatValues(document.getElementById('admin-stats'), [
      String(s.users),
      String(s.rooms),
      money(s.revenue),
      money(s.outstanding),
    ]);
    const tbody = document.getElementById('admin-recent-bills-tbody');
    if (tbody) {
      const rowHtmls = (s.recent_bills || []).map(
        (b) => `<tr>
      <td>${e(b.invoice_number)}</td><td>${e(b.guest_name || b.full_name || '')}</td>
      <td>${money(b.total_amount)}</td><td>${money(b.balance)}</td><td>${statusBadge(b.status)}</td>
    </tr>`
      );
      paginateTable(tbody, rowHtmls, { emptyHtml: '<tr><td colspan="5" class="empty">No bills yet.</td></tr>' });
    }
  }

  async function renderFrontdeskDashboard(main) {
    mountPageTemplate(main);
    const res = await apiGet('dashboard.php', 'frontdeskStats');
    const s = res.data;
    const set = (id, val) => {
      const el = document.getElementById(id);
      if (el) el.textContent = val;
    };
    set('fd-stat-checked-in', String(s.checked_in));
    set('fd-stat-reserved', String(s.reserved));
    set('fd-stat-pending-bills', String(s.pending_bills));
    set('fd-stat-today-pay', money(s.today_pay));
    const tbody = document.getElementById('fd-stays-tbody');
    if (tbody) {
      const rowHtmls = (s.stays || []).map(
        (x) => `<tr>
        <td>${e(x.full_name)}</td>
        <td>${e(x.room_number)} · ${e(x.room_type)}</td>
        <td>${fmtStayPoint(x.check_in, x.check_in_time)} → ${fmtStayPoint(x.check_out, x.check_out_time)}</td>
        <td>${statusBadge(x.status)}</td>
      </tr>`
      );
      paginateTable(tbody, rowHtmls, { emptyHtml: '<tr><td colspan="4" class="empty">No active bookings.</td></tr>' });
    }
  }

  async function renderGuestDashboard(main) {
    mountPageTemplate(main);
    const res = await apiGet('dashboard.php', 'guestStats');
    const s = res.data;
    const bill = s.active_bill;
    const booking = s.booking;
    const stays = s.stays || [];
    setStatValues(document.getElementById('guest-dash-kpis'), [
      money(bill?.balance ?? 0),
      bill ? statusBadge(bill.status) : statusBadge('pending'),
      `${stays.filter((stay) => ['reserved', 'checked_in'].includes(stay.status)).length} active room${stays.filter((stay) => ['reserved', 'checked_in'].includes(stay.status)).length === 1 ? '' : 's'}`,
    ]);
    const roomTarget = document.getElementById('guest-stay-rooms');
    if (roomTarget) {
      const roomCards = stays.map((stay) => {
        const requestable = stay.status === 'checked_in';
        const requests = stay.requests || [];
        const requestList = requests.length
          ? `<div class="guest-stay-room-requests"><strong>Room requests</strong>${requests.map((request) => `
            <div class="guest-stay-request"><span>${e(request.item_name)} × ${e(quantityLabel(request.quantity))}</span>${statusBadge(request.status)}</div>`).join('')}</div>`
          : '<p class="muted guest-no-room-requests">No requested services for this room yet.</p>';
        return `<article class="guest-stay-room-card">
          ${stay.photo_url
            ? `<img src="${e(stay.photo_url)}" alt="Room ${e(stay.room_number)}" loading="lazy">`
            : '<div class="billing-photo-fallback">Room photo unavailable</div>'}
          <div class="guest-stay-room-copy">
            <div class="row-between"><strong>Room ${e(stay.room_number)} · ${e(stay.room_type)}</strong>${statusBadge(stay.status)}</div>
            <p>${fmtStayPoint(stay.check_in, stay.check_in_time)} → ${fmtStayPoint(stay.check_out, stay.check_out_time)}</p>
            ${requestList}
            ${requestable
              ? `<a class="btn btn-primary btn-sm" href="requests.html?booking_id=${encodeURIComponent(stay.id)}">Request service for this room</a>`
              : '<span class="muted">Requests are available while you are checked in.</span>'}
          </div>
        </article>`;
      }).join('');
      roomTarget.innerHTML = roomCards || '<p class="empty">No rooms are linked to your guest account yet.</p>';
    }
  }

  async function renderRoomTypes(main) {
    mountPageTemplate(main);
    const res = await apiGet('room_types.php', 'getAllRoomTypes');
    const types = res.data || [];
    const flash = document.getElementById('flash');
    const list = document.getElementById('rtype-list');
    const pager = document.getElementById('rtype-pager');

    mountFeaturePickers(main);
    const bindRtypeCards = (scope) => {
      mountFeaturePickers(scope);
      scope.querySelectorAll('.rtype-edit').forEach((form) => {
        form.addEventListener('submit', async (ev) => {
          ev.preventDefault();
          const payload = Object.fromEntries(new FormData(form).entries());
          payload.id = form.getAttribute('data-id');
          try {
            await apiPost('room_types.php', 'updateRoomType', payload);
            location.reload();
          } catch (err) {
            showAlert(flash, 'error', err.message);
          }
        });
      });
      scope.querySelectorAll('[data-delete-rtype]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          if (!window.confirm(btn.getAttribute('data-confirm') || 'Delete?')) return;
          try {
            await apiPost('room_types.php', 'deleteRoomType', { id: btn.getAttribute('data-delete-rtype') });
            location.reload();
          } catch (err) {
            showAlert(flash, 'error', err.message);
          }
        });
      });
    };

    const cardHtml = (t) => {
      const slug = String(t.name || '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');
      return `
      <article class="rtype-card home-room-${e(slug || 'type')}">
        <div class="rtype-card-top">
          <div>
            <p class="rtype-kicker">Room type</p>
            <h3>${e(t.name)}</h3>
          </div>
          <div class="rtype-card-rate">${money(t.base_rate)}<span>/ night</span></div>
        </div>
        <p class="rtype-desc">${e(t.description || 'No description yet.')}</p>
        <ul class="home-room-meta">
          <li><span>Features</span><strong>${e(t.room_features || '—')}</strong></li>
          <li><span>Status</span><strong>${statusBadge(t.status)}</strong></li>
        </ul>
        <form class="rtype-edit" data-id="${t.id}">
          <div class="room-edit-grid">
            <label>Name<input name="name" value="${e(t.name)}" required></label>
            <label>Base rate<input type="number" step="0.01" name="base_rate" value="${e(t.base_rate)}" required></label>
            <div class="room-edit-full"><span class="dt-label">Features</span>${featurePicker('room_features', t.room_features)}</div>
            <label class="room-edit-full">Description<input name="description" value="${e(t.description || '')}" placeholder="Description"></label>
            <label>Status
              <select name="status">
                <option value="active" ${t.status === 'active' ? 'selected' : ''}>Active</option>
                <option value="inactive" ${t.status === 'inactive' ? 'selected' : ''}>Inactive</option>
              </select>
            </label>
          </div>
          <div class="actions room-edit-actions">
            <button class="btn btn-secondary btn-sm" type="submit">Save</button>
            <button class="btn btn-danger btn-sm" type="button" data-delete-rtype="${t.id}" data-confirm="Delete ${e(t.name)}?">Delete</button>
          </div>
        </form>
      </article>`;
    };

    if (list) {
      const cards = types.map(cardHtml);
      if (!cards.length) {
        list.innerHTML = '<p class="empty">No room types yet. Add Standard, Deluxe, or Suite above.</p>';
        if (pager) {
          pager.hidden = true;
          pager.innerHTML = '';
        }
      } else {
        // Reuse paginateTable pattern via a lightweight card pager
        const pageSize = TABLE_PAGE_SIZE;
        let page = 1;
        const paint = () => {
          const pages = Math.max(1, Math.ceil(cards.length / pageSize));
          if (page > pages) page = pages;
          const start = (page - 1) * pageSize;
          const slice = cards.slice(start, start + pageSize);
          list.innerHTML = slice.join('');
          bindRtypeCards(list);
          if (pager) {
            pager.hidden = false;
            const from = start + 1;
            const to = Math.min(start + pageSize, cards.length);
            pager.innerHTML = `
              <p class="table-pager-info">Showing <strong>${from}–${to}</strong> of <strong>${cards.length}</strong></p>
              <div class="table-pager-actions">
                <button type="button" class="btn btn-secondary btn-sm" data-pager-back ${page <= 1 ? 'disabled' : ''}>Back</button>
                <span class="table-pager-page">Page ${page} / ${pages}</span>
                <button type="button" class="btn btn-secondary btn-sm" data-pager-next ${page >= pages ? 'disabled' : ''}>Next</button>
              </div>`;
            pager.querySelector('[data-pager-back]')?.addEventListener('click', () => {
              page -= 1;
              paint();
            });
            pager.querySelector('[data-pager-next]')?.addEventListener('click', () => {
              page += 1;
              paint();
            });
          }
        };
        paint();
      }
    }

    document.getElementById('rtype-create')?.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      try {
        await apiPost('room_types.php', 'createRoomType', Object.fromEntries(new FormData(ev.target).entries()));
        const roleFolder = document.body.dataset.role === 'admin' ? 'admin' : 'frontdesk';
        sessionStorage.setItem('room-schedule-flash', r.message || 'Stay reserved.');
        location.href = `${BASE_PATH}/${roleFolder}/room-management.html?date=${encodeURIComponent(payload.check_in)}`;
      } catch (err) {
        showAlert(flash, 'error', err.message);
      }
    });
  }

  async function renderRequestedItems(main, options = {}) {
    if (!options.skipMount) mountPageTemplate(main);
    const res = await apiGet('requests.php', 'getStaffPage');
    if (options.isCurrent && !options.isCurrent()) return res.data;
    const all = res.data.requests || [];
    const assignableStaff = res.data.assignable_staff || [];
    const typeLabel = { extra: 'Amenities', food: 'Food', laundry: 'Laundry' };
    const typeBadge = { extra: 'badge-muted', food: 'badge-warn', laundry: 'badge-info' };
    const fmtStamp = (v) => {
      if (!v) return '—';
      const [d, t] = String(v).split(' ');
      return `${e(d)}<div class="cell-meta">${e(fmtTime((t || '').slice(0, 5)))}</div>`;
    };

    const summary = document.getElementById('req-summary');
    if (summary) {
      const count = (s) => all.filter((r) => r.status === s).length;
      summary.innerHTML = [
        ['Total', all.length],
        ['Pending', count('pending')],
        ['Preparing', count('preparing')],
        ['Ready', count('ready')],
        ['To be delivered', count('to_be_delivered')],
        ['Delivered', count('delivered')],
        ['Cancelled', count('cancelled')],
      ].map(([label, n]) => `<div class="req-stat"><span>${label}</span><strong>${n}</strong></div>`).join('');
    }

    const tbody = document.getElementById('requested-items-tbody');
    const paint = () => {
      const type = document.getElementById('req-filter-type')?.value || '';
      const status = document.getElementById('req-filter-status')?.value || '';
      const q = (document.getElementById('req-filter-q')?.value || '').trim().toLowerCase();
      const rows = all
        .filter((r) => (!type || r.request_type === type) && (!status || r.status === status))
        .filter((r) => !q || [r.guest_name, r.item_name, r.room_number, r.notes].join(' ').toLowerCase().includes(q))
        .map((r) => {
          const requiresAssignment = ['extra', 'laundry'].includes(r.request_type);
          const assignment = r.item_unavailable_at
            ? '<span class="muted">Catalog item unavailable; retained in history.</span>'
            : !requiresAssignment
            ? '<span class="muted">Kitchen queue</span>'
            : r.status !== 'pending'
              ? `<strong>${e(r.assigned_to_name || 'Not assigned')}</strong>`
              : assignableStaff.length
                ? `<form class="form-stack request-assignment" data-request-id="${r.id}">
                    <select name="assigned_to" aria-label="Assign ${e(r.item_name)} to Amenities and Laundry Staff" required>
                      <option value="">Choose staff</option>
                      ${assignableStaff.map((staff) => `<option value="${staff.id}" ${Number(staff.id) === Number(r.assigned_to) ? 'selected' : ''}>${e(staff.full_name)}</option>`).join('')}
                    </select>
                    <button class="btn btn-secondary btn-sm" type="submit">${r.assigned_to ? 'Reassign' : 'Assign'}</button>
                  </form>`
                : '<span class="muted">No active Amenities & Laundry Staff account.</span>';
          return `<tr>
          <td>${fmtStamp(r.created_at)}</td>
          <td><strong>${e(r.guest_name)}</strong><div class="cell-meta">Room ${e(r.room_number || '—')}${r.guest_phone ? ' · ' + e(r.guest_phone) : ''}</div></td>
          <td>${r.photo_url ? `<img class="request-thumb" src="${e(r.photo_url)}" alt="${e(r.item_name)}" loading="lazy">` : ''}<strong>${e(r.item_name)}</strong><div><span class="badge ${typeBadge[r.request_type] || 'badge-muted'}">${e(typeLabel[r.request_type] || r.request_type)}</span></div>${r.notes ? `<div class="cell-meta">${e(r.notes)}</div>` : ''}</td>
          <td>${e(quantityLabel(r.quantity))}</td>
          <td>${money((Number(r.quantity) || 0) * (Number(r.unit_price) || 0))}${Number(r.billed) ? '<div class="cell-meta">Billed</div>' : ''}</td>
          <td>${statusBadge(r.status)}</td>
          <td>${assignment}${r.assigned_by_name ? `<div class="cell-meta">Assigned by ${e(r.assigned_by_name)}</div>` : ''}${r.prepared_by_name ? `<div class="cell-meta">Prepared by ${e(r.prepared_by_name)}</div>` : ''}${r.confirmed_by_name ? `<div class="cell-meta">Guest confirmed delivery</div>` : ''}</td>
          <td>${r.handled_at ? fmtStamp(r.handled_at) : '—'}</td>
        </tr>`;
        });
      paginateTable(tbody, rows, {
        emptyHtml: '<tr><td colspan="8" class="empty">No requested items match.</td></tr>',
        onRender: (table) => {
          table.querySelectorAll('.request-assignment').forEach((form) => {
            form.addEventListener('submit', async (event) => {
              event.preventDefault();
              try {
                await apiPost('requests.php', 'assignRequest', {
                  id: form.dataset.requestId,
                  assigned_to: form.elements.assigned_to.value,
                });
                location.reload();
              } catch (error) {
                showAlert(document.getElementById('flash'), 'error', error.message);
              }
            });
          });
        },
      });
    };
    paint();
    ['req-filter-type', 'req-filter-status'].forEach((id) => document.getElementById(id)?.addEventListener('change', paint));
    document.getElementById('req-filter-q')?.addEventListener('input', paint);
    return res.data;
  }
  async function renderCombinedRequests(main, session) {
    const staffData = await renderRequestedItems(main);
    await renderAdditionalItems(main, session, { skipMount: true, data: staffData });
  }

  async function renderAdditionalItems(main, session, options = {}) {
    if (!options.skipMount) mountPageTemplate(main);
    const isAdmin = session?.user?.role === 'admin' || session?.role === 'admin';
    const staffData = options.data || (await apiGet('requests.php', 'getStaffPage')).data;
    const { requests, catalog, can_manage_catalog } = staffData;
    const manageCatalog = isAdmin && can_manage_catalog;

    const statusOptions = (current) =>
      ['pending', 'completed', 'cancelled']
        .map((s) => `<option value="${s}" ${s === current ? 'selected' : ''}>${s}</option>`)
        .join('');

    const flash = document.getElementById('flash');
    const bindReqStatusRows = (tb) => {
      tb.querySelectorAll('.req-status').forEach((form) => {
        form.addEventListener('submit', async (ev) => {
          ev.preventDefault();
          const payload = Object.fromEntries(new FormData(form).entries());
          payload.id = form.getAttribute('data-id');
          try {
            await apiPost('requests.php', 'updateRequestStatus', payload);
            location.reload();
          } catch (err) {
            showAlert(flash, 'error', err.message);
          }
        });
      });
    };
    const bindItemEditRows = (tb) => {
      tb.querySelectorAll('.item-edit').forEach((form) => {
        form.addEventListener('submit', async (ev) => {
          ev.preventDefault();
          const payload = Object.fromEntries(new FormData(form).entries());
          payload.id = form.getAttribute('data-id');
          try {
            await apiPost('additional_items.php', 'updateItem', payload);
            location.reload();
          } catch (err) {
            showAlert(flash, 'error', err.message);
          }
        });
      });
      tb.querySelectorAll('[data-delete-item]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          if (!window.confirm(btn.getAttribute('data-confirm') || 'Delete?')) return;
          try {
            await apiPost('additional_items.php', 'deleteItem', { id: btn.getAttribute('data-delete-item') });
            location.reload();
          } catch (err) {
            showAlert(flash, 'error', err.message);
          }
        });
      });
    };

    const reqTbody = document.getElementById('req-staff-tbody');
    if (reqTbody) {
      const rowHtmls = (requests || []).map(
        (r) => `<tr>
      <td><strong>${e(r.guest_name)}</strong><div class="muted">Room ${e(r.room_number || '—')}</div></td>
      <td>
        ${r.photo_url ? `<img class="request-thumb" src="${e(r.photo_url)}" alt="${e(r.item_name)}" loading="lazy">` : ''}<strong>${e(r.item_name)}</strong>
        <div class="muted">${e(({ extra: 'Amenities', food: 'Food', laundry: 'Laundry' })[r.request_type] || r.request_type)} · ${e(r.notes || '')}</div>
      </td>
      <td>${e(quantityLabel(r.quantity))}</td>
      <td>${money((Number(r.quantity) || 0) * (Number(r.unit_price) || 0))}</td>
      <td>${statusBadge(r.status)}${Number(r.billed) ? ' <span class="muted">charged</span>' : ''}${r.item_unavailable_at ? `<div class="cell-meta"><strong>${r.item_unavailable_reason === 'deleted' ? 'Removed from catalog' : 'Marked inactive'}${r.item_unavailable_by_name ? ` by ${e(r.item_unavailable_by_name)}` : ' by staff'}</strong> · history retained; do not fulfill</div>` : ''}</td>
      <td>
        <span class="muted">${r.assigned_to_name ? `Assigned to ${e(r.assigned_to_name)}` : r.request_type === 'food' ? 'Kitchen queue' : 'Not assigned'}${r.assigned_by_name ? ` · Assigned by ${e(r.assigned_by_name)}` : ''}${r.prepared_by_name ? ` · Prepared by ${e(r.prepared_by_name)}` : ''}${r.confirmed_by_name ? ` · Guest confirmed delivery` : ''}</span>
      </td>
    </tr>`
      );
      paginateTable(reqTbody, rowHtmls, {
        emptyHtml: '<tr><td colspan="6" class="empty">No guest requests yet.</td></tr>',
        onRender: bindReqStatusRows,
      });
    }

    const catalogTbody = document.getElementById('item-catalog-tbody');
    const catalogSplit = main.querySelector('.split');
    const createPanel = document.getElementById('item-create-panel');
    if (createPanel) createPanel.hidden = !manageCatalog;
    if (manageCatalog && catalogTbody) {
      const rowHtmls = (catalog || []).map(
        (it) => `<tr>
        <td><strong>${e(it.item_name)}</strong><div class="muted">${e(it.description || '')}</div></td>
        <td>${money(it.unit_price)}</td>
        <td>${statusBadge(it.status)}</td>
        <td>
          <form class="form-stack item-edit" data-id="${it.id}">
            <input name="item_name" value="${e(it.item_name)}" required>
            <input type="number" step="0.01" name="unit_price" value="${e(it.unit_price)}" required>
            <input name="description" value="${e(it.description || '')}" placeholder="Description">
            <select name="status">
              <option value="active" ${it.status === 'active' ? 'selected' : ''}>Active</option>
              <option value="inactive" ${it.status === 'inactive' ? 'selected' : ''}>Inactive</option>
            </select>
            <div class="actions">
              <button class="btn btn-secondary btn-sm" type="submit">Save</button>
              ${it.status === 'active' ? `<button class="btn btn-danger btn-sm" type="button" data-delete-item="${it.id}" data-confirm="Archive ${e(it.item_name)}? Existing guest requests and transaction history will be retained.">Archive</button>` : '<span class="muted">Archived</span>'}
            </div>
          </form>
        </td>
      </tr>`
      );
      paginateTable(catalogTbody, rowHtmls, {
        emptyHtml: '<tr><td colspan="4" class="empty">No additional items yet.</td></tr>',
        onRender: bindItemEditRows,
      });
    } else if (catalogTbody) {
      const rowHtmls = (catalog || []).map(
        (it) => `<tr>
      <td>${it.photo_url ? `<img class="request-thumb" src="${e(it.photo_url)}" alt="${e(it.item_name)}" loading="lazy">` : ''}<strong>${e(it.item_name)}</strong><div class="muted">${e(it.description || '')}</div></td>
      <td>${money(it.unit_price)}</td>
      <td>${statusBadge(it.status)}</td>
      <td><span class="muted">Admin only</span></td>
    </tr>`
      );
      paginateTable(catalogTbody, rowHtmls, { emptyHtml: '<tr><td colspan="4" class="empty">No items.</td></tr>' });
    }
    if (!manageCatalog && catalogSplit) {
      catalogSplit.querySelector('.panel:last-child')?.remove();
    }

    document.getElementById('item-create')?.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      try {
        await apiPost('additional_items.php', 'createItem', Object.fromEntries(new FormData(ev.target).entries()));
        location.reload();
      } catch (err) {
        showAlert(flash, 'error', err.message);
      }
    });
  }

  async function renderHousekeepingManager(main, options = {}) {
    if (!options.skipMount) mountPageTemplate(main, 'housekeeping');
    const res = await apiGet('housekeeping.php', 'getManagementPage');
    if (options.isCurrent && !options.isCurrent()) return;
    const { tasks = [], housekeepers = [] } = res.data || {};
    const tbody = document.getElementById('housekeeping-management-tbody');
    if (!tbody) return;
    const rows = tasks.map((task) => {
      const staffOptions = `<option value="" ${task.assigned_to ? '' : 'selected'}>Unassigned</option>${housekeepers.map((staff) => `<option value="${staff.id}" ${Number(task.assigned_to) === Number(staff.id) ? 'selected' : ''}>${e(staff.full_name)} · ${e(staff.email)}</option>`).join('')}`;
      const items = (task.items || []).map((item) =>
        `<li>${e(item.item_name)}: ${statusBadge(item.condition_status)}${item.notes ? `<div class="cell-meta">${e(item.notes)}</div>` : ''}</li>`
      ).join('');
      const assignment = task.status === 'completed'
        ? `<strong>${e(task.assigned_to_name || 'Unassigned')}</strong>`
        : housekeepers.length
          ? `<form class="form-stack housekeeping-assignment" data-task-id="${task.id}">
              <select name="assigned_to" aria-label="Assign housekeeping task for Room ${e(task.room_number)}">${staffOptions}</select>
              <button class="btn btn-secondary btn-sm" type="submit">Assign</button>
            </form>`
          : '<span class="muted">Create an active Housekeeping user to assign this task.</span>';
      return `<tr>
        <td><strong>Room ${e(task.room_number)}</strong><div class="cell-meta">Task #${e(task.id)} · Booking #${e(task.booking_id)}</div></td>
        <td>${e(task.guest_name)}<div class="cell-meta">Checked out ${fmtStayPoint(task.check_out, task.check_out_time)}</div></td>
        <td>${statusBadge(task.status)}<div class="cell-meta">Room: ${statusBadge(task.room_status)}</div></td>
        <td>${assignment}${task.assigned_by_name ? `<div class="cell-meta">Assigned by ${e(task.assigned_by_name)}</div>` : ''}</td>
        <td><details><summary>${(task.items || []).length} room items</summary><ul>${items || '<li>No checklist items configured.</li>'}</ul></details></td>
      </tr>`;
    });
    paginateTable(tbody, rows, {
      emptyHtml: '<tr><td colspan="5" class="empty">No checkout inspections yet.</td></tr>',
      onRender: (table) => {
        table.querySelectorAll('.housekeeping-assignment').forEach((form) => {
          form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const select = form.elements.assigned_to;
            if (!select.value) {
              showAlert(document.getElementById('flash'), 'error', 'Choose an active housekeeping staff account.');
              return;
            }
            try {
              await apiPost('housekeeping.php', 'assignTask', {
                task_id: form.dataset.taskId,
                assigned_to: select.value,
              });
              location.reload();
            } catch (error) {
              showAlert(document.getElementById('flash'), 'error', error.message);
            }
          });
        });
      },
    });
  }

  async function renderHousekeepingQueue(main) {
    mountPageTemplate(main);
    const res = await apiGet('housekeeping.php', 'getStaffPage');
    const tasks = res.data?.tasks || [];
    const tbody = document.getElementById('housekeeping-tasks');
    if (!tbody) return;
    const conditionOptions = (selected) => [
      ['pending', 'Select condition'],
      ['ok', 'OK'],
      ['damaged', 'Damaged'],
      ['stained', 'Stained'],
      ['missing', 'Missing'],
    ].map(([value, label]) => `<option value="${value}" ${selected === value ? 'selected' : ''}>${label}</option>`).join('');
    const rows = tasks.map((task) => {
      const completed = task.status === 'completed';
      const canInspect = Number(task.assigned_to) === Number(res.data?.user_id) || !task.assigned_to;
      const itemRows = (task.items || []).map((item) => {
        const chargeable = ['damaged', 'stained', 'missing'].includes(item.condition_status);
        const suggestedCharge = Number(item[`${item.condition_status}_charge`] || 0);
        const chargeValue = Number(item.charge_amount) > 0
          ? item.charge_amount
          : chargeable && suggestedCharge > 0 ? suggestedCharge : '';
        return `<tr data-inspection-item="${item.id}">
          <td><strong>${e(item.item_name)}</strong></td>
          <td>${completed
            ? statusBadge(item.condition_status)
            : `<select data-item-condition="${item.id}" aria-label="${e(item.item_name)} condition">${conditionOptions(item.condition_status)}</select>`}</td>
          <td>${completed
            ? Number(item.charge_amount) > 0 ? money(item.charge_amount) : 'No charge'
            : `<input type="number" min="0.01" step="0.01" inputmode="decimal" data-item-charge="${item.id}" data-charge-stained="${e(item.stained_charge)}" data-charge-damaged="${e(item.damaged_charge)}" data-charge-missing="${e(item.missing_charge)}" aria-label="${e(item.item_name)} charge" value="${e(chargeValue)}" placeholder="Charge (₱)" ${chargeable ? '' : 'disabled'}>`}</td>
          <td>${completed
            ? e(item.notes || '—')
            : `<input type="text" data-item-notes="${item.id}" value="${e(item.notes || '')}" aria-label="${e(item.item_name)} notes" placeholder="Optional note; describe damage or stain">`}</td>
        </tr>`;
      }).join('');
      const inspection = completed
        ? '<p class="muted">Inspection completed. The room status reflects the checklist findings.</p>'
        : !task.assigned_to
          ? `<button class="btn btn-primary btn-sm" type="button" data-claim-housekeeping="${task.id}">Claim this task</button>`
          : canInspect
            ? `<form class="housekeeping-inspection form-stack" data-task-id="${task.id}">
                <p class="muted">OK items are not charged. Selecting Damaged, Stained, or Missing fills a suggested charge from the item pricing data. Review and adjust it to match the actual loss or cleaning cost; confirmed charges are added to the guest's bill when you complete the inspection.</p>
                <div class="table-wrap"><table><thead><tr><th>Room item</th><th>Condition</th><th>Charge</th><th>Notes</th></tr></thead><tbody>${itemRows}</tbody></table></div>
                <div class="actions">
                  <button class="btn btn-secondary" type="submit" data-save-inspection>Save Progress</button>
                  <button class="btn btn-primary" type="submit" data-complete-inspection>Complete Inspection</button>
                </div>
              </form>`
            : `<p class="muted">Assigned to ${e(task.assigned_to_name || 'another housekeeping staff member')}.</p>`;
      return `<tr>
        <td><strong>Room ${e(task.room_number)}</strong><div class="cell-meta">${e(task.guest_name)} · Booking #${e(task.booking_id)}</div></td>
        <td>${fmtStayPoint(task.check_out, task.check_out_time)}</td>
        <td>${statusBadge(task.status)}<div class="cell-meta">Room status: ${statusBadge(task.room_status)}</div></td>
        <td>${inspection}</td>
      </tr>`;
    });
    paginateTable(tbody, rows, {
      emptyHtml: '<tr><td colspan="4" class="empty">No housekeeping inspections are assigned to you.</td></tr>',
      onRender: (table) => {
        table.querySelectorAll('[data-claim-housekeeping]').forEach((button) => {
          button.addEventListener('click', async () => {
            button.disabled = true;
            try {
              await apiPost('housekeeping.php', 'claimTask', { task_id: button.dataset.claimHousekeeping });
              location.reload();
            } catch (error) {
              showAlert(document.getElementById('flash'), 'error', error.message);
              button.disabled = false;
            }
          });
        });
        table.querySelectorAll('.housekeeping-inspection').forEach((form) => {
          form.querySelectorAll('[data-item-condition]').forEach((select) => {
            select.addEventListener('change', () => {
              const chargeInput = form.querySelector(`[data-item-charge="${select.dataset.itemCondition}"]`);
              if (!chargeInput) return;
              chargeInput.disabled = !['damaged', 'stained', 'missing'].includes(select.value);
              if (!chargeInput.disabled) {
                const suggestedCharge = Number(chargeInput.dataset[`charge${select.value[0].toUpperCase()}${select.value.slice(1)}`] || 0);
                chargeInput.value = suggestedCharge > 0 ? String(suggestedCharge) : '';
              } else {
                chargeInput.value = '';
              }
            });
          });
          form.querySelector('[data-complete-inspection]')?.addEventListener('click', () => { form.dataset.complete = 'true'; });
          form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const complete = form.dataset.complete === 'true';
            form.dataset.complete = 'false';
            const items = [...form.querySelectorAll('[data-item-condition]')].map((select) => ({
              id: select.dataset.itemCondition,
              condition_status: select.value,
              charge_amount: form.querySelector(`[data-item-charge="${select.dataset.itemCondition}"]`)?.value || '0',
              notes: form.querySelector(`[data-item-notes="${select.dataset.itemCondition}"]`)?.value || '',
            }));
            if (complete && items.some((item) => {
              const chargeAmount = Number(item.charge_amount);
              return ['damaged', 'stained', 'missing'].includes(item.condition_status)
                && (!Number.isFinite(chargeAmount) || chargeAmount <= 0);
            })) {
              showAlert(document.getElementById('flash'), 'error', 'Enter a positive charge amount for each damaged, stained, or missing item.');
              return;
            }
            try {
              await apiPost('housekeeping.php', 'saveInspection', {
                task_id: form.dataset.taskId,
                items,
                complete,
              });
              location.reload();
            } catch (error) {
              showAlert(document.getElementById('flash'), 'error', error.message);
            }
          });
        });
      },
    });
  }

  async function renderOperationsQueue(main) {
    mountPageTemplate(main);
    const res = await apiGet('requests.php', 'getStaffPage');
    const { requests, queue_role: role } = res.data;
    const tbody = document.getElementById('operations-queue-tbody');
    const flash = document.getElementById('flash');
    const nextStatus = (status) => ({
      pending: 'preparing',
      preparing: 'ready',
      ready: 'to_be_delivered',
      not_delivered: 'to_be_delivered',
    })[status] || '';
    const statusAction = {
      preparing: 'Start Preparing',
      ready: 'Mark Ready',
      to_be_delivered: 'Mark To be delivered',
    };
    const canHandle = (request) => {
      if (request.item_unavailable_at) return false;
      if (role === 'kitchen') return request.request_type === 'food';
      return role === 'laundry'
        && ['extra', 'laundry'].includes(request.request_type)
        && Number(request.assigned_to) === Number(res.data.user_id);
    };
    const rows = (requests || []).map((request) => {
      const next = nextStatus(request.status);
      const action = canHandle(request) && next
        ? `<form class="form-stack operations-status" data-id="${request.id}"><input type="hidden" name="status" value="${next}"><button class="btn btn-primary btn-sm" type="submit">${request.status === 'not_delivered' ? 'Retry Delivery' : statusAction[next]}</button></form>`
        : `<span class="muted">${request.status === 'to_be_delivered' ? 'Waiting for customer confirmation' : request.status === 'not_delivered' ? 'Guest reported not received' : request.confirmed_by_name ? 'Confirmed delivered by ' + e(request.confirmed_by_name) : request.status === 'cancelled' ? 'Cancelled' : 'No update available'}</span>`;
      return `<tr>
        <td><strong>${e(request.guest_name)}</strong><div class="cell-meta">Room ${e(request.room_number || '—')}</div></td>
        <td>${request.photo_url ? `<img class="request-thumb" src="${e(request.photo_url)}" alt="${e(request.item_name)}" loading="lazy">` : ''}<strong>${e(request.item_name)}</strong><div class="cell-meta">${e(({ extra: 'Amenities', food: 'Food', laundry: 'Laundry' })[request.request_type] || request.request_type)}${request.item_unavailable_at ? `<br><strong>${request.item_unavailable_reason === 'deleted' ? 'Removed from catalog' : 'Marked inactive'}${request.item_unavailable_by_name ? ` by ${e(request.item_unavailable_by_name)}` : ' by staff'}; this request is retained for history and cannot be fulfilled.</strong>` : ''}</div></td>
        <td>${fmtStayPoint(request.check_in, request.check_in_time)} → ${fmtStayPoint(request.check_out, request.check_out_time)}</td>
        <td>${e(quantityLabel(request.quantity))}</td>
        <td>${e(request.notes || '—')}</td>
        <td>${statusBadge(request.status)}${request.item_unavailable_at ? `<div class="cell-meta"><strong>${request.item_unavailable_reason === 'deleted' ? 'Removed from catalog' : 'Marked inactive'}${request.item_unavailable_by_name ? ` by ${e(request.item_unavailable_by_name)}` : ' by staff'}</strong> · retained for history; do not fulfill</div>` : ''}${request.prepared_by_name ? `<div class="cell-meta">Prepared by ${e(request.prepared_by_name)}</div>` : ''}${request.assigned_to_name ? `<div class="cell-meta">Assigned to ${e(request.assigned_to_name)}</div>` : ''}${request.confirmed_by_name ? `<div class="cell-meta">Customer confirmed ${e(request.confirmed_at || '')}</div>` : ''}</td>
        <td>${action}</td>
      </tr>`;
    });
    paginateTable(tbody, rows, {
      emptyHtml: '<tr><td colspan="7" class="empty">No requests in this queue.</td></tr>',
      onRender: (table) => {
        table.querySelectorAll('.operations-status').forEach((form) => {
          form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const payload = Object.fromEntries(new FormData(form).entries());
            payload.id = form.dataset.id;
            try {
              await apiPost('requests.php', 'updateRequestStatus', payload);
              location.reload();
            } catch (err) {
              showAlert(flash, 'error', err.message);
            }
          });
        });
      },
    });
  }

  async function renderGuestRequests(main) {
    mountPageTemplate(main);
    const res = await apiGet('requests.php', 'getGuestPage');
    const { requests, catalog, booking } = res.data;
    const activeBookings = res.data.bookings?.length ? res.data.bookings : (booking ? [booking] : []);
    const requestedBookingId = Number(qs('booking_id') || booking?.id || 0);
    const extras = catalog?.extras || [];
    const food = catalog?.food || [];
    const laundry = catalog?.laundry || [];
    const canRequest = booking?.status === 'checked_in';

    if (!canRequest) {
      const flashEl = document.getElementById('flash');
      showAlert(flashEl, 'info', 'Service requests are available only while you are checked in. Your account history and bills remain available.');
    }

    const flash = document.getElementById('flash');
    const bindCancelReqRows = (tb) => {
      tb.querySelectorAll('[data-cancel-req]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          if (!window.confirm('Cancel this request?')) return;
          try {
            await apiPost('requests.php', 'cancelRequest', { id: btn.getAttribute('data-cancel-req') });
            location.reload();
          } catch (err) {
            showAlert(flash, 'error', err.message);
          }
        });
      });
      tb.querySelectorAll('[data-confirm-delivery]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          if (!window.confirm('Confirm that you received this requested item?')) return;
          btn.disabled = true;
          try {
            await apiPost('requests.php', 'confirmDelivered', { id: btn.dataset.confirmDelivery });
            location.reload();
          } catch (err) {
            showAlert(flash, 'error', err.message);
            btn.disabled = false;
          }
        });
      });
      tb.querySelectorAll('[data-report-not-delivered]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          if (!window.confirm('Report that this item was not delivered to your room?')) return;
          btn.disabled = true;
          try {
            await apiPost('requests.php', 'reportNotDelivered', { id: btn.dataset.reportNotDelivered });
            location.reload();
          } catch (err) {
            showAlert(flash, 'error', err.message);
            btn.disabled = false;
          }
        });
      });
    };

    const tbody = document.getElementById('guest-req-tbody');
    const renderRequestRows = (guestRequests) => {
      if (!tbody) return;
      const rowHtmls = (guestRequests || []).map(
        (r) => `<tr>
        <td>${r.photo_url ? `<img class="request-thumb" src="${e(r.photo_url)}" alt="${e(r.item_name)}" loading="lazy">` : ''}<strong>${e(r.item_name)}</strong><div class="muted">${e(r.notes || '')}</div>${r.item_unavailable_at ? `<div class="cell-meta"><strong>This item was ${r.item_unavailable_reason === 'deleted' ? 'removed from the catalog' : 'marked inactive'}${r.item_unavailable_by_name ? ` by ${e(r.item_unavailable_by_name)}` : ' by staff'}.</strong> Your request and transaction history are retained. You can still request other active services.</div>` : ''}</td>
        <td>${e(({ extra: 'Amenities', food: 'Food', laundry: 'Laundry' })[r.request_type] || r.request_type)}</td>
        <td>${e(quantityLabel(r.quantity))}</td>
        <td>${statusBadge(r.status)}${r.prepared_by_name ? `<div class="cell-meta">Prepared by ${e(r.prepared_by_name)}</div>` : ''}${r.status === 'not_delivered' ? `<div class="cell-meta">You reported this request was not delivered.</div>` : r.confirmed_by_name ? `<div class="cell-meta">Confirmed delivered by you ${e(String(r.confirmed_at || '').slice(0, 16))}</div>` : ''}${r.handled_at && r.status !== 'pending' ? `<div class="cell-meta">Updated ${e(String(r.handled_at).slice(0, 16))}</div>` : ''}</td>
        <td>${
          r.item_unavailable_at
            ? '—'
            : r.status === 'pending'
            ? `<button class="btn btn-secondary btn-sm" data-cancel-req="${r.id}">Cancel</button>`
            : r.status === 'to_be_delivered'
              ? `<div class="actions"><button class="btn btn-primary btn-sm" type="button" data-confirm-delivery="${r.id}">Received</button><button class="btn btn-secondary btn-sm" type="button" data-report-not-delivered="${r.id}">Not Received</button></div>`
              : '—'
        }</td>
      </tr>`
      );
      paginateTable(tbody, rowHtmls, {
        emptyHtml: '<tr><td colspan="5" class="empty">No requests yet.</td></tr>',
        onRender: bindCancelReqRows,
      });
    };
    renderRequestRows(requests);

    const extraSel = document.getElementById('req-extra-select');
    if (extraSel) {
      extraSel.innerHTML =
        extras.map((x) => `<option value="${x.id}">${e(x.item_name)} — ${money(x.unit_price)}</option>`).join('') ||
        '<option value="">No extras available</option>';
    }
    const foodSel = document.getElementById('req-food-select');
    if (foodSel) {
      foodSel.innerHTML =
        food.map((x) => `<option value="${x.id}">${e(x.name)} — ${money(x.price)}</option>`).join('') ||
        '<option value="">No food items available</option>';
    }
    const laundrySel = document.getElementById('req-laundry-select');
    if (laundrySel) {
      laundrySel.innerHTML =
        laundry.map((x) => `<option value="${x.id}">${e(x.name)} — ${money(x.price)}</option>`).join('') ||
        '<option value="">No laundry services available</option>';
    }
    const roomSel = document.getElementById('req-room-select');
    if (roomSel) {
      roomSel.innerHTML = activeBookings.map((activeBooking) =>
        `<option value="${e(activeBooking.id)}" ${Number(activeBooking.id) === requestedBookingId ? 'selected' : ''}>Room ${e(activeBooking.room_number || '—')} · ${e(activeBooking.status.replace('_', ' '))}</option>`
      ).join('') || '<option value="">No active rooms available</option>';
      roomSel.disabled = !canRequest;
    }
    const submitBtn = document.querySelector('#req-create button[type="submit"]');
    if (submitBtn) submitBtn.disabled = !canRequest;
    document.getElementById('req-create')?.closest('.panel')?.classList.toggle('hidden', !canRequest);

    const typeSel = document.getElementById('req-type');
    const extraWrap = document.getElementById('req-extra-wrap');
    const foodWrap = document.getElementById('req-food-wrap');
    const laundryWrap = document.getElementById('req-laundry-wrap');
    const selects = { extra: extraSel, food: foodSel, laundry: laundrySel };
    const lists = { extra: extras, food, laundry };
    const nameOf = (x) => x.item_name || x.name;
    const priceOf = (x) => x.unit_price ?? x.price;
    const showPicked = () => {
      const type = typeSel?.value;
      const picked = (lists[type] || []).find((x) => String(x.id) === String(selects[type]?.value));
      const box = document.getElementById('req-picked');
      if (!box) return;
      box.hidden = !picked;
      if (!picked) return;
      document.getElementById('req-picked-img').src = picked.photo_url || CATALOG_PLACEHOLDER;
      document.getElementById('req-picked-name').textContent = `${nameOf(picked)} — ${money(priceOf(picked))}`;
      document.getElementById('req-picked-desc').textContent = picked.description || '';
    };
    typeSel?.addEventListener('change', () => {
      extraWrap.hidden = typeSel.value !== 'extra';
      foodWrap.hidden = typeSel.value !== 'food';
      laundryWrap.hidden = typeSel.value !== 'laundry';
      showPicked();
    });
    Object.values(selects).forEach((sel) => sel?.addEventListener('change', showPicked));
    showPicked();

    const gallery = document.getElementById('req-gallery');
    if (gallery) {
      gallery.closest('.panel')?.classList.toggle('hidden', !canRequest);
      const groups = [['extra', 'Amenities'], ['food', 'Food'], ['laundry', 'Laundry']];
      gallery.innerHTML = groups
        .filter(([type]) => lists[type].length)
        .map(([type, label]) => `<h3 class="req-gallery-title">${label}</h3><div class="req-cards">${lists[type]
          .map((x) => `<button type="button" class="req-card" data-type="${type}" data-id="${x.id}">
            <img src="${e(x.photo_url || CATALOG_PLACEHOLDER)}" alt="${e(nameOf(x))}" loading="lazy">
            <strong>${e(nameOf(x))}</strong><span>${money(priceOf(x))}</span>
          </button>`).join('')}</div>`)
        .join('') || '<p class="muted">No items available right now.</p>';
      gallery.querySelectorAll('.req-card').forEach((card) =>
        card.addEventListener('click', () => {
          typeSel.value = card.dataset.type;
          selects[card.dataset.type].value = card.dataset.id;
          typeSel.dispatchEvent(new Event('change'));
          document.getElementById('req-create')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        })
      );
    }
    document.getElementById('req-create')?.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const payload = Object.fromEntries(new FormData(ev.target).entries());
      if (payload.request_type === 'laundry') {
        payload.service_id = payload.laundry_service_id;
        delete payload.laundry_service_id;
      }
      try {
        const created = await apiPost('requests.php', 'createRequest', payload);
        ev.target.reset();
        if (typeSel) {
          typeSel.value = 'extra';
          typeSel.dispatchEvent(new Event('change'));
        }
        try {
          const refreshed = await apiGet('requests.php', 'getGuestPage');
          renderRequestRows(refreshed.data.requests || []);
          showAlert(flash, 'success', created.message || 'Request submitted and added to your guest account balance.');
        } catch (refreshError) {
          showAlert(flash, 'info', `Request submitted successfully, but the list could not refresh: ${refreshError.message}`);
        }
      } catch (err) {
        showAlert(flash, 'error', err.message);
      }
    });
  }

  async function renderRooms(main, session, canEdit, options = {}) {
    if (!options.skipMount) mountPageTemplate(main);
    const [roomsRes, typesRes] = await Promise.all([
      apiGet('rooms.php', 'getAllRooms'),
      apiGet('room_types.php', 'getActiveRoomTypes'),
    ]);
    if (options.isCurrent && !options.isCurrent()) return;
    const rooms = roomsRes.data || [];
    const types = typesRes.data || [];
    const typeOptions = (selectedId) =>
      types
        .map(
          (t) =>
            `<option value="${t.id}" ${String(t.id) === String(selectedId || '') ? 'selected' : ''}>${e(t.name)} · ${money(t.base_rate)}</option>`
        )
        .join('');

    if (canEdit) {
      main.querySelector('.notice-panel')?.remove();
      const typeSel = document.getElementById('room-type-select');
      if (typeSel) {
        typeSel.innerHTML = `<option value="">Select room type…</option>${typeOptions('')}`;
      }
    } else {
      document.getElementById('room-create')?.closest('.panel')?.remove();
    }

    const tbody = document.getElementById('rooms-tbody');
    if (!tbody) return;

    mountFeaturePickers(main);
    const bindRoomRows = (tb) => {
      mountFeaturePickers(tb);
      tb.querySelectorAll('[data-room-lightbox]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const src = btn.getAttribute('data-room-lightbox') || '';
          const label = btn.getAttribute('data-room-label') || 'Room photo';
          if (!src) return;
          openRoomLightbox(src, label);
        });
      });
      if (!canEdit) return;
      tb.querySelectorAll('.room-edit-form').forEach((form) => {
        form.addEventListener('submit', async (ev) => {
          ev.preventDefault();
          const fd = new FormData(form);
          fd.set('id', form.getAttribute('data-id'));
          try {
            await apiPostForm('rooms.php', 'updateRoom', fd);
            location.reload();
          } catch (err) {
            showAlert(document.getElementById('flash'), 'error', err.message);
          }
        });
      });
      tb.querySelectorAll('[data-delete-room]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          if (!window.confirm(btn.getAttribute('data-confirm') || 'Delete room?')) return;
          try {
            await apiPost('rooms.php', 'deleteRoom', { id: btn.getAttribute('data-delete-room') });
            location.reload();
          } catch (err) {
            showAlert(document.getElementById('flash'), 'error', err.message);
          }
        });
      });
    };

    const rowHtmls = rooms.map((r) => {
      const typeName = r.room_type_name || r.room_type || 'Room';
      const photo = r.photo_url || '';
      const label = `Room ${r.room_number} · ${typeName}`;
      const edit = canEdit
        ? `<td class="rooms-manage-cell">
          <form class="room-edit-form" data-id="${r.id}">
            <div class="room-edit-grid">
              <label>Type<select name="room_type_id">${typeOptions(r.room_type_id)}</select></label>
              <label>Rate<input type="number" step="0.01" name="rate_per_night" value="${e(r.rate_per_night)}" required></label>
              <label>Max adults<input type="number" min="1" max="20" name="max_adults" value="${e(r.max_adults ?? 2)}" required></label>
              <label>Max children<input type="number" min="0" max="20" name="max_children" value="${e(r.max_children ?? 1)}" required></label>
              <label>Status
                <select name="status">
                  ${['available', 'occupied', 'maintenance', 'reserved', 'cleaning']
                    .map((st) => `<option value="${st}" ${r.status === st ? 'selected' : ''}>${st}</option>`)
                    .join('')}
                </select>
              </label>
              <div class="room-edit-full"><span class="dt-label">Features</span>${featurePicker('room_features', r.room_features)}</div>
              <label class="room-edit-full">New photo
                <input type="file" name="photo" accept="image/*" class="file-input">
              </label>
            </div>
            <div class="actions room-edit-actions">
              <button class="btn btn-secondary btn-sm" type="submit">Save</button>
              <button class="btn btn-danger btn-sm" type="button" data-delete-room="${r.id}" data-confirm="Delete room ${e(r.room_number)}?">Delete</button>
            </div>
          </form>
        </td>`
        : '';
      return `<tr class="rooms-row">
        <td>
          <div class="room-media">
            <button type="button" class="room-media-photo" data-room-lightbox="${e(photo)}" data-room-label="${e(label)}" title="View photo">
              <img src="${e(photo)}" alt="${e(label)}" loading="lazy">
            </button>
            <div class="room-media-copy">
              <strong class="room-media-number">Room ${e(r.room_number)}</strong>
              <span class="cell-meta">Floor ${e(r.floor)} · Up to ${e(r.max_adults ?? 2)} adults + ${e(r.max_children ?? 1)} kids</span>
            </div>
          </div>
        </td>
        <td>
          <strong>${e(typeName)}</strong>
          <div class="room-rate-line">${money(r.rate_per_night)} <span class="cell-meta">/ night</span></div>
        </td>
        <td><span class="room-features-text">${e(r.room_features || '—')}</span></td>
        <td>${statusBadge(r.status)}</td>
        ${edit}
      </tr>`;
    });
    paginateTable(tbody, rowHtmls, {
      emptyHtml: `<tr><td colspan="${canEdit ? 5 : 4}" class="empty">No rooms.</td></tr>`,
      onRender: bindRoomRows,
    });

    if (!canEdit) return;

    document.getElementById('room-create')?.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const fd = new FormData(ev.target);
      try {
        await apiPostForm('rooms.php', 'createRoom', fd);
        location.reload();
      } catch (err) {
        showAlert(document.getElementById('flash'), 'error', err.message);
      }
    });
  }

  async function renderRoomManagement(main, options = {}) {
    if (!options.skipMount) mountPageTemplate(main);
    const flash = document.getElementById('flash');
    const scheduleMessage = sessionStorage.getItem('room-schedule-flash');
    if (scheduleMessage) {
      showAlert(flash, 'success', scheduleMessage);
      sessionStorage.removeItem('room-schedule-flash');
    }
    const today = localStamp().slice(0, 10);
    const selectedDate = qs('date') || today;
    const res = await apiGet('rooms.php', 'getRoomManagement', { date: selectedDate });
    const tbody = document.getElementById('room-management-tbody');
    const rooms = res.data || [];
    const dateInput = document.getElementById('room-schedule-date');
    if (dateInput) {
      dateInput.value = selectedDate;
      dateInput.addEventListener('change', () => {
        location.search = `?date=${encodeURIComponent(dateInput.value || today)}`;
      });
    }
    const roomRows = rooms.map((room) => {
        const bookingStatus = room.booking_status || '';
        const canSetStatus = !room.booking_id;
        const hasPendingInspection = Number(room.housekeeping_pending) === 1;
        const status = bookingStatus === 'checked_in'
          ? 'checked_in'
          : bookingStatus === 'reserved'
            ? 'reserved'
            : ['maintenance', 'cleaning', 'occupied', 'reserved'].includes(room.status) ? room.status : 'available';
        const stay = room.booking_id
          ? `<strong>${e(room.guest_name || 'Guest')}</strong><div class="cell-meta">Booking #${e(room.booking_id)} · ${fmtStayPoint(room.check_in, room.check_in_time)} to ${fmtStayPoint(room.check_out, room.check_out_time)}</div>`
          : '<span class="muted">No stay scheduled</span>';
        const details = `<ul class="kpi-list room-booking-details" id="room-detail-${room.id}" data-room-detail-panel="${room.id}" hidden>
          <li><span>Booking</span><strong>${room.booking_id ? `#${e(room.booking_id)}` : 'No booking for this date'}</strong></li>
          <li><span>Guest</span><strong>${e(room.guest_name || '—')}</strong></li>
          <li><span>Contact</span><strong>${e(room.guest_phone || room.guest_email || '—')}</strong></li>
          <li><span>Stay Dates</span><strong>${room.booking_id ? `${fmtStayPoint(room.check_in, room.check_in_time)} to ${fmtStayPoint(room.check_out, room.check_out_time)}` : '—'}</strong></li>
          <li><span>Stay Status</span><strong>${statusBadge(bookingStatus || 'available')}</strong></li>
          <li><span>Guests</span><strong>${room.booking_id ? `${e(room.adults || 1)} adults · ${e(room.children || 0)} children` : '—'}</strong></li>
          <li><span>Notes</span><strong>${e(room.booking_notes || '—')}</strong></li>
          <li><span>Rate</span><strong>${money(room.rate_per_night)} / night</strong></li>
          <li><span>Room Status</span><strong>${statusBadge(room.status)}</strong></li>
          <li><span>Update Room Status</span><strong class="actions">
            <select data-room-status="${room.id}" aria-label="Room ${e(room.room_number)} status" ${canSetStatus ? '' : 'disabled'}>
              ${['available', 'maintenance', 'cleaning', ...(['occupied', 'reserved'].includes(room.status) ? [room.status] : [])]
                .map((value) => `<option value="${value}" ${room.status === value ? 'selected' : ''} ${(!canSetStatus && value !== room.status) || (hasPendingInspection && value === 'available' && room.status !== 'available') ? 'disabled' : ''}>${value[0].toUpperCase() + value.slice(1)}</option>`).join('')}
            </select>
            <button class="btn btn-secondary btn-sm" type="button" data-save-room-status="${room.id}" ${canSetStatus ? '' : 'disabled'}>Save</button>
          </strong>${canSetStatus ? hasPendingInspection ? '<div class="cell-meta">Complete the outstanding housekeeping inspection before marking this room available.</div>' : '' : '<div class="cell-meta">Managed by the active stay</div>'}</li>
        </ul>`;
        const roleFolder = document.body.dataset.role === 'admin' ? 'admin' : 'frontdesk';
        const transferLink = bookingStatus === 'checked_in'
            ? `<a class="btn btn-secondary btn-sm" href="${BASE_PATH}/${roleFolder}/room-management.html?tab=transfers&amp;booking_id=${room.booking_id}">Transfer Room</a>`
          : '';
        const action = bookingStatus === 'reserved'
          ? selectedDate === today
            ? `<button class="btn btn-primary btn-sm" type="button" data-room-checkin="${room.booking_id}">Check-In</button>`
            : '<span class="muted">Scheduled</span>'
          : bookingStatus === 'checked_in'
            ? selectedDate === today
              ? `<div class="actions"><button class="btn btn-teal btn-sm" type="button" data-room-checkout="${room.booking_id}">Check-Out</button>${transferLink}</div>`
              : transferLink
            : room.status === 'maintenance'
              ? '<span class="muted">Maintenance</span>'
              : `<a class="btn btn-secondary btn-sm" href="${BASE_PATH}/${roleFolder}/checkin.html?room_id=${room.id}&amp;date=${encodeURIComponent(selectedDate)}">Book Room</a>`;
        return { status, html: `<tr>
          <td><strong>Room ${e(room.room_number)}</strong></td>
          <td>${e(room.room_type)}<div class="cell-meta">${money(room.rate_per_night)} / night</div></td>
          <td>${stay}</td>
          <td>${statusBadge(status)}</td>
          <td><div class="actions">${action}<button class="btn btn-secondary btn-sm" type="button" data-room-details="${room.id}" aria-controls="room-detail-${room.id}" aria-expanded="false">View Details</button></div>${details}</td>
        </tr>` };
    });
    const statusLabels = [
      ['all', 'All Rooms'],
      ['available', 'Available'],
      ['reserved', 'Reserved'],
      ['checked_in', 'Checked In'],
      ['maintenance', 'Maintenance'],
      ['cleaning', 'Cleaning'],
    ];
    const statusCounts = roomRows.reduce((counts, row) => {
      counts[row.status] = (counts[row.status] || 0) + 1;
      return counts;
    }, { all: roomRows.length });
    const summary = document.getElementById('room-status-summary');
    if (summary) {
      summary.innerHTML = statusLabels.map(([key, label]) =>
        `<div class="room-status-count"><span>${label}</span><strong>${statusCounts[key] || 0}</strong></div>`
      ).join('');
    }
    const filters = document.getElementById('room-status-filters');
    let selectedStatus = 'all';
    if (filters) {
      filters.innerHTML = statusLabels.map(([key, label]) =>
        `<button class="btn btn-secondary btn-sm room-status-filter" type="button" data-room-status-filter="${key}" aria-pressed="${key === selectedStatus}">${label} (${statusCounts[key] || 0})</button>`
      ).join('');
    }
    const renderRows = () => {
      const visibleRows = selectedStatus === 'all'
        ? roomRows
        : roomRows.filter((row) => row.status === selectedStatus);
      paginateTable(tbody, visibleRows.map((row) => row.html), {
        emptyHtml: `<tr><td colspan="5" class="empty">${roomRows.length ? 'No rooms match this status for the selected date.' : 'No rooms in inventory.'}</td></tr>`,
        onRender: (table) => {
          table.querySelectorAll('[data-room-details]').forEach((button) => {
            button.addEventListener('click', () => {
              const panel = button.closest('td')?.querySelector('[data-room-detail-panel]');
              if (!panel) return;
              panel.hidden = !panel.hidden;
              button.setAttribute('aria-expanded', String(!panel.hidden));
              button.textContent = panel.hidden ? 'View Details' : 'Hide Details';
            });
          });
          table.querySelectorAll('[data-save-room-status]').forEach((button) => {
            button.addEventListener('click', async () => {
              const roomId = button.dataset.saveRoomStatus;
              const statusInput = table.querySelector(`[data-room-status="${roomId}"]`);
              button.disabled = true;
              try {
                await apiPost('rooms.php', 'setOperationalStatus', { id: roomId, status: statusInput?.value || '' });
                location.reload();
              } catch (err) {
                showAlert(flash, 'error', err.message);
                button.disabled = false;
              }
            });
          });
          table.querySelectorAll('[data-room-checkin]').forEach((button) => {
            button.addEventListener('click', async () => {
              try {
                await apiPost('bookings.php', 'checkIn', { id: button.dataset.roomCheckin });
                const roleFolder = document.body.dataset.role === 'admin' ? 'admin' : 'frontdesk';
                location.href = `${BASE_PATH}/${roleFolder}/billing.html?booking_id=${encodeURIComponent(button.dataset.roomCheckin)}`;
              } catch (err) {
                showAlert(flash, 'error', err.message);
              }
            });
          });
          table.querySelectorAll('[data-room-checkout]').forEach((button) => {
            button.addEventListener('click', async () => {
              try {
                await apiPost('bookings.php', 'checkOut', { id: button.dataset.roomCheckout });
                location.href = `${BASE_PATH}/${document.body.dataset.role === 'admin' ? 'admin/housekeeping.html' : 'frontdesk/operations.html?tab=inspections'}`;
              } catch (err) {
                showAlert(flash, 'error', err.message);
              }
            });
          });
        },
      });
    };
    filters?.querySelectorAll('[data-room-status-filter]').forEach((button) => {
      button.addEventListener('click', () => {
        selectedStatus = button.dataset.roomStatusFilter || 'all';
        filters.querySelectorAll('[data-room-status-filter]').forEach((filterButton) => {
          filterButton.setAttribute('aria-pressed', String(filterButton === button));
        });
        renderRows();
      });
    });
    renderRows();
  }

  function openRoomLightbox(src, label) {
    let overlay = document.getElementById('room-lightbox');
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.id = 'room-lightbox';
      overlay.className = 'room-lightbox';
      overlay.innerHTML = `
        <button type="button" class="room-lightbox-close" aria-label="Close">×</button>
        <figure class="room-lightbox-figure">
          <img alt="">
          <figcaption></figcaption>
        </figure>`;
      document.body.appendChild(overlay);
      const close = () => overlay.classList.remove('is-open');
      overlay.addEventListener('click', (ev) => {
        if (ev.target === overlay || ev.target.classList.contains('room-lightbox-close')) close();
      });
      document.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape') close();
      });
    }
    const img = overlay.querySelector('img');
    const cap = overlay.querySelector('figcaption');
    img.src = src;
    img.alt = label;
    cap.textContent = label;
    overlay.classList.add('is-open');
  }

  const CATALOG_PRESETS = {
    food: [
      ['Breakfast Buffet', 450, 'Daily breakfast buffet per person'],
      ['Club Sandwich', 280, 'Triple-decker sandwich with fries'],
      ['Chicken Adobo Meal', 320, 'Filipino classic with garlic rice'],
      ['Pancake Stack', 220, 'Pancakes with syrup and butter'],
      ['Garden Salad', 240, 'Fresh garden salad with house dressing'],
      ['Fresh Fruit Platter', 350, 'Seasonal fruits delivered to the room'],
      ['Steamed Rice', 60, 'Steamed white rice, one serving'],
      ['Hot Coffee', 90, 'Freshly brewed coffee'],
      ['Hot Tea', 70, 'Selection of hot tea'],
      ['Bottled Water', 45, 'Chilled bottled drinking water'],
      ['Vegetable Soup', 180, 'Warm seasonal vegetable soup'],
    ],
    laundry: [
      ['Wash & Fold', 350, 'Wash and fold per load'],
      ['Dry Cleaning', 250, 'Per piece dry cleaning'],
      ['Ironing Only', 80, 'Per piece ironing'],
      ['Express Laundry (3 hrs)', 500, 'Priority wash, dry and fold'],
      ['Delicate Garment Wash', 300, 'Gentle wash for delicate garments'],
    ],
    extra: [
      ['Extra Pillow', 150, 'Additional standard soft pillow'],
      ['Extra Bath Towel', 100, 'Fresh bath towel'],
      ['Extra Blanket', 200, 'Additional thermal blanket'],
      ['Toiletries Kit', 120, 'Toothbrush, shampoo, soap and comb'],
      ['Bedroom Slippers', 60, 'Disposable slippers'],
      ['Hair Dryer', 100, 'Delivered to your room'],
      ['Dental Care Kit', 100, 'Toothbrush and toothpaste set'],
      ['Shower Cap', 40, 'Disposable shower cap'],
      ['Bedroom Slippers', 100, 'Comfortable disposable guest slippers'],
      ['Sewing Kit', 50, 'Small travel sewing kit'],
      ['Sanitary Kit', 120, 'Guest personal care kit'],
    ],
  };
  const CATALOG_KIND_LABEL = { food: 'Food', laundry: 'Laundry', extra: 'Items' };
  const CATALOG_PLACEHOLDER =
    'data:image/svg+xml;utf8,' +
    encodeURIComponent(
      '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="90" viewBox="0 0 120 90"><rect width="120" height="90" fill="#eef1f6"/><path d="M30 62l16-20 12 14 8-9 24 15z" fill="#c4ccd9"/><circle cx="42" cy="32" r="7" fill="#c4ccd9"/></svg>'
    );

  async function renderServices(main, options = {}) {
    if (!options.skipMount) mountPageTemplate(main, 'services');
    const canManage = Boolean(options.canManage);
    const flash = document.getElementById('flash');
    const [svcRes, itemRes, catRes] = await Promise.all([
      apiGet('services.php', 'getAllServices'),
      apiGet('additional_items.php', 'getAllItems'),
      apiGet('categories.php', 'getAllCategories', { category_type: 'service' }),
    ]);
    if (options.isCurrent && !options.isCurrent()) return;
    const categories = catRes.data || [];
    if (!canManage) {
      document.getElementById('catalog-form-panel')?.classList.add('hidden');
      document.querySelector('#services-tbody')?.closest('table')?.querySelector('thead th:last-child')?.remove();
    }
    const serviceKind = (s) => {
      const cat = String(s.category_name || s.category || '').trim().toLowerCase();
      if (['food', 'restaurant', 'room service'].includes(cat)) return 'food';
      return cat === 'laundry' ? 'laundry' : 'other';
    };
    const entries = [
      ...(svcRes.data || []).filter((s) => serviceKind(s) !== 'other').map((s) => ({
        key: `service:${s.id}`, source: 'service', id: s.id, kind: serviceKind(s), name: s.name,
        price: s.price, description: s.description || '', status: s.status, photo: s.photo_url,
        category: s.category_name || s.category || '—', category_id: s.category_id,
      })),
      ...(itemRes.data || []).map((it) => ({
        key: `extra:${it.id}`, source: 'extra', id: it.id, kind: 'extra', name: it.item_name,
        price: it.unit_price, description: it.description || '', status: it.status, photo: it.photo_url,
        category: 'Items (Extra)', category_id: null,
      })),
    ];
    const categoryCounts = {
      food: entries.filter((entry) => entry.kind === 'food').length,
      laundry: entries.filter((entry) => entry.kind === 'laundry').length,
      extra: entries.filter((entry) => entry.kind === 'extra').length,
    };
    const categoryCards = document.getElementById('service-category-cards');
    if (categoryCards) {
      categoryCards.innerHTML = [
        { name: 'Food', description: 'Meals, drinks, and in-room food service.', count: categoryCounts.food },
        { name: 'Laundry', description: 'Washing, ironing, and dry cleaning.', count: categoryCounts.laundry },
        { name: 'Items', description: 'Guest comfort, supplies, and room extras.', count: categoryCounts.extra },
      ].map((category) => `
        <article class="stat">
          <div class="label">${e(category.name)}</div>
          <div class="value">${category.count}</div>
          <p class="muted">${e(category.description)}</p>
        </article>`).join('');
    }

    const form = document.getElementById('catalog-form');
    const kindSel = document.getElementById('cat-kind');
    const presetSel = document.getElementById('cat-preset');
    const preview = document.getElementById('cat-photo-preview');
    const fileInput = document.getElementById('cat-photo');
    const idInput = document.getElementById('cat-id');
    const saveBtn = document.getElementById('cat-save');
    const cancelBtn = document.getElementById('cat-cancel');
    const statusWrap = document.getElementById('cat-status-wrap');
    const removeWrap = document.getElementById('cat-remove-wrap');
    let editing = null;

    // Food and Laundry map to their built-in service category behind the scenes.
    const categoryIdFor = (kind) => {
      const wanted = kind === 'laundry' ? ['laundry'] : ['food', 'restaurant', 'room service'];
      return wanted.map((w) => categories.find((c) => String(c.name).trim().toLowerCase() === w)).find(Boolean)?.id || '';
    };
    const fillPresets = () => {
      const list = CATALOG_PRESETS[kindSel.value] || [];
      presetSel.innerHTML =
        '<option value="">Choose a ready-made entry…</option>' +
        list.map(([n, p], i) => `<option value="${i}">${e(n)} — ${money(p)}</option>`).join('');
    };
    const showPreview = (src) => {
      preview.hidden = !src;
      if (src) preview.src = src;
    };
    const resetForm = () => {
      editing = null;
      form.reset();
      idInput.value = '';
      kindSel.disabled = false;
      statusWrap.hidden = true;
      removeWrap.hidden = true;
      cancelBtn.hidden = true;
      saveBtn.textContent = 'Add';
      document.getElementById('catalog-form-title').textContent = 'Add Request Item';
      fillPresets();
      showPreview('');
    };

    kindSel.addEventListener('change', () => {
      fillPresets();
    });
    presetSel.addEventListener('change', () => {
      const p = (CATALOG_PRESETS[kindSel.value] || [])[Number(presetSel.value)];
      if (!p) return;
      form.elements.name.value = p[0];
      form.elements.price.value = p[1];
      form.elements.description.value = p[2];
    });
    fileInput.addEventListener('change', () => {
      const file = fileInput.files?.[0];
      showPreview(file ? URL.createObjectURL(file) : editing?.photo || '');
    });
    cancelBtn.addEventListener('click', resetForm);

    const startEdit = (entry) => {
      editing = entry;
      form.reset();
      idInput.value = entry.id;
      kindSel.value = entry.kind;
      kindSel.disabled = true;
      fillPresets();
      form.elements.name.value = entry.name;
      form.elements.price.value = entry.price;
      form.elements.description.value = entry.description;
      form.elements.status.value = entry.status;
      statusWrap.hidden = false;
      removeWrap.hidden = !entry.photo;
      showPreview(entry.photo || '');
      saveBtn.textContent = 'Save Changes';
      cancelBtn.hidden = false;
      document.getElementById('catalog-form-title').textContent = `Edit ${entry.name}`;
      document.getElementById('catalog-form-panel').scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const fd = new FormData(form);
      fd.delete('kind');
      const isExtra = (editing ? editing.source === 'extra' : kindSel.value === 'extra');
      const file = isExtra ? 'additional_items.php' : 'services.php';
      if (isExtra) {
        fd.set('item_name', fd.get('name'));
        fd.set('unit_price', fd.get('price'));
        fd.delete('category_id');
      } else {
        fd.set('category_id', editing?.category_id || categoryIdFor(kindSel.value));
        if (!fd.get('category_id')) {
          showAlert(flash, 'error', 'The Food/Laundry category is missing. Re-import the database or add it first.');
          return;
        }
      }
      const operation = editing
        ? (isExtra ? 'updateItem' : 'updateService')
        : (isExtra ? 'createItem' : 'createService');
      try {
        await apiPostForm(file, operation, fd);
        location.reload();
      } catch (err) {
        showAlert(flash, 'error', err.message);
      }
    });

    const tbody = document.getElementById('services-tbody');
    const paint = () => {
      const kind = document.getElementById('svc-filter')?.value || 'all';
      const q = (document.getElementById('cat-search')?.value || '').trim().toLowerCase();
      const rows = entries
        .filter((x) => (kind === 'all' || x.kind === kind) && (!q || `${x.name} ${x.description}`.toLowerCase().includes(q)))
        .map((x) => `<tr>
          <td data-label="Photo"><img class="catalog-thumb" src="${e(x.photo || CATALOG_PLACEHOLDER)}" alt="${e(x.name)}" loading="lazy"></td>
          <td data-label="Name"><strong>${e(x.name)}</strong><div class="cell-meta">${e(x.description)}</div></td>
          <td data-label="Category">${e(CATALOG_KIND_LABEL[x.kind])}</td>
          <td data-label="Price">${money(x.price)}</td>
          <td data-label="Status">${statusBadge(x.status)}</td>
            ${canManage ? `<td data-label="Actions" class="actions">
            <button class="btn btn-secondary btn-sm" type="button" data-edit="${e(x.key)}">Edit</button>
            ${x.status === 'active' ? `<button class="btn btn-danger btn-sm" type="button" data-del="${e(x.key)}">Archive</button>` : '<span class="muted">Archived</span>'}
            </td>` : ''}
        </tr>`);
      paginateTable(tbody, rows, {
          emptyHtml: `<tr><td colspan="${canManage ? 6 : 5}" class="empty">Nothing here yet.</td></tr>`,
        onRender: (tb) => {
            if (!canManage) return;
            tb.querySelectorAll('[data-edit]').forEach((btn) =>
            btn.addEventListener('click', () => startEdit(entries.find((x) => x.key === btn.dataset.edit)))
          );
          tb.querySelectorAll('[data-del]').forEach((btn) =>
            btn.addEventListener('click', async () => {
              const entry = entries.find((x) => x.key === btn.dataset.del);
              if (!entry || !window.confirm(`Archive ${entry.name}? It will no longer be available for new requests. Existing requests and transaction history will be kept.`)) return;
              try {
                if (entry.source === 'extra') await apiPost('additional_items.php', 'deleteItem', { id: entry.id });
                else await apiPost('services.php', 'deleteService', { id: entry.id });
                location.reload();
              } catch (err) {
                showAlert(flash, 'error', err.message);
              }
            })
          );
        },
      });
    };
    document.getElementById('svc-filter')?.addEventListener('change', paint);
    document.getElementById('cat-search')?.addEventListener('input', paint);

    resetForm();
    paint();
  }

  async function renderArchiveManagement(main, options = {}) {
    if (!options.skipMount) mountPageTemplate(main, 'archive');
    const canManage = document.body.dataset.role === 'admin';
    const [servicesRes, itemsRes] = await Promise.all([
      apiGet('services.php', 'getAllServices'),
      apiGet('additional_items.php', 'getAllItems'),
    ]);
    if (options.isCurrent && !options.isCurrent()) return;

    const note = document.getElementById('archive-access-note');
    if (note && !canManage) {
      note.textContent = 'Archived catalog entries are hidden from new guest requests. Existing requests and transaction history remain available for reference. Only Admin can restore catalog entries.';
    }
    const serviceKind = (service) => {
      const category = String(service.category_name || service.category || '').trim().toLowerCase();
      if (['food', 'restaurant', 'room service'].includes(category)) return 'Food';
      return category === 'laundry' ? 'Laundry' : '';
    };
    const archived = [
      ...(servicesRes.data || [])
        .filter((service) => service.status !== 'active' && serviceKind(service))
        .map((service) => ({
          id: service.id,
          source: 'service',
          name: service.name,
          type: serviceKind(service),
          description: service.description || '',
          price: service.price,
        })),
      ...(itemsRes.data || [])
        .filter((item) => item.status !== 'active')
        .map((item) => ({
          id: item.id,
          source: 'extra',
          name: item.item_name,
          type: 'Items',
          description: item.description || '',
          price: item.unit_price,
        })),
    ];
    const tbody = document.getElementById('archive-items-tbody');
    if (!tbody) return;

    const rows = archived.map((item) => `<tr>
      <td><strong>${e(item.name)}</strong><div class="cell-meta">${e(item.description || '—')}</div></td>
      <td>${e(item.type)}</td>
      <td>${money(item.price)}</td>
      <td><span class="badge badge-muted">Archived / inactive</span></td>
      <td>${canManage
      ? `<div class="actions"><button type="button" class="btn btn-primary btn-sm" data-restore-item="${e(item.source)}:${e(item.id)}">Restore</button><button type="button" class="btn btn-danger btn-sm" data-permanent-delete-item="${e(item.source)}:${e(item.id)}">Delete permanently</button></div>`
        : '<span class="muted">Admin only</span>'}</td>
    </tr>`);

    paginateTable(tbody, rows, {
      emptyHtml: '<tr><td colspan="5" class="empty">No archived Food, Laundry, or additional items.</td></tr>',
      onRender: (table) => {
        table.querySelectorAll('[data-restore-item]').forEach((button) => {
          button.addEventListener('click', async () => {
            const [source, id] = String(button.dataset.restoreItem || '').split(':');
            const entry = archived.find((item) => item.source === source && String(item.id) === id);
            if (!entry || !window.confirm(`Restore ${entry.name}? It will become available for new guest requests. Past requests remain marked as archived.`)) return;
            button.disabled = true;
            try {
              const api = source === 'extra' ? 'additional_items.php' : 'services.php';
              const operation = source === 'extra' ? 'restoreItem' : 'restoreService';
              await apiPost(api, operation, { id });
              location.reload();
            } catch (error) {
              button.disabled = false;
              showAlert(document.getElementById('flash'), 'error', error.message);
            }
          });
        });
        table.querySelectorAll('[data-permanent-delete-item]').forEach((button) => {
          button.addEventListener('click', async () => {
            const [source, id] = String(button.dataset.permanentDeleteItem || '').split(':');
            const entry = archived.find((item) => item.source === source && String(item.id) === id);
            if (!entry || !window.confirm(`Permanently delete ${entry.name} from the catalog? Existing guest requests and bills will be retained and marked as deleted. This cannot be undone.`)) return;
            button.disabled = true;
            try {
              const api = source === 'extra' ? 'additional_items.php' : 'services.php';
              const operation = source === 'extra' ? 'permanentlyDeleteItem' : 'permanentlyDeleteService';
              await apiPost(api, operation, { id });
              location.reload();
            } catch (error) {
              button.disabled = false;
              showAlert(document.getElementById('flash'), 'error', error.message);
            }
          });
        });
      },
    });
  }

  async function renderUsers(main) {
    mountPageTemplate(main);
    const res = await apiGet('users.php', 'getAllUsers');
    const users = res.data || [];
    const bindUserRows = (tb) => {
      tb.querySelectorAll('[data-reset]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const password = prompt('New password (min 6 chars):');
          if (!password) return;
          try {
            await apiPost('users.php', 'resetPassword', {
              id: btn.getAttribute('data-reset'),
              password,
            });
            alert('Password reset.');
          } catch (err) {
            showAlert(document.getElementById('flash'), 'error', err.message);
          }
        });
      });
    };

    const tbody = document.getElementById('users-tbody');
    if (tbody) {
      const rowHtmls = users.map(
        (u) => `<tr>
        <td>${e(u.full_name)}</td><td>${e(u.first_name || '—')}</td><td>${e(u.last_name || '—')}</td>
        <td>${e(u.email)}</td><td>${e(u.phone || '—')}</td><td>${e(u.role)}</td><td>${statusBadge(u.status)}</td>
        <td class="actions">
          <button class="btn btn-secondary btn-sm" data-reset="${u.id}">Reset PW</button>
        </td>
      </tr>`
      );
      paginateTable(tbody, rowHtmls, {
        emptyHtml: '<tr><td colspan="8" class="empty">No users.</td></tr>',
        onRender: bindUserRows,
      });
    }

    document.getElementById('user-create').addEventListener('submit', async (ev) => {
      ev.preventDefault();
      try {
        await apiPost('users.php', 'createUser', Object.fromEntries(new FormData(ev.target).entries()));
        location.reload();
      } catch (err) {
        showAlert(document.getElementById('flash'), 'error', err.message);
      }
    });
  }

  function discountLabel(d) {
    if (!d) return '—';
    return d.discount_type === 'fixed' ? money(d.value) : `${e(d.value)}%`;
  }

  function bindCreateDiscountForm(flash) {
    const form = document.getElementById('disc-create');
    const typeSelect = document.getElementById('disc-type');
    const valueInput = document.getElementById('disc-value');
    const syncLimit = () => {
      if (valueInput) valueInput.max = typeSelect?.value === 'percent' ? '100' : '';
    };
    form?.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      try {
        await apiPost('discounts.php', 'createDiscount', Object.fromEntries(new FormData(ev.target).entries()));
        location.reload();
      } catch (err) {
        showAlert(flash, 'error', err.message);
      }
    });
    typeSelect?.addEventListener('change', syncLimit);
    syncLimit();
  }

  async function renderDiscounts(main, options = {}) {
    if (!options.skipMount) mountPageTemplate(main, 'discounts');
    const res = await apiGet('discounts.php', 'getAllDiscounts');
    const { senior, pwd, employee, custom } = res.data;
    fillFormValues(document.getElementById('form-employee'), {
      name: employee?.name || 'Employee Discount',
      value: employee?.value ?? 10,
      description: employee?.description || '',
    });

    fillFormValues(document.getElementById('form-senior'), {
      name: senior?.name || 'Senior Citizen Discount',
      value: senior?.value ?? 10,
      description: senior?.description || '',
    });
    fillFormValues(document.getElementById('form-pwd'), {
      name: pwd?.name || 'PWD Discount',
      value: pwd?.value ?? 10,
      description: pwd?.description || '',
    });

    const flash = document.getElementById('flash');
    const bindDiscRows = (tb) => {
      tb.querySelectorAll('.disc-edit').forEach((form) => {
        form.addEventListener('submit', async (ev) => {
          ev.preventDefault();
          const payload = Object.fromEntries(new FormData(form).entries());
          payload.id = form.getAttribute('data-id');
          try {
            await apiPost('discounts.php', 'updateDiscount', payload);
            location.reload();
          } catch (err) {
            showAlert(flash, 'error', err.message);
          }
        });
      });
      tb.querySelectorAll('[data-delete-disc]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          if (!window.confirm(btn.getAttribute('data-confirm') || 'Delete?')) return;
          try {
            await apiPost('discounts.php', 'deleteDiscount', { id: btn.getAttribute('data-delete-disc') });
            location.reload();
          } catch (err) {
            showAlert(flash, 'error', err.message);
          }
        });
      });
    };

    const tbody = document.getElementById('disc-tbody');
    if (tbody) {
      const rowHtmls = (custom || []).map(
        (d) => `<tr>
        <td data-label="Name"><strong>${e(d.name)}</strong></td>
        <td data-label="Description">${e(d.description || 'No details provided.')}</td>
        <td data-label="Type / Value">${e(d.discount_type)} · ${discountLabel(d)}</td>
        <td data-label="Status">${statusBadge(d.status)}</td>
        <td data-label="Edit / Delete">
          <form class="form-stack disc-edit" data-id="${d.id}">
            <label>Name<input name="name" value="${e(d.name)}" required></label>
            <label>Type<select name="discount_type">
              <option value="percent" ${d.discount_type === 'percent' ? 'selected' : ''}>Percent</option>
              <option value="fixed" ${d.discount_type === 'fixed' ? 'selected' : ''}>Fixed amount</option>
            </select></label>
            <label>Value<input type="number" min="0.01" max="${d.discount_type === 'percent' ? '100' : ''}" step="0.01" name="value" value="${e(d.value)}" required></label>
            <label>Details / eligibility<textarea name="description" rows="2" placeholder="Describe who qualifies and any conditions">${e(d.description || '')}</textarea></label>
            <label>Status<select name="status">
              <option value="active" ${d.status === 'active' ? 'selected' : ''}>Active</option>
              <option value="inactive" ${d.status === 'inactive' ? 'selected' : ''}>Inactive</option>
            </select></label>
            <div class="actions">
              <button class="btn btn-secondary btn-sm" type="submit">Save</button>
              <button class="btn btn-danger btn-sm" type="button" data-delete-disc="${d.id}" data-confirm="Delete ${e(d.name)}?">Delete</button>
            </div>
          </form>
        </td>
      </tr>`
      );
      paginateTable(tbody, rowHtmls, {
        emptyHtml: '<tr><td colspan="5" class="empty">No additional discounts have been added yet.</td></tr>',
        onRender: bindDiscRows,
      });
      tbody.querySelectorAll('.disc-edit select[name="discount_type"]').forEach((typeSelect) => {
        const syncLimit = () => {
          const valueInput = typeSelect.form.querySelector('input[name="value"]');
          if (!valueInput) return;
          valueInput.max = typeSelect.value === 'percent' ? '100' : '';
        };
        typeSelect.addEventListener('change', syncLimit);
        syncLimit();
      });
    }

    document.getElementById('form-senior').addEventListener('submit', async (ev) => {
      ev.preventDefault();
      try {
        await apiPost('discounts.php', 'updateSenior', Object.fromEntries(new FormData(ev.target).entries()));
        location.reload();
      } catch (err) {
        showAlert(flash, 'error', err.message);
      }
    });
    document.getElementById('form-employee').addEventListener('submit', async (ev) => {
      ev.preventDefault();
      try {
        await apiPost('discounts.php', 'updateEmployee', Object.fromEntries(new FormData(ev.target).entries()));
        location.reload();
      } catch (err) {
        showAlert(flash, 'error', err.message);
      }
    });
    document.getElementById('form-pwd').addEventListener('submit', async (ev) => {
      ev.preventDefault();
      try {
        await apiPost('discounts.php', 'updatePwd', Object.fromEntries(new FormData(ev.target).entries()));
        location.reload();
      } catch (err) {
        showAlert(flash, 'error', err.message);
      }
    });
    bindCreateDiscountForm(flash);
  }

  function renderStayChangeHistory(tbody, extensions, transfers) {
    if (!tbody) return;
    const events = [
      ...(extensions || []).map((entry) => {
        const details = String(entry.details || '').replace(/^Booking #\d+\s*·\s*/, '');
        const room = details.match(/^Room\s+([^·]+)\s*·/);
        return {
          date: entry.created_at,
          change: entry.action === 'booking_reopen' ? 'Stay reopened and extended' : 'Stay extended',
          room: room ? `Room ${room[1].trim()}` : `Booking #${entry.booking_id}`,
          details: details.replace(/^Room\s+[^·]+\s*·\s*/, ''),
          updatedBy: entry.performed_by || 'Staff unavailable',
        };
      }),
      ...(transfers || []).map((entry) => ({
        date: `${entry.transfer_date} ${entry.transfer_time}`,
        change: 'Room transferred',
        room: `Room ${entry.from_room} → Room ${entry.to_room}`,
        details: [
          entry.reason || 'Room transfer',
          `${entry.remaining_nights} remaining night${Number(entry.remaining_nights) === 1 ? '' : 's'}`,
          `Rate adjustment ${money(entry.total_additional_charge)}`,
        ].join(' · '),
        updatedBy: entry.transferred_by_name || 'Staff unavailable',
      })),
    ].sort((left, right) => new Date(right.date.replace(' ', 'T')) - new Date(left.date.replace(' ', 'T')));
    paginateTable(tbody, events.map((event) => `<tr>
      <td data-label="Date &amp; Time">${fmtBillingDateTime(event.date)}</td>
      <td data-label="Change"><span class="badge badge-info">${e(event.change)}</span></td>
      <td data-label="Room">${e(event.room)}</td>
      <td data-label="Details">${e(event.details)}</td>
      <td data-label="Updated By">${e(event.updatedBy)}</td>
    </tr>`), {
      emptyHtml: '<tr><td colspan="5" class="empty">No extensions or room transfers have been recorded for these stays.</td></tr>',
    });
  }

  async function renderGuestAccount(main) {
    mountPageTemplate(main);
    const flash = document.getElementById('flash');
    const pendingPayment = sessionStorage.getItem('guest-account-flash');
    if (pendingPayment) {
      sessionStorage.removeItem('guest-account-flash');
      showAlert(flash, 'success', pendingPayment);
    }
    const params = {};
    if (qs('booking_id')) params.booking_id = Number(qs('booking_id'));
    if (qs('bill_id')) params.bill_id = Number(qs('bill_id'));
    if (!params.booking_id && !params.bill_id) {
      showAlert(flash, 'error', 'Select a guest account from Guest Billing.');
      return;
    }

    try {
      const res = await apiGet('bills.php', 'getGuestAccount', params);
      const account = res.data;
      document.getElementById('account-guest-name').textContent = account.guest.full_name;
      document.getElementById('account-guest-contact').textContent = [account.guest.phone, account.guest.email].filter(Boolean).join(' · ');
      document.getElementById('account-room-summary').textContent =
        `${account.room_count} room${account.room_count === 1 ? '' : 's'} · ${account.stay_count} stay${account.stay_count === 1 ? '' : 's'} · ${account.rooms.filter((room) => room.bill_id).length} bill${account.rooms.filter((room) => room.bill_id).length === 1 ? '' : 's'}`;
      document.getElementById('account-kpis').innerHTML = `
        <li><span>Full charges · all rooms</span><strong>${money(account.total_amount)}</strong></li>
        <li><span>Total paid</span><strong>${money(account.paid_amount)}</strong></li>
        <li><span>Balance due</span><strong>${money(account.balance)}</strong></li>`;
      const paymentPanel = document.getElementById('account-payment-panel');
      const paymentForm = document.getElementById('account-payment-form');
      const amountInput = document.getElementById('account-payment-amount');
      const methodInput = document.getElementById('account-payment-method');
      const referenceWrap = document.getElementById('account-payment-reference-wrap');
      const referenceInput = document.getElementById('account-payment-reference');
      const referenceLabel = document.getElementById('account-payment-reference-label');
      const submitPayment = document.getElementById('account-payment-submit');
      const hasOutstanding = Number(account.balance) > 0.009;
      paymentPanel.classList.toggle('hidden', !hasOutstanding);
      document.getElementById('account-payment-booking-id').value = account.booking_id || '';
      document.getElementById('account-payment-bill-id').value = account.bill_id || '';
      amountInput.max = Number(account.balance).toFixed(2);
      amountInput.value = Number(account.balance).toFixed(2);
      document.getElementById('account-pay-full').addEventListener('click', () => {
        amountInput.value = Number(account.balance).toFixed(2);
        paymentForm.requestSubmit();
      });
      methodInput.addEventListener('change', () => {
        const method = methodInput.value;
        const requiresReference = method !== 'cash';
        referenceWrap.hidden = !requiresReference;
        referenceInput.required = requiresReference;
        referenceInput.disabled = !requiresReference;
        if (!requiresReference) referenceInput.value = '';
        referenceInput.inputMode = method === 'gcash' ? 'numeric' : 'text';
        referenceLabel.textContent = method === 'gcash' ? 'GCash reference number (required)' : 'Bank transfer reference number (required)';
        referenceInput.placeholder = method === 'gcash' ? 'Enter at least 8 digits' : 'Enter at least 6 letters or numbers';
        const destination = document.getElementById('account-payment-destination');
        if (destination) {
          destination.innerHTML = paymentDestinationMarkup(method);
          destination.classList.toggle('hidden', method === 'cash');
        }
      });
      methodInput.dispatchEvent(new Event('change'));
      paymentForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const amount = Number(amountInput.value);
        if (Math.round(amount * 100) / 100 < 0.01) {
          showAlert(flash, 'error', 'Payment amount must be at least 0.01.');
          return;
        }
        if (amount > Number(account.balance) + 0.009) {
          showAlert(flash, 'error', `Enter an amount up to the outstanding balance of ${money(account.balance)}.`);
          return;
        }
        submitPayment.disabled = true;
        try {
          const payload = Object.fromEntries(new FormData(paymentForm).entries());
          await apiPost('payments.php', 'recordAccountPayment', payload);
          location.href = `guest-account.html?booking_id=${encodeURIComponent(account.booking_id || '')}`;
        } catch (err) {
          showAlert(flash, 'error', err.message);
        } finally {
          submitPayment.disabled = false;
        }
      });
      const tbody = document.getElementById('account-rooms-tbody');
      const rows = account.rooms.map((room) => {
        const stay = room.check_in
          ? `${fmtBillingDateTime(`${room.check_in} ${room.check_in_time || ''}`)} → ${fmtBillingDateTime(`${room.check_out} ${room.check_out_time || ''}`)}`
          : 'Manual guest account';
        const billCell = room.bill_id
          ? `${e(room.invoice_number)}<div class="cell-meta">Booking #${e(room.booking_id)}</div>`
          : '<span class="muted">No bill yet</span>';
        const actions = room.bill_id
          ? `<a class="btn btn-primary btn-sm" href="billing.html?id=${room.bill_id}">Manage Bill</a>
             ${Number(room.balance) > 0 ? `<a class="btn btn-teal btn-sm" href="payments.html?bill_id=${room.bill_id}">Take Payment</a>` : ''}`
          : '<span class="muted">No bill yet</span>';
        return `<tr>
          <td><strong>${room.room_number ? `Room ${e(room.room_number)} · ${e(room.room_type || '')}` : 'Manual account'}</strong><div class="cell-meta stay-dates-cell">${stay}</div></td>
          <td>${billCell}</td>
          <td class="numeric-cell">${money(room.total_amount || 0)}</td>
          <td class="numeric-cell">${money(room.paid_amount || 0)}</td>
          <td class="numeric-cell">${money(room.balance || 0)}</td>
          <td>${room.bill_status ? statusBadge(room.bill_status) : '<span class="badge badge-muted">No bill</span>'}</td>
          <td>${roomRequestsDropdown(room)}</td>
          <td class="actions">${actions}</td>
        </tr>`;
      });
      paginateTable(tbody, rows, {
        emptyHtml: '<tr><td colspan="8" class="empty">No rooms or bills are linked to this account.</td></tr>',
      });
      renderStayChangeHistory(
        document.getElementById('account-stay-changes-tbody'),
        account.extension_history,
        account.transfer_history
      );
      const paymentHistory = (account.payment_history || []).filter((payment) => Number(payment.amount) >= 0.01);
      document.getElementById('account-payment-history-summary').textContent =
        `${paymentHistory.length} payment${paymentHistory.length === 1 ? '' : 's'} recorded across this account.`;
      const paymentHistoryRows = paymentHistory.map((payment) => {
        const roomLabel = payment.room_number
          ? `Room ${e(payment.room_number)}`
          : 'Guest account';
        const billLabel = `${roomLabel}<div class="cell-meta">${e(payment.invoice_number || 'Bill')} · Booking #${e(payment.booking_id || '—')}</div>`;
        const methodDetails = `${payMethodBadge(payment.method)}${payment.reference_no ? `<div class="cell-meta">Ref ${e(payment.reference_no)}</div>` : ''}`;
        const receiverDetails = `<strong>${e(payment.cashier || 'Staff unavailable')}</strong>${payment.notes ? `<div class="cell-meta">${e(payment.notes)}</div>` : ''}`;
        return `<tr>
          <td data-label="Date &amp; Time">${fmtBillingDateTime(payment.paid_at)}</td>
          <td data-label="Room / Bill">${billLabel}</td>
          <td data-label="Amount">${money(payment.amount)}</td>
          <td data-label="Method / Reference">${methodDetails}</td>
          <td data-label="Received By / Note">${receiverDetails}</td>
          <td data-label="Receipt"><a class="btn btn-secondary btn-sm" href="${e(paymentReceiptExportUrl(payment.id))}" download>Export PDF</a></td>
        </tr>`;
      });
      paginateTable(document.getElementById('account-payment-history-tbody'), paymentHistoryRows, {
        emptyHtml: '<tr><td colspan="6" class="empty">No payments have been recorded for this account.</td></tr>',
      });
    } catch (err) {
      showAlert(flash, 'error', err.message);
      document.getElementById('account-rooms-tbody').innerHTML =
        '<tr><td colspan="7" class="empty">Unable to load this guest account.</td></tr>';
      document.getElementById('account-payment-history-tbody').innerHTML =
        '<tr><td colspan="6" class="empty">Unable to load payment history.</td></tr>';
    }
  }

  async function renderAdminBilling(main, options = {}) {
    return renderBillingFd(main, options);
  }

  /* ---------- Dependency-free SVG charts (no external library needed) ---------- */
  const CHART_COLORS = ['#0f766e', '#2563eb', '#d97706', '#9333ea', '#dc2626', '#0891b2', '#65a30d', '#db2777'];
  const chartNum = (n) => Number(n) || 0;
  const chartShort = (n) => {
    n = chartNum(n);
    if (Math.abs(n) >= 1e6) return `${(n / 1e6).toFixed(1)}M`;
    if (Math.abs(n) >= 1e3) return `${(n / 1e3).toFixed(1)}k`;
    return String(Math.round(n * 100) / 100);
  };
  const chartEmpty = (msg = 'No data for this period.') => `<div class="chart-empty">${e(msg)}</div>`;
  const chartLegend = (items) =>
    `<ul class="chart-legend">${items
      .map((i) => `<li><i style="background:${i.color}"></i>${e(i.label)} <b>${e(i.text)}</b></li>`)
      .join('')}</ul>`;

  function donutChart(data, fmt = (v) => chartShort(v), centerLabel = 'Total') {
    const items = data.filter((d) => chartNum(d.value) > 0);
    const total = items.reduce((s, d) => s + chartNum(d.value), 0);
    if (!total) return chartEmpty();
    const R = 70, C = 2 * Math.PI * R;
    let offset = 0;
    const arcs = items.map((d, i) => {
      const len = (chartNum(d.value) / total) * C;
      const arc = `<circle cx="100" cy="100" r="${R}" fill="none" stroke="${CHART_COLORS[i % 8]}" stroke-width="28"
        stroke-dasharray="${len} ${C - len}" stroke-dashoffset="${-offset}" transform="rotate(-90 100 100)"><title>${e(d.label)}: ${e(fmt(d.value))}</title></circle>`;
      offset += len;
      return arc;
    }).join('');
    const legend = chartLegend(items.map((d, i) => ({ color: CHART_COLORS[i % 8], label: d.label, text: `${fmt(d.value)} · ${Math.round((chartNum(d.value) / total) * 100)}%` })));
    return `<div class="donut-wrap"><svg viewBox="0 0 200 200" class="donut" role="img">${arcs}
      <text x="100" y="96" text-anchor="middle" class="donut-num">${e(fmt(total))}</text>
      <text x="100" y="116" text-anchor="middle" class="donut-sub">${e(centerLabel)}</text></svg>${legend}</div>`;
  }

  function barChart(data, { fmt = chartShort, horizontal = false, series = null } = {}) {
    if (!data.length || data.every((d) => !chartNum(d.value) && !(series || []).some((s) => chartNum(d[s.key])))) return chartEmpty();
    if (horizontal) {
      const max = Math.max(...data.map((d) => chartNum(d.value)), 1);
      return `<div class="hbars">${data
        .map((d, i) => `<div class="hbar"><span class="hbar-label" title="${e(d.label)}">${e(d.label)}</span>
          <div class="hbar-track"><div class="hbar-fill" style="width:${Math.max(2, (chartNum(d.value) / max) * 100)}%;background:${CHART_COLORS[i % 8]}"></div></div>
          <b>${e(fmt(d.value))}</b></div>`)
        .join('')}</div>`;
    }
    const keys = series ? series.map((s) => s.key) : ['value'];
    const colors = series ? series.map((s) => s.color) : [CHART_COLORS[0]];
    const max = Math.max(...data.flatMap((d) => keys.map((k) => chartNum(d[k]))), 1);
    const W = 320, H = 200, padL = 36, padB = 26, padT = 10, plotH = H - padB - padT, plotW = W - padL - 6;
    const group = plotW / data.length, bw = Math.min(30, (group - 8) / keys.length);
    const grid = [0, 0.5, 1].map((t) => {
      const y = padT + plotH - t * plotH;
      return `<line x1="${padL}" x2="${W - 6}" y1="${y}" y2="${y}" class="grid-line"/><text x="${padL - 4}" y="${y + 3}" text-anchor="end" class="axis">${e(chartShort(max * t))}</text>`;
    }).join('');
    const bars = data.map((d, gi) => {
      const gx = padL + gi * group + (group - bw * keys.length) / 2;
      const rects = keys.map((k, ki) => {
        const h = (chartNum(d[k]) / max) * plotH;
        return `<rect x="${gx + ki * bw}" y="${padT + plotH - h}" width="${bw - 2}" height="${h}" rx="3" fill="${colors[ki]}"><title>${e(d.label)}: ${e(fmt(d[k]))}</title></rect>`;
      }).join('');
      return `${rects}<text x="${padL + gi * group + group / 2}" y="${H - 8}" text-anchor="middle" class="axis">${e(String(d.label).slice(0, 8))}</text>`;
    }).join('');
    const legend = series ? chartLegend(series.map((s) => ({ color: s.color, label: s.name, text: '' }))) : '';
    return `<svg viewBox="0 0 ${W} ${H}" class="bars" role="img">${grid}${bars}</svg>${legend}`;
  }

  function areaChart(data, fmt = chartShort) {
    if (!data.length) return chartEmpty();
    const W = 640, H = 220, padL = 44, padB = 28, padT = 12, plotH = H - padB - padT, plotW = W - padL - 12;
    const vals = data.map((d) => chartNum(d.value));
    const max = Math.max(...vals, 1);
    const x = (i) => padL + (data.length === 1 ? plotW / 2 : (i / (data.length - 1)) * plotW);
    const y = (v) => padT + plotH - (v / max) * plotH;
    const line = data.map((d, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(chartNum(d.value)).toFixed(1)}`).join(' ');
    const area = `${line} L${x(data.length - 1).toFixed(1)},${padT + plotH} L${x(0).toFixed(1)},${padT + plotH} Z`;
    const grid = [0, 0.25, 0.5, 0.75, 1].map((t) => {
      const gy = padT + plotH - t * plotH;
      return `<line x1="${padL}" x2="${W - 12}" y1="${gy}" y2="${gy}" class="grid-line"/><text x="${padL - 6}" y="${gy + 3}" text-anchor="end" class="axis">${e(fmt(max * t))}</text>`;
    }).join('');
    const step = Math.max(1, Math.ceil(data.length / 8));
    const labels = data.map((d, i) => (i % step === 0 ? `<text x="${x(i)}" y="${H - 8}" text-anchor="middle" class="axis">${e(String(d.label).slice(5))}</text>` : '')).join('');
    const dots = data.map((d, i) => `<circle cx="${x(i)}" cy="${y(chartNum(d.value))}" r="4" fill="#0f766e"><title>${e(d.label)}: ${e(money(d.value))}</title></circle>`).join('');
    return `<svg viewBox="0 0 ${W} ${H}" class="area" role="img"><defs><linearGradient id="areaFill" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#0f766e" stop-opacity=".45"/><stop offset="1" stop-color="#0f766e" stop-opacity=".02"/></linearGradient></defs>
      ${grid}<path d="${area}" fill="url(#areaFill)"/><path d="${line}" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linejoin="round"/>${dots}${labels}</svg>`;
  }

  function gaugeChart(percent) {
    const p = Math.max(0, Math.min(100, chartNum(percent)));
    const R = 70, C = Math.PI * R;
    return `<svg viewBox="0 0 200 120" class="gauge" role="img"><path d="M30,100 A70,70 0 0 1 170,100" fill="none" stroke="#e2e8f0" stroke-width="22" stroke-linecap="round"/>
      <path d="M30,100 A70,70 0 0 1 170,100" fill="none" stroke="${p >= 75 ? '#0f766e' : p >= 40 ? '#d97706' : '#dc2626'}" stroke-width="22" stroke-linecap="round" stroke-dasharray="${(p / 100) * C} ${C}"/>
      <text x="100" y="92" text-anchor="middle" class="donut-num">${p.toFixed(1)}%</text><text x="100" y="112" text-anchor="middle" class="donut-sub">of billed amount collected</text></svg>`;
  }

  function paintReportCharts(d) {
    const ch = d.charts || {};
    const put = (id, html) => {
      const el = document.getElementById(id);
      if (el) el.innerHTML = html;
    };
    const titleCase = (s) => String(s || '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
    const mapLabel = (rows, fn = titleCase) => (rows || []).map((r) => ({ ...r, label: fn(r.label) }));
    const days = ['', 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    // Fill quiet days with zero so the trend line spans the whole period (capped at 45 days).
    const byDay = Object.fromEntries((ch.daily || []).map((r) => [r.label, chartNum(r.value)]));
    const series = [];
    for (let t = new Date(`${d.to}T00:00:00`), n = 0; n < 45 && t >= new Date(`${d.from}T00:00:00`); n++, t.setDate(t.getDate() - 1)) {
      const key = `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(t.getDate()).padStart(2, '0')}`;
      series.unshift({ label: key, value: byDay[key] || 0 });
    }
    put('ch-daily', areaChart(series, chartShort));
    put('ch-monthly', barChart(ch.monthly || [], { series: [{ key: 'billed', name: 'Billed', color: '#2563eb' }, { key: 'collected', name: 'Collected', color: '#0f766e' }] }));
    put('ch-methods', donutChart(mapLabel(ch.methods, (l) => (l === 'online' ? 'GCash' : l === 'bank' ? 'Bank Transfer' : 'Cash (counter)')), money, 'Collected'));
    put('ch-status', donutChart((d.by_status || []).map((r) => ({ label: titleCase(r.status), value: r.cnt })), (v) => String(Math.round(v)), 'Bills'));
    put('ch-types', barChart(mapLabel(ch.bill_types), { horizontal: true, fmt: money }));
    put('ch-services', barChart((d.services || []).slice(0, 6).map((r) => ({ label: r.description, value: r.total })), { horizontal: true, fmt: money }));
    put('ch-weekday', barChart((ch.weekday || []).map((r) => ({ ...r, label: days[r.label] || r.label }))));
    put('ch-rooms', donutChart(mapLabel(ch.rooms), (v) => String(Math.round(v)), 'Rooms'));
    put('ch-roomtypes', barChart(mapLabel(ch.room_types), { fmt: (v) => String(Math.round(v)) }));
    put('ch-bookings', donutChart(mapLabel(ch.bookings), (v) => String(Math.round(v)), 'Stays'));
    put('ch-reqtypes', barChart(mapLabel(ch.request_types), { horizontal: true, fmt: (v) => String(Math.round(v)) }));
    put('ch-reqstatus', donutChart(mapLabel(ch.request_status), (v) => String(Math.round(v)), 'Requests'));
    put('ch-guests', barChart(ch.top_guests || [], { horizontal: true, fmt: money }));
    const billed = chartNum(d.summary?.billed);
    put('ch-rate', gaugeChart(billed ? (chartNum(d.summary?.paid) / billed) * 100 : 0));
  }

  async function renderReports(main) {
    mountPageTemplate(main);
    const today = localStamp().slice(0, 10);
    const from = qs('from') || `${today.slice(0, 7)}-01`;
    const to = qs('to') || today;
    const res = await apiGet('reports.php', 'getReport', { from, to });
    const d = res.data;
    const summary = d.summary || {};
    const collected = d.collected || {};
    const exportBase = `${API_BASE}/reports_export.php`;

    const fromInput = document.querySelector('#report-filter [name="from"]');
    const toInput = document.querySelector('#report-filter [name="to"]');
    if (fromInput) fromInput.value = d.from;
    if (toInput) toInput.value = d.to;
    const period = document.getElementById('report-period');
    if (period) period.textContent = `Period ${d.from} → ${d.to} · Generated ${d.generated_at || ''}`;

    setStatValues(document.querySelector('#report-summary .grid.grid-4'), [
      String(summary.bill_count || 0),
      money(summary.billed),
      money(collected.collected),
      money(summary.outstanding),
    ]);

    paintReportCharts(d);

    const byStatus = document.getElementById('report-by-status-tbody');
    if (byStatus) {
      const rowHtmls = (d.by_status || []).map(
        (r) => `<tr><td>${statusBadge(r.status)}</td><td>${e(r.cnt || 0)}</td><td>${money(r.total)}</td></tr>`
      );
      paginateTable(byStatus, rowHtmls, { emptyHtml: '<tr><td colspan="3" class="empty">No bills in this range.</td></tr>' });
    }

    const collections = document.getElementById('report-collections');
    if (collections) {
      collections.innerHTML = `
      <li><span>Payments</span><strong>${e(collected.payment_count || 0)}</strong></li>
      <li><span>Amount collected</span><strong>${money(collected.collected)}</strong></li>
      <li><span>Paid on bills</span><strong>${money(summary.paid)}</strong></li>`;
    }

    const daily = document.getElementById('report-daily-tbody');
    if (daily) {
      const rowHtmls = (d.daily || []).map((r) => `<tr><td>${e(r.d)}</td><td>${money(r.total)}</td></tr>`);
      paginateTable(daily, rowHtmls, { emptyHtml: '<tr><td colspan="2" class="empty">No payments in this range.</td></tr>' });
    }

    const services = document.getElementById('report-services-tbody');
    if (services) {
      const rowHtmls = (d.services || []).map((r) => `<tr><td>${e(r.description)}</td><td>${money(r.total)}</td></tr>`);
      paginateTable(services, rowHtmls, { emptyHtml: '<tr><td colspan="2" class="empty">No service revenue in this range.</td></tr>' });
    }

    const syncExports = () => {
      const f = document.querySelector('#report-filter [name="from"]')?.value || d.from;
      const t = document.querySelector('#report-filter [name="to"]')?.value || d.to;
      const excel = document.getElementById('export-excel');
      const pdf = document.getElementById('export-pdf');
      const validRange = Boolean(f && t && f <= t);
      if (excel) {
        excel.href = validRange ? `${exportBase}?format=excel&from=${encodeURIComponent(f)}&to=${encodeURIComponent(t)}` : '#';
        excel.setAttribute('aria-disabled', String(!validRange));
      }
      if (pdf) {
        pdf.href = validRange ? `${exportBase}?format=pdf&from=${encodeURIComponent(f)}&to=${encodeURIComponent(t)}` : '#';
        pdf.setAttribute('aria-disabled', String(!validRange));
      }
    };
    syncExports();

    document.getElementById('report-filter').addEventListener('submit', (ev) => {
      ev.preventDefault();
      const fd = new FormData(ev.target);
      const filterFrom = String(fd.get('from') || '');
      const filterTo = String(fd.get('to') || '');
      if (!filterFrom || !filterTo || filterFrom > filterTo) {
        showAlert(document.getElementById('flash'), 'error', 'Choose a valid report date range.');
        return;
      }
      location.href = `reports.html?from=${encodeURIComponent(filterFrom)}&to=${encodeURIComponent(filterTo)}`;
    });
    document.getElementById('report-filter').addEventListener('change', syncExports);
  }

  async function renderAudit(main, staffOnly) {
    mountPageTemplate(main);
    const op = staffOnly ? 'getStaffLogs' : 'getSystemLogs';
    const res = await apiGet('audit.php', op);
    const logs = res.data || [];
    const tbody = document.getElementById('audit-tbody');
    if (tbody) {
      const roleNames = {
        admin: 'Admin', frontdesk: 'Front Desk', guest: 'Guest',
        kitchen: 'Kitchen', laundry: 'Amenities & Laundry Staff', housekeeping: 'Housekeeping',
      };
      const rowHtmls = logs.map(
        (l) => `<tr>
    <td>${e(l.created_at)}</td>
    <td><strong>${e(l.full_name || l.email || 'System')}</strong>${l.email ? `<div class="cell-meta">${e(l.email)}</div>` : ''}</td>
    <td>${l.role ? `<span class="badge badge-info">${e(roleNames[l.role] || l.role)}</span>` : '<span class="badge badge-muted">System</span>'}</td>
    <td>${e(String(l.action || '').replace(/_/g, ' '))}</td><td>${e(l.details || '')}</td></tr>`
      );
      paginateTable(tbody, rowHtmls, { emptyHtml: '<tr><td colspan="5" class="empty">No logs.</td></tr>' });
    }
  }

  function splitGuestName(fullName, storedFirstName = '', storedLastName = '') {
    if (storedFirstName || storedLastName) {
      return { firstName: storedFirstName, lastName: storedLastName };
    }
    const [firstName = '', ...lastNameParts] = String(fullName || '').trim().split(/\s+/).filter(Boolean);
    return { firstName, lastName: lastNameParts.join(' ') };
  }

  function bindGuestAppointmentLookup(onLinked) {
    const form = document.getElementById('guest-appointment-lookup');
    const preview = document.getElementById('guest-appointment-preview');
    if (!form || !preview) return;
    let appointmentCode = '';
    let appointment = null;
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const codeField = form.elements.appointment_code;
      const button = form.querySelector('[type="submit"]');
      appointmentCode = '';
      appointment = null;
      codeField.value = String(codeField.value || '').trim().toUpperCase();
      button.disabled = true;
      try {
        const response = await apiGet('appointments.php', 'getPublicAppointment', {
          appointment_code: codeField.value,
        });
        appointment = response.data;
        if (appointment.converted_at) throw new Error('This appointment has already been checked in.');
        appointmentCode = codeField.value;
        preview.innerHTML = `<div class="panel appointment-detail">
          <h3>${e(appointment.full_name)}</h3>
          <div class="appointment-detail-grid">
            <p><strong>Email</strong><span>${e(appointment.email)}</span></p>
            <p><strong>Phone</strong><span>${e(appointment.phone)}</span></p>
            <p><strong>Stay</strong><span>${appointmentDateTimeLabel(appointment.check_in, appointment.check_in_time)} – ${appointmentDateTimeLabel(appointment.check_out, appointment.check_out_time)}</span></p>
            <p><strong>Rooms</strong><span>${e(appointment.assigned_room_numbers || `${appointment.room_type_name} · ${appointment.room_count} room(s)`)}</span></p>
            <p><strong>Guests</strong><span>${e(appointment.adults)} adults · ${e(appointment.children)} children</span></p>
            <p><strong>Deposit</strong><span>${money(appointment.deposit_amount || 0)} · ${e(appointment.payment_status)}</span></p>
          </div>
          <p>${statusBadge(appointment.status)} ${Number(appointment.account_linked) === 1 ? 'An account is already linked to this appointment.' : 'No guest account has been linked yet.'}</p>
          ${['pending', 'confirmed'].includes(appointment.status)
            ? `<button type="button" class="btn btn-primary" data-create-appointment-account>${Number(appointment.account_linked) === 1 ? 'Verify account link' : 'Create or link guest account'}</button>`
            : '<p class="field-hint">Only pending or confirmed appointments can be linked to a guest account.</p>'}
        </div>`;
      } catch (error) {
        preview.innerHTML = `<div class="alert alert-error">${e(error.message)}</div>`;
      } finally {
        button.disabled = false;
      }
    });
    preview.addEventListener('click', async (event) => {
      const button = event.target.closest('[data-create-appointment-account]');
      if (!button || !appointment || !appointmentCode) return;
      button.disabled = true;
      try {
        const response = await apiPost('appointments.php', 'claimGuestAccount', {
          appointment_code: appointmentCode,
        });
        const result = response.data;
        preview.innerHTML = `<div class="alert alert-success">
          <strong>${result.account_status === 'created' ? 'Guest account created.' : 'Existing guest account linked.'}</strong>
          <p>${e(response.message)} ${e(result.email || '')}</p>
          <p>Appointment details are saved and linked to this account. ${result.account_status === 'created' ? 'The appointment code is the initial password; ask the guest to change it after signing in.' : 'The existing password is unchanged.'}</p>
        </div>`;
        await onLinked();
      } catch (error) {
        showAlert(document.getElementById('flash'), 'error', error.message);
        button.disabled = false;
      }
    });
  }

  async function renderGuests(main, options = {}) {
    if (!options.skipMount) mountPageTemplate(main);
    const q = qs('q');
    const searchInput = document.querySelector('#guest-search input[name="q"]');
    if (searchInput) searchInput.value = q;
    const tbody = document.getElementById('guests-tbody');
    const passwordDialog = document.createElement('dialog');
    passwordDialog.id = 'guest-password-reset-dialog';
    passwordDialog.className = 'guest-password-dialog';
    passwordDialog.setAttribute('aria-labelledby', 'guest-password-reset-title');
    passwordDialog.innerHTML = `
      <form id="guest-password-reset-form" class="form-stack">
        <h2 id="guest-password-reset-title">Reset guest password</h2>
        <p class="muted">Enter a unique password with at least 12 characters. Avoid the guest's name and email.</p>
        <div>
          <label for="guest-new-password">New password</label>
          <input id="guest-new-password" name="password" type="password" minlength="12" autocomplete="new-password" required>
        </div>
        <p id="guest-password-reset-error" class="alert alert-error" role="alert" hidden></p>
        <div class="actions guest-password-dialog-actions">
          <button class="btn btn-secondary" type="button" data-password-cancel>Cancel</button>
          <button class="btn btn-primary" type="submit">Reset password</button>
        </div>
      </form>`;
    main.append(passwordDialog);
    const passwordForm = passwordDialog.querySelector('#guest-password-reset-form');
    const passwordInput = passwordForm.elements.password;
    const passwordError = passwordDialog.querySelector('#guest-password-reset-error');
    const passwordSubmit = passwordForm.querySelector('[type="submit"]');
    const passwordCancel = passwordDialog.querySelector('[data-password-cancel]');
    let activeResetButton = null;
    passwordDialog.addEventListener('close', () => {
      passwordForm.reset();
      passwordError.hidden = true;
      passwordError.textContent = '';
      passwordSubmit.disabled = false;
      passwordCancel.disabled = false;
      if (activeResetButton?.isConnected) activeResetButton.focus();
      activeResetButton = null;
    });
    passwordDialog.addEventListener('cancel', (event) => {
      if (passwordSubmit.disabled) event.preventDefault();
    });
    passwordCancel.addEventListener('click', () => passwordDialog.close());
    passwordForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (!passwordForm.reportValidity()) return;
      passwordError.hidden = true;
      passwordError.textContent = '';
      passwordSubmit.disabled = true;
      passwordCancel.disabled = true;
      try {
        await apiPost('guests.php', 'resetPortalPassword', {
          id: activeResetButton?.dataset.guestReset,
          password: passwordInput.value,
        });
        passwordDialog.close();
        showAlert(document.getElementById('flash'), 'success', 'Guest portal password reset.');
      } catch (error) {
        passwordError.textContent = error.message;
        passwordError.hidden = false;
        passwordSubmit.disabled = false;
        passwordCancel.disabled = false;
        passwordInput.focus();
      }
    });
    const renderRows = async () => {
      const res = await apiGet('guests.php', 'getAllGuests', q ? { q } : {});
      const guests = res.data || [];
      if (!tbody) return;
      const rowHtmls = guests.map(
        (g) => {
          const { firstName, lastName } = splitGuestName(g.full_name, g.first_name, g.last_name);
          return `<tr>
      <td>${e(g.full_name)}</td>
      <td>${e(firstName || '—')}</td>
      <td>${e(lastName || '—')}</td>
      <td>${e(g.email || '')}</td>
      <td>${e(g.phone || '')}</td>
      <td>${Number(g.has_portal) === 1 ? statusBadge(g.portal_status || 'active') : '<span class="muted">Walk-in</span>'}</td>
      <td>${Number(g.has_portal) === 1 ? `<div class="actions">
        <button class="btn btn-secondary btn-sm" type="button" data-guest-reset="${e(g.id)}">Reset password</button>
        <button class="btn ${g.portal_status === 'active' ? 'btn-danger' : 'btn-primary'} btn-sm" type="button" data-guest-status="${e(g.id)}" data-status="${g.portal_status === 'active' ? 'inactive' : 'active'}">${g.portal_status === 'active' ? 'Delete account' : 'Restore account'}</button>
      </div>` : '<span class="muted">No portal actions</span>'}</td>
    </tr>`;
        }
      );
      paginateTable(tbody, rowHtmls, {
        emptyHtml: '<tr><td colspan="7" class="empty">No guests found.</td></tr>',
        onRender: (table) => {
          table.querySelectorAll('[data-guest-reset]').forEach((button) => {
            button.addEventListener('click', () => {
              activeResetButton = button;
              passwordError.hidden = true;
              passwordError.textContent = '';
              passwordDialog.showModal();
              passwordInput.focus();
            });
          });
          table.querySelectorAll('[data-guest-status]').forEach((button) => {
            button.addEventListener('click', async () => {
              const inactive = button.dataset.status === 'inactive';
              if (!window.confirm(inactive
                ? 'Delete this guest portal account? Sign-in will be disabled, but the guest profile, bookings, bills, and request history will be retained. You can restore the account later.'
                : 'Restore this guest portal account and allow the guest to sign in again?')) return;
              button.disabled = true;
              try {
                await apiPost('guests.php', 'setPortalStatus', {
                  id: button.dataset.guestStatus,
                  status: button.dataset.status,
                });
                await renderRows();
                showAlert(document.getElementById('flash'), 'success', inactive
                  ? 'Guest portal account deleted. Sign-in is disabled; guest and transaction history were retained.'
                  : 'Guest portal account restored. The guest can sign in again.');
              } catch (error) {
                showAlert(document.getElementById('flash'), 'error', error.message);
                button.disabled = false;
              }
            });
          });
        },
      });
    };
    try {
      await renderRows();
    } catch (error) {
      if (tbody) paginateTable(tbody, [], { emptyHtml: `<tr><td colspan="7" class="empty">${e(error.message)}</td></tr>` });
    }

    bindGuestAppointmentLookup(renderRows);

    document.getElementById('guest-search').addEventListener('submit', (ev) => {
      ev.preventDefault();
      location.href = `guests.html?q=${encodeURIComponent(new FormData(ev.target).get('q') || '')}`;
    });
    document.getElementById('guest-create').addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const fd = new FormData(ev.target);
      const payload = Object.fromEntries(fd.entries());
      payload.create_login = fd.get('create_login') ? 1 : 0;
      try {
        await apiPost('guests.php', 'createQuickGuest', payload);
        location.reload();
      } catch (err) {
        showAlert(document.getElementById('flash'), 'error', err.message);
      }
    });
    const quickCreate = document.getElementById('guest-create');
    const quickCreateLogin = quickCreate?.querySelector('[name="create_login"]');
    const quickName = quickCreate?.querySelector('[name="full_name"]');
    const quickPasswordWrap = quickCreate?.querySelector('#quick-guest-password-wrap');
    const quickPassword = quickCreate?.querySelector('#quick-guest-default-password');
    const syncQuickPassword = () => {
      if (quickPasswordWrap) quickPasswordWrap.hidden = !quickCreateLogin?.checked;
      if (quickPassword) quickPassword.value = splitGuestName(quickName?.value || '').lastName;
    };
    quickCreateLogin?.addEventListener('change', syncQuickPassword);
    quickName?.addEventListener('input', syncQuickPassword);
    syncQuickPassword();
  }

  async function renderGuestRegister(main, options = {}) {
    if (!options.skipMount && !mountPageTemplate(main, 'register-guest')) {
      main.innerHTML = '<div class="alert alert-error">Register Guest template missing.</div>';
      return;
    }

    const q = qs('q');
    const searchInput = main.querySelector('#guest-reg-search input[name="q"]');
    if (searchInput) searchInput.value = q;

    const flash = document.getElementById('flash');
    const tbody = document.getElementById('guest-reg-tbody');
    const createLogin = document.getElementById('gr-create-login');
    const passwordWrap = document.getElementById('gr-password-wrap');
    bindGuestAppointmentLookup(async () => {
      const response = await apiGet('guests.php', 'getRegisteredGuests', q ? { q } : {});
      const guests = response.data || [];
      const rows = guests.map((guest) => `<tr>
        <td><strong>${e(guest.full_name)}</strong></td>
        <td>${e(guest.first_name || '—')}</td>
        <td>${e(guest.last_name || '—')}</td>
        <td>${e(guest.email || '—')}</td>
        <td>${e(guest.phone || '—')}</td>
        <td>${Number(guest.has_portal) === 1 || Number(guest.portal_created) === 1
          ? `${statusBadge(guest.portal_status || 'active')}<div class="cell-meta">${e(guest.portal_email || guest.email || '')}</div>`
          : '<span class="muted">No login (walk-in)</span>'}</td>
      </tr>`);
      paginateTable(tbody, rows, {
        emptyHtml: '<tr><td colspan="6" class="empty">No guests registered by front desk yet.</td></tr>',
      });
    });

    const syncPassword = () => {
      if (!passwordWrap) return;
      passwordWrap.hidden = !(createLogin?.checked);
      const password = passwordWrap.querySelector('[name="password"]');
      if (password) password.required = Boolean(createLogin?.checked);
    };
    createLogin?.addEventListener('change', syncPassword);
    syncPassword();

    try {
      const res = await apiGet('guests.php', 'getRegisteredGuests', q ? { q } : {});
      const guests = res.data || [];
      const regMeta = (g) => {
        const parts = [];
        if (g.registered_by_name) parts.push('By ' + g.registered_by_name);
        if (g.registered_at) parts.push(g.registered_at);
        return parts.length ? `<div class="cell-meta">${e(parts.join(' · '))}</div>` : '';
      };
      const rowHtmls = guests.map(
        (g) => {
          const { firstName, lastName } = splitGuestName(g.full_name, g.first_name, g.last_name);
          return `<tr>
        <td><strong>${e(g.full_name)}</strong>${regMeta(g)}</td>
        <td>${e(firstName || '—')}</td>
        <td>${e(lastName || '—')}</td>
        <td>${e(g.email || '—')}</td>
        <td>${e(g.phone || '—')}</td>
        <td>${
          Number(g.has_portal) === 1 || Number(g.portal_created) === 1
            ? `${statusBadge(g.portal_status || 'active')}<div class="cell-meta">${e(g.portal_email || g.email || '')}</div>`
            : '<span class="muted">No login (walk-in)</span>'
        }</td>
      </tr>`;
        }
      );
      paginateTable(tbody, rowHtmls, {
        emptyHtml: '<tr><td colspan="6" class="empty">No guests registered by front desk yet.</td></tr>',
      });
    } catch (err) {
      paginateTable(tbody, [], { emptyHtml: `<tr><td colspan="6" class="empty">${e(err.message)}</td></tr>` });
    }

    document.getElementById('guest-reg-search')?.addEventListener('submit', (ev) => {
      ev.preventDefault();
      const term = new FormData(ev.target).get('q') || '';
      location.href = `guests.html?tab=register&q=${encodeURIComponent(String(term))}`;
    });

    document.getElementById('guest-register-form')?.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const fd = new FormData(ev.target);
      const payload = Object.fromEntries(fd.entries());
      payload.create_login = fd.get('create_login') ? 1 : 0;
      try {
        await apiPost('guests.php', 'createGuest', payload);
        location.href = 'guests.html?tab=register';
      } catch (err) {
        showAlert(flash, 'error', err.message);
      }
    });
  }

  async function renderCheckIn(main, options = {}) {
    if (!options.skipMount) mountPageTemplate(main);
    fillTimeSelects(main);
    enhanceDateTimeFields(main);
    const localDate = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    const addDay = (value) => {
      const date = new Date(`${value}T00:00:00`);
      date.setDate(date.getDate() + 1);
      return localDate(date);
    };
    const today = localDate(new Date());
    const res = await apiGet('bookings.php', 'getCheckinData');
    const { bookings, guests } = res.data;
    const taxRate = Number(res.data.tax_rate || 0);
    const reserved = (bookings || []).filter((b) => b.status === 'reserved');
    const guestOptions = (guests || [])
      .map((g) => {
        const portal = Number(g.has_portal) === 1 ? ' · Portal user' : '';
        const email = g.email || g.user_email || '';
        return `<option value="${g.id}">${e(g.full_name)}${email ? ' · ' + e(email) : ''}${portal}</option>`;
      })
      .join('');
    const flash = document.getElementById('flash');
    const bookingSubmit = document.querySelector('#booking-create button[type="submit"]');
    let billAndPaymentArePositive = false;
    const bindCheckInRows = (tb) => {
      enhanceDateTimeFields(tb);
      tb.querySelectorAll('.stay-dates').forEach((form) => {
        const checkInDate = form.elements.check_in;
        const checkOutDate = form.elements.check_out;
        checkInDate.min = today;
        const setCheckoutMinimum = () => {
          const nextNight = addDay(checkInDate.value >= today ? checkInDate.value : today);
          checkOutDate.min = nextNight;
          if (checkOutDate.value && checkOutDate.value < nextNight) checkOutDate.value = nextNight;
        };
        setCheckoutMinimum();
        checkInDate.addEventListener('change', setCheckoutMinimum);
        form.addEventListener('submit', async (ev) => {
          ev.preventDefault();
          const payload = Object.fromEntries(new FormData(form).entries());
          payload.id = form.getAttribute('data-id');
          try {
            await apiPost('bookings.php', 'updateStayDates', payload);
            location.reload();
          } catch (err) {
            showAlert(flash, 'error', err.message);
          }
        });
      });
      tb.querySelectorAll('[data-in]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          try {
            const r = await apiPost('bookings.php', 'checkIn', { id: btn.getAttribute('data-in') });
            sessionStorage.setItem('billing-flash', r.message || 'Guest checked in.');
            const roleFolder = document.body.dataset.role === 'admin' ? 'admin' : 'frontdesk';
            location.href = `${BASE_PATH}/${roleFolder}/billing.html?booking_id=${r.data?.booking_id || btn.getAttribute('data-in')}`;
          } catch (err) {
            showAlert(flash, 'error', err.message);
          }
        });
      });
    };

    const tbody = document.getElementById('checkin-tbody');
    if (tbody) {
      const rowHtmls = reserved.map(
        (b) => `<tr>
      <td>${e(b.full_name)}</td>
      <td>${e(b.room_number)} · ${e(b.room_type)}</td>
      <td>
        <form class="form-stack stay-dates" data-id="${b.id}">
          <div class="dt-field" data-time-policy="check-in"><label>Check-in date and time</label><div class="dt-row"><input type="date" name="check_in" min="${today}" value="${e(b.check_in)}" required>${timeOptions(b.check_in_time, '15:00', 'check_in_time', false, 'check-in')}</div></div>
          <div class="dt-field" data-time-policy="check-out"><label>Check-out date and time</label><div class="dt-row"><input type="date" name="check_out" min="${today}" value="${e(b.check_out)}" required>${timeOptions(b.check_out_time, '14:00', 'check_out_time', false, 'check-out')}</div></div>
          <button class="btn btn-secondary btn-sm" type="submit">Save dates</button>
        </form>
      </td>
      <td>${statusBadge(b.status)}</td>
      <td><button class="btn btn-primary btn-sm" data-in="${b.id}">Check-In</button></td>
    </tr>`
      );
      paginateTable(tbody, rowHtmls, {
        emptyHtml: '<tr><td colspan="5" class="empty">No reserved bookings waiting for check-in.</td></tr>',
        onRender: bindCheckInRows,
      });
    }

    const inHouseTbody = document.getElementById('inhouse-tbody');
    if (inHouseTbody) {
      const roleFolder = document.body.dataset.role === 'admin' ? 'admin' : 'frontdesk';
      const staffBase = `${BASE_PATH}/${roleFolder}`;
      const rowHtmls = (res.data.in_house || []).map(
        (b) => `<tr>
      <td>${e(b.full_name)}<div class="cell-meta">Booking #${e(b.id)}</div></td>
      <td><strong>${e(b.room_number)}</strong> · ${e(b.room_type)}</td>
      <td>${fmtStayPoint(b.check_in, b.check_in_time)} → ${fmtStayPoint(b.check_out, b.check_out_time)}</td>
      <td>${b.bill_id ? `${e(b.invoice_number)} ${statusBadge(b.bill_status)}` : '<span class="muted">No account yet</span>'}</td>
      <td>${b.bill_id ? money(b.total_amount) : '—'}</td>
      <td>${b.bill_id ? money(b.balance) : '—'}</td>
      <td class="actions">
        <a class="btn btn-primary btn-sm" href="${staffBase}/billing.html?booking_id=${b.id}">Add Charges</a>
        <a class="btn btn-secondary btn-sm" href="${staffBase}/room-management.html?tab=extend-stay&amp;booking_id=${b.id}">Extend Stay</a>
      </td>
    </tr>`
      );
      paginateTable(inHouseTbody, rowHtmls, {
        emptyHtml: '<tr><td colspan="7" class="empty">No guests are checked in right now.</td></tr>',
      });
    }

    const guestSel = document.getElementById('booking-guest-select');
    if (guestSel) guestSel.innerHTML = `<option value="">Select guest…</option>${guestOptions}`;
    const checkInInput = document.getElementById('check-in-at');
    const checkOutInput = document.getElementById('check-out-at');
    const toLocalStamp = (date) => {
      const pad = (value) => String(value).padStart(2, '0');
      return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
    };
    const stampDate = (v) => (v || '').slice(0, 10);
    const minCheckOut = () => addDay(stampDate(checkInInput.value) || today);
    const setNewStayCheckoutMinimum = () => {
      if (!checkInInput || !checkOutInput) return;
      checkInInput.min = today;
      if (checkInInput.value && checkInInput.value < checkInInput.min) {
        checkInInput.value = today;
        showAlert(flash, 'error', 'Past check-in dates are not allowed.');
      }
      checkOutInput.min = minCheckOut();
      if (!checkOutInput.value || checkOutInput.value < checkOutInput.min) {
        checkOutInput.value = checkOutInput.min;
      }
    };
    if (checkInInput) {
      const requestedDate = qs('date');
      checkInInput.value = requestedDate && requestedDate >= today ? requestedDate : today;
    }
    setNewStayCheckoutMinimum();
    let stayRooms = [];
    const typeSel = document.getElementById('billing-type');
    const discountSel = document.getElementById('billing-discount');
    let discountList = [];
    try {
      discountList = (await apiGet('discounts.php', 'getActiveDiscounts')).data || [];
    } catch (err) {
      showAlert(flash, 'error', `Discounts could not be loaded: ${err.message}`);
    }
    const discountEligibilityWrap = document.getElementById('discount-eligibility-wrap');
    const discountEligibilityDetails = document.getElementById('discount-eligibility-details');
    const discountEligibilityCheck = document.getElementById('discount-eligibility-confirmed');
    if (discountSel) {
      discountSel.innerHTML = '<option value="">No discount</option>' + discountList
        .map((d) => `<option value="${d.id}">${e(d.name)} (${d.discount_type === 'percent' ? `${Number(d.value)}%` : money(d.value)})</option>`).join('');
      discountSel.addEventListener('change', () => {
        if (discountEligibilityCheck) discountEligibilityCheck.checked = false;
        const selectedDiscount = discountList.find((d) => String(d.id) === String(discountSel.value));
        if (discountEligibilityWrap) discountEligibilityWrap.hidden = !selectedDiscount;
        if (discountEligibilityDetails) {
          discountEligibilityDetails.textContent = selectedDiscount?.description
            || 'Review the hotel eligibility policy and check any required supporting ID before applying this discount.';
        }
        refreshBilling();
      });
    }
    const slotsBox = document.getElementById('room-slots');
    const capNote = document.getElementById('capacity-note');
    const party = { adults: 1, children: 0, rooms: 1 };
    let slotIds = [''];
    const appointmentIdInput = document.getElementById('checkin-appointment-id');
    const appointmentLookupForm = document.getElementById('appointment-checkin-lookup');
    const appointmentLookupResult = document.getElementById('appointment-checkin-result');
    let appointmentRoomIds = [];
    let appointmentRoomDetails = [];
    let appointmentDetails = null;
    let appointmentIsConfirmed = true;
    const bookingForm = document.getElementById('booking-create');
    const setAppointmentInputsLocked = (locked) => {
      ['guest_id', 'check_in', 'check_out', 'check_in_time', 'check_out_time'].forEach((name) => {
        const field = bookingForm?.elements.namedItem(name);
        if (field && 'disabled' in field) field.disabled = locked;
      });
      bookingForm?.querySelectorAll('[data-step]').forEach((button) => { button.disabled = locked; });
      bookingForm?.querySelectorAll('[data-time-picker] input, [data-time-picker] select, [data-time-picker] button')
        .forEach((control) => { control.disabled = locked; });
    };

    const chosenRooms = () => slotIds.map((id) => stayRooms.find((r) => String(r.id) === String(id))).filter(Boolean);

    // Greedy split: every room gets one adult first, then fills adults and children up to its limits.
    const distributeGuests = () => {
      const rooms = slotIds.map((id) => stayRooms.find((r) => String(r.id) === String(id)));
      const out = rooms.map(() => ({ adults: 0, children: 0 }));
      if (rooms.some((r) => !r)) return { out, ok: false, reason: 'Select a room for every slot.' };
      if (party.adults < rooms.length) return { out, ok: false, reason: 'Each room needs at least one adult. Remove a room or add adults.' };
      let adults = party.adults;
      let children = party.children;
      rooms.forEach((r, i) => { out[i].adults = 1; adults -= 1; });
      rooms.forEach((r, i) => {
        const a = Math.min(adults, Number(r.max_adults) - 1);
        out[i].adults += a; adults -= a;
        const c = Math.min(children, Number(r.max_children));
        out[i].children = c; children -= c;
      });
      if (adults > 0 || children > 0) {
        return { out, ok: false, reason: `Exceeds available capacity of the selected room(s) by ${adults} adult(s) and ${children} child(ren). Add another room.` };
      }
      return { out, ok: true, reason: '' };
    };

    const nightsCount = () => (checkInInput && checkOutInput ? Math.round((new Date(stampDate(checkOutInput.value)) - new Date(stampDate(checkInInput.value))) / 86400000) : 0);

    const refreshBilling = () => {
      const rooms = chosenRooms();
      const nights = nightsCount();
      const summary = document.getElementById('billing-summary');
      const due = document.getElementById('billing-due');
      document.getElementById('partial-wrap').hidden = typeSel.value !== 'partial';
      const payMethod = document.getElementById('billing-method').value;
      const refWrap = document.getElementById('gcash-wrap');
      if (refWrap) refWrap.hidden = payMethod === 'cash';
      const destination = document.getElementById('billing-payment-destination');
      if (destination) {
        destination.innerHTML = paymentDestinationMarkup(payMethod);
        destination.hidden = payMethod === 'cash';
      }
      const refLbl = document.getElementById('billing-ref-label');
      const refInput = document.getElementById('billing-ref');
      if (refLbl) refLbl.textContent = payMethod === 'bank_transfer' ? 'Bank transfer reference no. (required)' : 'GCash reference no. (required)';
      if (refInput) {
        refInput.required = payMethod !== 'cash';
        refInput.disabled = payMethod === 'cash';
        if (payMethod === 'cash') refInput.value = '';
        refInput.inputMode = payMethod === 'gcash' ? 'numeric' : 'text';
        refInput.placeholder = payMethod === 'bank_transfer' ? 'e.g. BDO-20261021-0001' : 'e.g. 1012 345 678901';
      }
      if (!rooms.length || nights < 1) {
        summary.textContent = 'Select a room and dates to see the bill.';
        due.textContent = '';
        billAndPaymentArePositive = false;
        if (bookingSubmit) bookingSubmit.disabled = true;
        return;
      }
      const disc = discountList.find((d) => String(d.id) === String(discountSel?.value || ''));
      const charges = rooms.map((room, index) => {
        const subtotal = Math.round(Number(room.rate_per_night) * nights * 100) / 100;
        const discountAmount = !disc ? 0 : disc.discount_type === 'percent'
          ? Math.round(subtotal * Number(disc.value)) / 100
          : index === 0 ? Math.min(Number(disc.value), subtotal) : 0;
        const taxable = Math.max(0, subtotal - discountAmount);
        const tax = Math.round(taxable * (taxRate / 100) * 100) / 100;
        return { subtotal, discountAmount, tax, total: Math.round((taxable + tax) * 100) / 100 };
      });
      const sub = charges.reduce((sum, charge) => sum + charge.subtotal, 0);
      const discAmt = charges.reduce((sum, charge) => sum + charge.discountAmount, 0);
      const tax = charges.reduce((sum, charge) => sum + charge.tax, 0);
      const total = charges.reduce((sum, charge) => sum + charge.total, 0);
      const lines = rooms.map((r) => `Room ${e(r.room_number)} ${money(r.rate_per_night)} × ${nights}`).join(' + ');
      summary.innerHTML = `${lines} = ${money(sub)}${disc ? ` − ${e(disc.name)} ${money(discAmt)}` : ''} + Tax ${money(tax)} → <strong>Total ${money(total)}</strong>`;
      const requestedPay = typeSel.value === 'full' ? total : typeSel.value === 'half' ? Math.round(total / 2 * 100) / 100 : Number(document.getElementById('partial-amount').value || 0);
      const pay = Math.min(requestedPay, total);
      const left = Math.max(0, total - pay);
      billAndPaymentArePositive = total >= 0.01 && Math.round(requestedPay * 100) / 100 >= 0.01;
      const discountEligibilityConfirmed = !disc || Boolean(discountEligibilityCheck?.checked);
      if (bookingSubmit) bookingSubmit.disabled = !billAndPaymentArePositive || !appointmentIsConfirmed || !discountEligibilityConfirmed;
      due.innerHTML = billAndPaymentArePositive
        ? `Pay now: <strong>${money(pay)}</strong> · Balance: <strong>${money(left)}</strong> · Status: <strong>${left <= 0.009 ? 'Paid' : 'Partial'}</strong>${left > 0.009 ? '<div class="cell-meta">The remaining balance and every request or service availed during the stay (food, laundry, items) must be paid at the counter in cash, by GCash or by bank transfer before check-out.</div>' : ''}`
        : '<span class="field-hint">A room invoice and check-in payment must each be at least 0.01. Zero-amount invoices and payments are not allowed.</span>';
    };

    const renderSlots = () => {
      if (!slotsBox) return;
      if (!stayRooms.length) {
        const appointmentRoomNumbers = appointmentRoomDetails
          .map((room) => e(room.room_number || `#${room.id}`)).join(', ');
        slotsBox.innerHTML = appointmentRoomIds.length
          ? `<p class="alert alert-error">Appointment room(s) ${appointmentRoomNumbers} are not currently available for the approved stay. Resolve room availability before check-in.</p>`
          : '<p class="muted">No rooms available for those dates.</p>';
        capNote.textContent = appointmentRoomIds.length ? 'The appointment rooms cannot be replaced with other rooms.' : '';
        refreshBilling();
        return;
      }
      const unavailableAppointmentRoomIds = appointmentRoomIds.filter(
        (id) => !stayRooms.some((room) => String(room.id) === String(id))
      );
      if (unavailableAppointmentRoomIds.length) {
        const unavailableRoomNumbers = unavailableAppointmentRoomIds.map((id) => {
          const room = appointmentRoomDetails.find((item) => String(item.id) === String(id));
          return e(room?.room_number || `#${id}`);
        }).join(', ');
        slotsBox.innerHTML = `<p class="alert alert-error">Appointment room(s) ${unavailableRoomNumbers} are not currently available for the approved stay. Resolve room availability before check-in.</p>`;
        capNote.textContent = 'The appointment rooms cannot be replaced with other rooms.';
        refreshBilling();
        return;
      }
      const dist = distributeGuests();
      slotsBox.innerHTML = slotIds.map((id, i) => {
        const taken = slotIds.filter((_, j) => j !== i);
        const availableRoomOptions = appointmentRoomIds.length
          ? stayRooms.filter((room) => appointmentRoomIds.includes(Number(room.id)))
          : stayRooms;
        const opts = availableRoomOptions
          .filter((r) => !taken.includes(String(r.id)))
          .map((r) => `<option value="${r.id}" ${String(r.id) === String(id) ? 'selected' : ''}>${e(r.room_number)} · ${e(r.room_type)} · ${money(r.rate_per_night)} · up to ${e(r.max_adults)} adults + ${e(r.max_children)} kids</option>`)
          .join('');
        const share = dist.out[i];
        const info = id && share ? `<span class="cell-meta">Assigned: ${share.adults} adult(s), ${share.children} child(ren)</span>` : '';
        const lockRoom = appointmentRoomIds.length > 0;
        return `<div class="room-slot"><label>Room ${i + 1}<select data-slot="${i}" ${lockRoom ? 'disabled' : ''}><option value="">Select room…</option>${opts}</select></label>${info}</div>`;
      }).join('');
      slotsBox.querySelectorAll('select[data-slot]').forEach((sel) => {
        sel.addEventListener('change', () => { slotIds[Number(sel.dataset.slot)] = sel.value; renderSlots(); });
      });
      const capTotal = chosenRooms().reduce((s, r) => s + Number(r.max_adults) + Number(r.max_children), 0);
      capNote.className = `capacity-note ${dist.ok ? 'ok' : 'bad'}`;
      capNote.textContent = dist.ok
        ? `${party.adults + party.children} guest(s) fit in ${slotIds.length} room(s) (capacity ${capTotal}).${appointmentRoomIds.length ? ' Rooms are fixed to this appointment.' : ''}`
        : dist.reason;
      document.getElementById('guest-adults').textContent = party.adults;
      document.getElementById('guest-children').textContent = party.children;
      document.getElementById('guest-rooms').textContent = slotIds.length;
      refreshBilling();
    };

    // Grow the room count (auto-picking free rooms) until the guests fit.
    const autoFit = () => {
      const free = () => stayRooms.filter((r) => !slotIds.includes(String(r.id)));
      slotIds = slotIds.map((id) => (stayRooms.some((r) => String(r.id) === String(id)) ? id : ''));
      slotIds = slotIds.map((id) => {
        if (id) return id;
        const next = free()[0];
        return next ? String(next.id) : '';
      });
      let guard = 0;
      while (!distributeGuests().ok && slotIds.length < Math.min(party.adults, stayRooms.length) && free().length && guard++ < 20) {
        slotIds.push(String(free()[0].id));
      }
    };

    document.querySelectorAll('#booking-create [data-stepper]').forEach((box) => {
      box.querySelectorAll('[data-step]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const key = box.dataset.stepper;
          const step = Number(btn.dataset.step);
          if (key === 'adults') party.adults = Math.max(1, Math.min(40, party.adults + step));
          if (key === 'children') party.children = Math.max(0, Math.min(40, party.children + step));
          if (key === 'rooms') {
            if (step > 0 && slotIds.length < stayRooms.length) slotIds.push('');
            if (step < 0 && slotIds.length > 1) slotIds.pop();
          }
          if (key !== 'rooms' || step > 0) autoFit();
          renderSlots();
        });
      });
    });
    ['billing-type', 'billing-method', 'partial-amount'].forEach((id) => { document.getElementById(id)?.addEventListener('input', refreshBilling); });
    discountEligibilityCheck?.addEventListener('change', refreshBilling);

    const loadAvailableRooms = async () => {
      const checkIn = stampDate(checkInInput?.value);
      const checkOut = stampDate(checkOutInput?.value);
      if (!checkIn || !checkOut || checkOut <= checkIn) {
        stayRooms = [];
        if (slotsBox) slotsBox.innerHTML = '<p class="muted">Choose valid stay dates first.</p>';
        return;
      }
      try {
        const available = await apiGet('rooms.php', 'getAvailableRoomsForDates', {
          check_in: checkIn,
          check_out: checkOut,
          appointment_id: appointmentIdInput?.value || '',
        });
        stayRooms = available.data || [];
        const preset = qs('room_id');
        if (appointmentRoomIds.length) {
          slotIds = appointmentRoomIds.map(String);
        } else if (preset && !slotIds.some(Boolean) && stayRooms.some((r) => String(r.id) === String(preset))) {
          slotIds = [String(preset)];
        }
        if (!appointmentRoomIds.length) autoFit();
        renderSlots();
      } catch (err) {
        stayRooms = [];
        if (slotsBox) slotsBox.innerHTML = '<p class="muted">Could not load rooms for those dates.</p>';
        showAlert(flash, 'error', err.message);
      }
    };
    appointmentLookupForm?.addEventListener('submit', async (event) => {
      event.preventDefault();
      const codeField = appointmentLookupForm.elements.appointment_code;
      const submit = appointmentLookupForm.querySelector('[type="submit"]');
      const code = String(codeField.value || '').trim().toUpperCase();
      codeField.value = code;
      submit.disabled = true;
      appointmentIdInput.value = '';
      appointmentDetails = null;
      appointmentRoomIds = [];
      appointmentRoomDetails = [];
      appointmentIsConfirmed = true;
      setAppointmentInputsLocked(false);
      if (appointmentLookupResult) appointmentLookupResult.replaceChildren();
      try {
        const response = await apiPost('appointments.php', 'claimGuestAccount', { appointment_code: code });
        const appointment = response.data.appointment;
        if (appointment.converted_at) {
          throw new Error('This appointment has already been checked in.');
        }
        appointmentIdInput.value = String(appointment.id);
        appointmentRoomIds = appointment.room_ids || [];
        appointmentRoomDetails = appointment.rooms || [];
        appointmentDetails = { ...appointment, guest_id: response.data.guest_id };
        appointmentIsConfirmed = appointment.status === 'confirmed'
          && ['verified', 'not_required'].includes(appointment.payment_status);
        const guestSelect = bookingForm.elements.guest_id;
        if (![...guestSelect.options].some((option) => option.value === String(response.data.guest_id))) {
          const option = document.createElement('option');
          option.value = String(response.data.guest_id);
          option.textContent = `${appointment.first_name} ${appointment.last_name} · ${appointment.email} · Portal user`;
          guestSelect.append(option);
        }
        guestSelect.value = String(response.data.guest_id);
        checkInInput.value = appointment.check_in;
        checkOutInput.value = appointment.check_out;
        bookingForm.elements.check_in_time.value = appointment.check_in_time;
        bookingForm.elements.check_out_time.value = appointment.check_out_time;
        const checkInPicker = bookingForm.elements.check_in_time.closest('[data-time-picker]');
        if (checkInPicker) {
          const minute = checkInPicker.querySelector('[data-tp="m"]');
          if (minute) minute.value = String(parseTimeParts(appointment.check_in_time).m);
          syncTimePicker(checkInPicker);
        }
        party.adults = Number(appointment.adults);
        party.children = Number(appointment.children);
        slotIds = appointmentRoomIds.map(String);
        document.getElementById('guest-adults').textContent = party.adults;
        document.getElementById('guest-children').textContent = party.children;
        document.getElementById('guest-rooms').textContent = slotIds.length;
        const notes = bookingForm.elements.notes;
        if (notes) notes.value = appointment.notes || '';
        document.getElementById('billing-type').value = 'partial';
        document.getElementById('partial-amount').value = Number(appointment.deposit_amount).toFixed(2);
        document.getElementById('billing-method').value = appointment.payment_method;
        document.getElementById('billing-ref').value = appointment.payment_reference;
        document.getElementById('billing-discount').value = '';
        if (discountEligibilityCheck) discountEligibilityCheck.checked = false;
        if (discountEligibilityWrap) discountEligibilityWrap.hidden = true;
        setAppointmentInputsLocked(true);
        await loadAvailableRooms();
        refreshBilling();
        const accountText = response.data.account_status === 'created'
          ? 'Guest account created. The appointment code is the initial password.'
          : 'Existing guest account linked.';
        const roomText = appointmentRoomDetails.length
          ? `Booked room(s): ${appointmentRoomDetails.map((room) => `${room.room_number} · ${room.room_type}`).join(', ')}.`
          : 'No rooms are assigned to this appointment; check-in is blocked until rooms are assigned.';
        const checkinText = appointmentIsConfirmed
          ? 'Appointment details are loaded. The verified 25% deposit is prefilled as the check-in payment.'
          : `Appointment status is ${appointment.status} and deposit status is ${appointment.payment_status}; confirm the request and verify its deposit before check-in.`;
        showAlert(appointmentLookupResult, appointmentIsConfirmed ? 'success' : 'info', `${accountText} ${roomText} ${checkinText}`);
        submit.disabled = false;
      } catch (error) {
        appointmentIdInput.value = '';
        appointmentRoomIds = [];
        appointmentRoomDetails = [];
        appointmentDetails = null;
        appointmentIsConfirmed = true;
        setAppointmentInputsLocked(false);
        showAlert(appointmentLookupResult, 'error', error.message);
        await loadAvailableRooms();
        submit.disabled = false;
      }
    });
    checkInInput?.addEventListener('change', () => {
      setNewStayCheckoutMinimum();
      loadAvailableRooms();
    });
    checkOutInput?.addEventListener('change', () => {
      checkOutInput.min = minCheckOut();
      if (checkOutInput.value && checkOutInput.value < checkOutInput.min) {
        checkOutInput.value = checkOutInput.min;
        showAlert(flash, 'error', 'Check-out must be at least the day after check-in.');
      }
      loadAvailableRooms();
    });
    await loadAvailableRooms();

    document.getElementById('booking-create').addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const dist = distributeGuests();
      if (!dist.ok) {
        showAlert(flash, 'error', dist.reason);
        return;
      }
      if (!billAndPaymentArePositive) {
        showAlert(flash, 'error', 'A room invoice and check-in payment must each be at least 0.01.');
        return;
      }
      const fd = new FormData(ev.target);
      const payload = Object.fromEntries(fd.entries());
      if (appointmentDetails) {
        Object.assign(payload, {
          appointment_id: appointmentDetails.id,
          guest_id: appointmentDetails.guest_id,
          check_in: appointmentDetails.check_in,
          check_out: appointmentDetails.check_out,
          check_in_time: appointmentDetails.check_in_time,
          check_out_time: appointmentDetails.check_out_time,
          adults: appointmentDetails.adults,
          children: appointmentDetails.children,
        });
      }
      if (checkOutInput.value < minCheckOut()) {
        showAlert(flash, 'error', 'Check-out must be on a later day than check-in.');
        return;
      }
      payload.check_in = stampDate(payload.check_in);
      payload.check_out = stampDate(payload.check_out);
      const requestedCheckIn = `${payload.check_in}T${payload.check_in_time}`;
      const appointmentScheduledForToday = appointmentDetails && payload.check_in === today;
      if (requestedCheckIn < toLocalStamp(new Date()) && !appointmentScheduledForToday) {
        showAlert(flash, 'error', 'Check-in cannot be in the past. Choose a future time.');
        return;
      }
      payload.adults = party.adults;
      payload.children = party.children;
      payload.rooms = JSON.stringify(slotIds.map((id, i) => ({ room_id: Number(id), adults: dist.out[i].adults, children: dist.out[i].children })));
      try {
        const r = await apiPost('bookings.php', 'createBooking', payload);
        const paymentId = r.data?.payment_ids?.[0];
        if (paymentId && r.data?.bill_id) {
          sessionStorage.setItem('billing-flash', r.message || 'Check-in payment recorded.');
          const roleFolder = document.body.dataset.role === 'admin' ? 'admin' : 'frontdesk';
          location.href = `${BASE_PATH}/${roleFolder}/payments.html?tab=invoices&id=${r.data.bill_id}`;
          return;
        }
        if (r.data?.status === 'checked_in' && r.data?.booking_id) {
          sessionStorage.setItem('billing-flash', r.message || 'Guest checked in.');
          const roleFolder = document.body.dataset.role === 'admin' ? 'admin' : 'frontdesk';
          location.href = `${BASE_PATH}/${roleFolder}/billing.html?booking_id=${r.data.booking_id}`;
          return;
        }
        const roleFolder = document.body.dataset.role === 'admin' ? 'admin' : 'frontdesk';
        sessionStorage.setItem('room-schedule-flash', r.message || 'Stay reserved.');
        location.href = `${BASE_PATH}/${roleFolder}/room-management.html?date=${encodeURIComponent(payload.check_in)}`;
      } catch (err) {
        showAlert(flash, 'error', err.message);
      }
    });
  }

  async function renderCheckOut(main, options = {}) {
    if (!options.skipMount) mountPageTemplate(main);
    const todayDate = new Date();
    const today = `${todayDate.getFullYear()}-${String(todayDate.getMonth() + 1).padStart(2, '0')}-${String(todayDate.getDate()).padStart(2, '0')}`;
    const addCheckoutDay = (value) => {
      const date = new Date(`${value}T00:00:00`);
      date.setDate(date.getDate() + 1);
      return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    };
    const res = await apiGet('bookings.php', 'getCheckinData');
    const bookings = (res.data.bookings || []).filter((b) => b.status === 'checked_in');

    const flash = document.getElementById('flash');
    const bindCheckOutRows = (tb) => {
      enhanceDateTimeFields(tb);
      tb.querySelectorAll('.stay-dates').forEach((form) => {
        const checkInDate = form.elements.check_in;
        const checkOutDate = form.elements.check_out;
        const alignCheckout = () => {
          const nextDate = addCheckoutDay(checkInDate.value || today);
          checkOutDate.min = nextDate;
          if (checkOutDate.value < nextDate) checkOutDate.value = nextDate;
        };
        alignCheckout();
        checkInDate.addEventListener('change', alignCheckout);
        form.addEventListener('submit', async (ev) => {
          ev.preventDefault();
          const payload = Object.fromEntries(new FormData(form).entries());
          payload.id = form.getAttribute('data-id');
          try {
            await apiPost('bookings.php', 'updateStayDates', payload);
            location.reload();
          } catch (err) {
            showAlert(flash, 'error', err.message);
          }
        });
      });
      tb.querySelectorAll('[data-out]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          try {
            await apiPost('bookings.php', 'checkOut', { id: btn.getAttribute('data-out') });
            const roleFolder = document.body.dataset.role === 'admin' ? 'admin' : 'frontdesk';
            location.href = `${BASE_PATH}/${roleFolder === 'admin' ? 'admin/housekeeping.html' : 'frontdesk/operations.html?tab=inspections'}`;
          } catch (err) {
            showAlert(flash, 'error', err.message);
          }
        });
      });
    };

    const tbody = document.getElementById('checkout-tbody');
    if (tbody) {
      const rowHtmls = bookings.map((b) => {
        const accountBalance = Math.max(0, Number(b.account_balance || 0));
        const hasBalanceDue = accountBalance > 0.009;
        return `<tr>
    <td>${e(b.full_name)}</td>
    <td>${e(b.room_number)} · ${e(b.room_type)}</td>
    <td>
      <form class="form-stack stay-dates" data-id="${b.id}">
        <div class="dt-field"><label>Check-in date and time</label><div class="dt-row"><input type="date" name="check_in" value="${e(b.check_in)}" readonly required>${timeOptions(b.check_in_time, '15:00', 'check_in_time', true)}</div></div>
        <div class="dt-field" data-time-policy="check-out"><label>Check-out date and time</label><div class="dt-row"><input type="date" name="check_out" min="${today}" value="${e(b.check_out)}" required>${timeOptions(b.check_out_time, '14:00', 'check_out_time', false, 'check-out')}</div></div>
        <button class="btn btn-secondary btn-sm" type="submit">Save dates</button>
      </form>
    </td>
    <td>${statusBadge(b.status)}<div class="cell-meta">Account balance: <strong>${money(accountBalance)}</strong>${hasBalanceDue ? ' · Payment required' : ' · Cleared'}</div></td>
    <td class="actions">
      <a class="btn btn-secondary btn-sm" href="billing.html?booking_id=${b.id}">Open Bill</a>
      <button class="btn btn-primary btn-sm" data-out="${b.id}" ${hasBalanceDue ? 'disabled title="Clear the guest account balance before check-out."' : ''}>Check-Out</button>
    </td>
  </tr>`
      });
      paginateTable(tbody, rowHtmls, {
        emptyHtml: '<tr><td colspan="5" class="empty">No checked-in guests ready for check-out.</td></tr>',
        onRender: bindCheckOutRows,
      });
    }
  }

  function fmtTransferStamp(date, time) {
    if (!date) return '—';
    const day = new Date(`${date}T00:00:00`).toLocaleDateString('en-US', { weekday: 'long' });
    return `<strong>${e(day)}</strong><div class="cell-meta">${e(date)} · ${e(fmtTime(time))}</div>`;
  }

  async function renderTransfersStaff(main, canTransfer, options = {}) {
    if (!options.skipMount) mountPageTemplate(main);
    const res = await apiGet('transfers.php', 'getTransferPage');
    const { bookings, rooms, transfers } = res.data;
    const roomOptions = (rooms || [])
      .map(
        (r) =>
          `<option value="${r.id}">${e(r.room_number)} · ${e(r.room_type)} · ${money(r.rate_per_night)}/night</option>`
      )
      .join('');

    if (!canTransfer) {
      main.querySelector('.split')?.remove();
      const notice = document.createElement('section');
      notice.className = 'panel notice-panel';
      notice.innerHTML = '<p class="muted" style="margin:0;"><strong>View only.</strong> Room transfers are performed by Front Desk or Admin.</p>';
      main.insertBefore(notice, main.firstChild?.nextSibling || main.firstChild);
    } else {
      const bookingSel = document.getElementById('transfer-booking');
      const roomSel = document.getElementById('transfer-room');
      const dateInput = document.querySelector('#transfer-form input[name="transfer_date"]');
      const preview = document.getElementById('transfer-rate-preview');
      const dateNow = new Date();
      const today = `${dateNow.getFullYear()}-${String(dateNow.getMonth() + 1).padStart(2, '0')}-${String(dateNow.getDate()).padStart(2, '0')}`;
      const staySel = document.getElementById('transfer-stay');
      const stays = new Map();
      (bookings || []).forEach((b) => {
        const key = b.stay_group ? `g${b.stay_group}` : `b${b.id}`;
        if (!stays.has(key)) stays.set(key, []);
        stays.get(key).push(b);
      });
      const bookingOpt = (b) => `<option value="${b.id}" data-room="${b.room_id}" data-check-in="${e(b.check_in)}" data-check-in-time="${e(b.check_in_time || '')}" data-check-out="${e(b.check_out)}" data-check-out-time="${e(b.check_out_time || '')}" data-rate="${e(b.rate_per_night)}">Room ${e(b.room_number)} (${e(b.room_type)}) · ${money(b.rate_per_night)}/night · booking #${b.id}</option>`;
      if (staySel) {
        staySel.innerHTML = `<option value="">Select guest stay…</option>${[...stays.entries()]
          .map(([key, list]) => `<option value="${key}">${e(list[0].full_name)} · ${list.length} room${list.length > 1 ? 's' : ''} (${list.map((b) => e(b.room_number)).join(', ')}) · in ${e(list[0].check_in)} ${e(fmtTime(list[0].check_in_time))}</option>`)
          .join('')}`;
      }
      const fillStayRooms = () => {
        const list = stays.get(staySel?.value) || [];
        bookingSel.innerHTML = list.length > 1
          ? `<option value="">Select which room to transfer…</option>${list.map(bookingOpt).join('')}`
          : list.length === 1 ? bookingOpt(list[0]) : '<option value="">Select guest stay…</option>';
      };
      if (bookingSel) bookingSel.innerHTML = '<option value="">Select guest stay…</option>';
      if (roomSel) roomSel.innerHTML = `<option value="">Select target room…</option>${roomOptions || ''}`;
      const presetBooking = qs('booking_id');
      if (staySel && presetBooking) {
        const hit = [...stays.entries()].find(([, list]) => list.some((b) => String(b.id) === String(presetBooking)));
        if (hit) { staySel.value = hit[0]; fillStayRooms(); bookingSel.value = presetBooking; }
      }
      if (dateInput) {
        dateInput.min = today;
        dateInput.value = today;
      }
      const timeSlot = document.getElementById('transfer-time-slot');
      if (timeSlot) timeSlot.innerHTML = timeOptions('15:00:00', '15:00:00', 'transfer_time', false, 'transfer');
      enhanceDateTimeFields(document.getElementById('transfer-form'));
      const dayLabel = document.getElementById('transfer-day');
      const stayInfo = document.getElementById('transfer-stay-info');
      const paintTransferMeta = () => {
        if (dayLabel && dateInput?.value) {
          const d = new Date(`${dateInput.value}T00:00:00`);
          dayLabel.textContent = `Transfer day: ${d.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}`;
        }
        const sb = bookingSel?.selectedOptions[0];
        if (dateInput) {
          let minDate = today;
          if (sb?.value && sb.dataset.checkIn) {
            minDate = sb.dataset.checkIn > today ? sb.dataset.checkIn : today;
            if (minDate === sb.dataset.checkIn && (sb.dataset.checkInTime || '15:00:00') > '15:00:00') {
              const nextDate = new Date(`${minDate}T00:00:00`);
              nextDate.setDate(nextDate.getDate() + 1);
              minDate = `${nextDate.getFullYear()}-${String(nextDate.getMonth() + 1).padStart(2, '0')}-${String(nextDate.getDate()).padStart(2, '0')}`;
            }
          }
          dateInput.min = minDate;
          if (dateInput.value && dateInput.value < minDate) dateInput.value = minDate;
        }
        if (stayInfo) {
          stayInfo.textContent = sb?.value
            ? `Guest checked in ${sb.dataset.checkIn} at ${fmtTime(sb.dataset.checkInTime)} · checkout ${sb.dataset.checkOut} at 2:00 PM. Transfers are scheduled at 3:00 PM; late same-day transfers add one extra night.`
            : '';
        }
      };

      const loadTransferTargets = async () => {
        paintTransferMeta();
        const selectedBooking = bookingSel?.selectedOptions[0];
        if (!selectedBooking?.value || !dateInput || !roomSel) {
          roomSel.innerHTML = '<option value="">Select a booking first</option>';
          if (preview) preview.textContent = 'Select a booking, transfer date, and target room to preview the rate adjustment.';
          return;
        }
        const checkOut = selectedBooking.dataset.checkOut || '';
        const dayBeforeCheckout = new Date(`${checkOut}T00:00:00`);
        dayBeforeCheckout.setDate(dayBeforeCheckout.getDate() - 1);
        const maxDate = `${dayBeforeCheckout.getFullYear()}-${String(dayBeforeCheckout.getMonth() + 1).padStart(2, '0')}-${String(dayBeforeCheckout.getDate()).padStart(2, '0')}`;
        dateInput.max = maxDate;
        if (maxDate < dateInput.min) {
          roomSel.innerHTML = '<option value="">Stay has no remaining transfer dates</option>';
          if (preview) preview.textContent = 'This stay has no transfer dates remaining after check-in and before checkout.';
          return;
        }
        if (dateInput.value < dateInput.min || dateInput.value > maxDate) dateInput.value = dateInput.min;
        try {
          const available = await apiGet('rooms.php', 'getAvailableRoomsForDates', {
            check_in: dateInput.value,
            check_out: checkOut,
          });
          const currentRoomId = selectedBooking.dataset.room;
          const selectedRoomId = roomSel.value;
          const targets = (available.data || []).filter((room) => String(room.id) !== String(currentRoomId));
          roomSel.innerHTML = `<option value="">Select target room…</option>${targets
            .map((room) => `<option value="${room.id}" data-rate="${e(room.rate_per_night)}">${e(room.room_number)} · ${e(room.room_type)} · ${money(room.rate_per_night)}/night</option>`)
            .join('')}`;
          if (targets.some((room) => String(room.id) === String(selectedRoomId))) roomSel.value = selectedRoomId;
          const target = roomSel.selectedOptions[0];
          const nights = Math.max(0, Math.round((new Date(`${checkOut}T00:00:00`) - new Date(`${dateInput.value}T00:00:00`)) / 86400000));
          if (preview) {
            const transferStamp = `${dateInput.value}T15:00:00`;
            const transferAt = new Date(transferStamp);
            const nextMidnight = new Date(`${dateInput.value}T00:00:00`);
            nextMidnight.setDate(nextMidnight.getDate() + 1);
            const transferDayFraction = Math.max(0, (nextMidnight - transferAt) / 86400000);
            const billableNights = Math.max(0, nights - 1) + transferDayFraction;
            const rateAdjustment = target?.value
              ? Math.round((Number(target.dataset.rate) - Number(selectedBooking.dataset.rate)) * billableNights * 100) / 100
              : 0;
            preview.textContent = target?.value
              ? `Target rate ${money(Number(target.dataset.rate))}/night starting ${transferAt.toLocaleString('en-US', { dateStyle: 'medium', timeStyle: 'short' })}. The selected time leaves ${billableNights.toFixed(6)} billable night-equivalent(s) across ${nights} calendar night(s). Rate adjustment from the current room: ${money(rateAdjustment)}.`
              : targets.length ? 'Select a target room to preview its rate adjustment.' : 'No target rooms are available for the remaining stay dates.';
          }
        } catch (err) {
          roomSel.innerHTML = '<option value="">Could not load target rooms</option>';
          if (preview) preview.textContent = err.message;
        }
      };
      staySel?.addEventListener('change', () => { fillStayRooms(); roomSel.value = ''; loadTransferTargets(); });
      bookingSel?.addEventListener('change', loadTransferTargets);
      roomSel?.addEventListener('change', loadTransferTargets);
      dateInput?.addEventListener('change', loadTransferTargets);
      if (bookingSel?.value) await loadTransferTargets();

      const howPanel = main.querySelector('.split .panel:last-child');
      if (howPanel) {
        const extra =
          !(bookings || []).length
            ? '<p class="empty" style="margin-top:16px;">No checked-in guests available to transfer.</p>'
            : !(rooms || []).length
              ? '<p class="empty" style="margin-top:16px;">No available rooms for transfer.</p>'
              : '';
        if (extra) howPanel.insertAdjacentHTML('beforeend', extra);
      }
    }

    const tbody = document.getElementById('transfers-tbody');
    if (tbody) {
      const rowHtmls = (transfers || []).map(
        (t) => `<tr>
        <td>${e(t.guest_name || '')}</td>
        <td><strong>${e(t.from_room)}</strong> (${e(t.from_type || '')}) → <strong>${e(t.to_room)}</strong> (${e(t.to_type || '')})</td>
        <td>${money(t.old_nightly_rate)} → ${money(t.new_nightly_rate)}</td>
        <td>${e(t.remaining_nights)}</td>
        <td>${money(t.total_additional_charge)}</td>
        <td>${fmtTransferStamp(t.transfer_date, t.transfer_time)}</td>
        <td>${e(t.transferred_by_name || '—')}</td>
        <td>${e(t.reason || '—')}</td>
      </tr>`
      );
      paginateTable(tbody, rowHtmls, { emptyHtml: '<tr><td colspan="8" class="empty">No room transfers yet.</td></tr>' });
    }

    if (!canTransfer) return;

    const flash = document.getElementById('flash');
    document.getElementById('transfer-form')?.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      try {
        await apiPost('transfers.php', 'transferRoom', Object.fromEntries(new FormData(ev.target).entries()));
        location.reload();
      } catch (err) {
        showAlert(flash, 'error', err.message);
      }
    });
  }

  async function renderGuestTransfers(main) {
    mountPageTemplate(main);
    const res = await apiGet('transfers.php', 'getGuestTransfers');
    const { transfers, booking } = res.data;

    const staySection = main.querySelector('.panel');
    if (staySection) {
      staySection.innerHTML = !booking
        ? '<h2>Current Stay</h2><p class="empty">No active stay right now.</p>'
        : `<h2>Current Stay</h2>
    <ul class="kpi-list">
    <li><span>Room</span><strong>${e(booking.room_number)} · ${e(booking.room_type)}</strong></li>
    <li><span>Check-In</span><strong>${fmtStayPoint(booking.check_in, booking.check_in_time)}</strong></li>
    <li><span>Check-Out</span><strong>${fmtStayPoint(booking.check_out, booking.check_out_time)}</strong></li>
    <li><span>Status</span><strong>${statusBadge(booking.status)}</strong></li>
  </ul>`;
    }

    const tbody = document.getElementById('guest-xfer-tbody');
    if (tbody) {
      const rowHtmls = (transfers || []).map(
        (t) => `<tr>
        <td><strong>${e(t.from_room)}</strong> (${e(t.from_type || '')}) → <strong>${e(t.to_room)}</strong> (${e(t.to_type || '')})</td>
        <td>${money(t.old_nightly_rate)} → ${money(t.new_nightly_rate)}</td>
        <td>${e(t.remaining_nights)}</td>
        <td>${money(t.total_additional_charge)}</td>
        <td>${fmtTransferStamp(t.transfer_date, t.transfer_time)}</td>
        <td>${e(t.reason || '—')}</td>
      </tr>`
      );
      paginateTable(tbody, rowHtmls, { emptyHtml: '<tr><td colspan="6" class="empty">No room transfers on your account yet.</td></tr>' });
    }
  }

  function chargeTypeLabel(item, serviceCats) {
    if (item.item_type === 'room') return '<span class="badge badge-info">Room</span>';
    if (item.item_type === 'transfer_charge') return '<span class="badge badge-info">Transfer</span>';
    if (item.item_type === 'extra') return '<span class="badge badge-ok">Amenity</span>';
    if (item.item_type === 'service') {
      const cat = (serviceCats[item.reference_id] || item.description || '').toLowerCase();
      if (cat.includes('laundry')) return '<span class="badge badge-muted">Laundry</span>';
      if (/restaurant|room service|food|breakfast|lunch|dinner|meal|bar/.test(cat)) return '<span class="badge badge-warn">Food</span>';
      return '<span class="badge badge-muted">Service</span>';
    }
    return '<span class="badge badge-muted">Other</span>';
  }

  async function renderBillingFd(main, options = {}) {
    if (!options.skipMount) mountPageTemplate(main);
    const id = qs('id');
    const bookingId = qs('booking_id');
    if (!id) {
      document.getElementById('billing-panel')?.classList.add('billing-list-only');
      document.getElementById('bill-workspace')?.classList.add('hidden');
    } else {
      document.getElementById('billing-panel')?.classList.add('billing-focused');
      document.getElementById('billing-flow')?.classList.add('hidden');
      document.getElementById('bill-back-to-accounts')?.classList.remove('hidden');
    }
    const params = {};
    if (id) params.id = Number(id);
    if (bookingId) params.booking_id = Number(bookingId);
    const res = await apiGet('bills.php', 'getBillingPage', params);
    const d = res.data;
    const bill = d.bill;
    const flash = document.getElementById('flash');

    const pending = sessionStorage.getItem('billing-flash');
    if (pending) {
      sessionStorage.removeItem('billing-flash');
      showAlert(flash, 'success', pending);
    }

    const openTbody = document.getElementById('open-bills-tbody');
    if (openTbody) {
      const rowHtmls = (d.account_history || []).map((account) => {
        const url = `billing.html?id=${encodeURIComponent(account.representative_bill_id || '')}`;
        const accountLabel = Number(account.room_count)
          ? `${e(account.room_count)} room${Number(account.room_count) === 1 ? '' : 's'} · ${e(account.stay_count || 0)} stay${Number(account.stay_count) === 1 ? '' : 's'}`
          : 'Manual guest account';
        const stayDates = account.stay_periods
          ? `<span class="stay-dates-cell">${account.stay_periods.split('|').map((period) => {
            const [checkIn, checkOut] = period.split('~');
            return `${fmtShortDate(checkIn)} → ${fmtShortDate(checkOut)}`;
          }).join('<br>')}</span>`
          : '<span class="muted">Manual account</span>';
        const stayStatuses = String(account.stay_statuses || '')
          .split(',')
          .filter(Boolean)
          .map((status) => statusBadge(status))
          .join(' ') || '<span class="badge badge-muted">Manual</span>';
        return `<tr>
      <td data-label="Guest account"><strong>${e(account.full_name)}</strong><div class="cell-meta">${accountLabel} · ${e(account.invoice_number || '')}</div></td>
      <td data-label="Rooms">${Number(account.room_count) ? `${e(account.room_numbers || '')}<div class="cell-meta">${e(account.room_count)} room${Number(account.room_count) === 1 ? '' : 's'} · ${e(account.stay_count || 0)} stay${Number(account.stay_count) === 1 ? '' : 's'}</div>` : 'Manual account'}</td>
      <td data-label="Stay dates">${stayDates}<div class="cell-meta">${stayStatuses}</div></td>
      <td class="numeric-cell" data-label="Total charges">${money(account.total_amount)}</td>
      <td class="numeric-cell" data-label="Paid">${money(account.paid_amount)}</td>
      <td class="numeric-cell" data-label="Balance">${money(account.balance)}</td>
      <td data-label="Payment status">${statusBadge(account.account_status)}</td>
      <td class="actions" data-label="Action"><a class="btn btn-secondary btn-sm" href="${url}">Select Account &amp; Rooms</a></td></tr>`;
      });
      paginateTable(openTbody, rowHtmls, { emptyHtml: '<tr><td colspan="8" class="empty">No billing history is available for checked-in or checked-out accounts.</td></tr>' });
    }

    const guestCards = document.getElementById('bill-guest-cards');
    const accountRooms = document.getElementById('bill-account-rooms');
    const roomSelection = document.getElementById('bill-room-selection');
    const selectedGuestName = document.getElementById('bill-selected-guest-name');
    const accountRoomEmpty = document.getElementById('bill-account-room-empty');
    const dueInput = document.getElementById('bill-due-date');
    if (dueInput && !dueInput.value) {
      const tomorrow = new Date();
      tomorrow.setDate(tomorrow.getDate() + 1);
      dueInput.value = `${tomorrow.getFullYear()}-${String(tomorrow.getMonth() + 1).padStart(2, '0')}-${String(tomorrow.getDate()).padStart(2, '0')}`;
    }
    let selectedGuestId = bill
      ? Number(bill.guest_id)
      : Number((d.bookings || []).find((item) => String(item.id) === String(bookingId))?.guest_id || 0);
    const openGuestAccount = async (guestId, selectedBookingId = 0) => {
      try {
        const result = await apiPost('bills.php', 'createBill', {
          guest_id: guestId,
          booking_id: selectedBookingId,
          due_date: dueInput?.value || '',
        });
        location.href = `billing.html?id=${result.data.bill_id}`;
      } catch (error) {
        showAlert(flash, 'error', error.message);
      }
    };
    const guestList = d.guests || [];
    if (guestCards) {
      guestCards.innerHTML = guestList.map((guest) => `
        <button type="button" class="billing-account-card${selectedGuestId === Number(guest.id) ? ' is-selected' : ''}" data-select-guest="${e(guest.id)}">
          <span class="billing-account-avatar" aria-hidden="true">${e(String(guest.full_name || 'G').trim().charAt(0).toUpperCase())}</span>
          <span><strong>${e(guest.full_name)}</strong><small>${e(guest.email || guest.phone || 'Guest account')}</small></span>
          <span class="billing-account-arrow" aria-hidden="true">›</span>
        </button>`).join('') || '<p class="empty">No guest accounts are available.</p>';
      guestCards.querySelectorAll('[data-select-guest]').forEach((button) => {
        button.addEventListener('click', () => {
          selectedGuestId = Number(button.dataset.selectGuest);
          guestCards.querySelectorAll('.billing-account-card').forEach((card) => card.classList.toggle('is-selected', card === button));
          const guest = guestList.find((item) => Number(item.id) === selectedGuestId);
          if (selectedGuestName) selectedGuestName.textContent = guest?.full_name || 'Guest';
          roomSelection?.classList.remove('hidden');
          const guestBookings = (d.bookings || []).filter((item) => Number(item.guest_id) === selectedGuestId);
          if (accountRooms) {
            accountRooms.innerHTML = guestBookings.map((room) => `
              <article class="appointment-room-card billing-selectable-room">
                ${room.photo_url ? `<img src="${e(room.photo_url)}" alt="Room ${e(room.room_number)}">` : '<div class="billing-photo-fallback">Room photo unavailable</div>'}
                <div class="appointment-room-card-copy">
                  <strong>Room ${e(room.room_number)}</strong>
                  <small>${e(room.room_type || 'Guest room')} · ${statusBadge(room.status)}</small>
                  <small>${fmtBillingDateTime(room.check_in)} → ${fmtBillingDateTime(room.check_out)}</small>
                  <button type="button" class="btn btn-primary btn-sm" data-open-booking="${e(room.id)}">Open this room account</button>
                </div>
              </article>`).join('');
            accountRooms.querySelectorAll('[data-open-booking]').forEach((openButton) => {
              openButton.addEventListener('click', () => openGuestAccount(selectedGuestId, Number(openButton.dataset.openBooking)));
            });
          }
          if (accountRoomEmpty) {
            accountRoomEmpty.textContent = guestBookings.length
              ? 'All active rooms for this guest are shown above. Select a room to open its bill.'
              : 'No active rooms for this guest. Use the manual account button for a walk-in or unlinked account.';
          }
          const manualButton = document.getElementById('bill-manual-account');
          if (manualButton) manualButton.onclick = () => openGuestAccount(selectedGuestId);
        });
      });
      if (selectedGuestId) {
        guestCards.querySelector(`[data-select-guest="${CSS.escape(String(selectedGuestId))}"]`)?.click();
      }
    }

    const title = document.getElementById('bill-title');
    const empty = document.getElementById('bill-empty');
    const stay = document.getElementById('bill-stay');
    const kpi = document.getElementById('bill-kpi');
    const itemsWrap = document.getElementById('bill-items-wrap');
    const itemsTbody = document.getElementById('bill-items-tbody');
    const chargeForms = document.getElementById('bill-charge-forms');
    const locked = document.getElementById('bill-locked');
    const actions = document.getElementById('bill-actions');
    const flow = document.getElementById('billing-flow');
    const markStep = (step, state) => flow?.querySelector(`[data-step="${step}"]`)?.classList.add(state);

    if (!bill) {
      if (title) title.textContent = 'Select a bill';
      markStep('checkin', 'is-current');
      return;
    }

    const serviceCats = {};
    (d.services || []).forEach((s) => {
      serviceCats[s.id] = `${s.category || ''} ${s.name || ''}`;
    });
    const items = d.items || [];
    const isLocked = bill.status === 'void' || ['checked_out', 'cancelled'].includes(bill.booking_status);

    if (bill.booking_status === 'checked_in' || bill.booking_status === 'checked_out') markStep('checkin', 'is-done');
    if (items.length) markStep('charges', 'is-done');
    if (items.length) markStep('account', 'is-done');
    markStep(items.length ? 'account' : 'charges', 'is-current');

    if (title) {
      title.textContent = bill.room_number
        ? `Room ${bill.room_number} bill · ${bill.full_name || 'Guest'}`
        : `Guest Account ${bill.invoice_number}`;
    }
    const accountCreated = document.getElementById('bill-created-at');
    if (accountCreated) {
      accountCreated.textContent = `Account opened: ${fmtBillingDateTime(bill.created_at)}`;
      accountCreated.title = bill.created_at || '';
    }
    empty?.classList.add('hidden');

    if (stay) {
      stay.classList.remove('hidden');
      stay.innerHTML = bill.room_number
        ? `<div><span>Guest</span><strong>${e(bill.full_name || '')}</strong><small>${e(bill.phone || bill.email || '')}</small></div>
        <div><span>Room</span><strong>${e(bill.room_number)} · ${e(bill.room_type || '')}</strong><small>${money(bill.rate_per_night)} / night</small></div>
        <div><span>Check-in</span><strong>${fmtBillingDateTime(`${bill.stay_check_in} ${bill.stay_check_in_time || ''}`)}</strong><small>Booking #${e(bill.booking_id)}</small></div>
        <div><span>Check-out</span><strong>${fmtBillingDateTime(`${bill.stay_check_out} ${bill.stay_check_out_time || ''}`)}</strong><small>Stay status: ${statusBadge(bill.booking_status)}</small></div>`
        : `<div><span>Guest</span><strong>${e(bill.full_name || '')}</strong><small>${e(bill.phone || bill.email || '')}</small></div>
        <div><span>Room</span><strong>No linked stay</strong><small>Manual guest account</small></div>`;
    }

    kpi?.classList.remove('hidden');
    if (kpi) {
      kpi.innerHTML = `
        <li><span>Subtotal</span><strong>${money(bill.subtotal)}</strong></li>
        <li><span>Discount</span><strong>${money(bill.discount_amount)}</strong></li>
        <li><span>Total</span><strong>${money(bill.total_amount)}</strong></li>
        <li><span>Paid</span><strong>${money(bill.paid_amount)}</strong></li>
        <li><span>Balance</span><strong>${money(bill.balance)}</strong></li>
        <li><span>Payment Status</span><strong>${statusBadge(bill.status)}<small style="display:block;font-weight:400;">${e(paymentStatusDescription(bill.status))}</small></strong></li>`;
    }

    itemsWrap?.classList.remove('hidden');
    if (itemsTbody) {
      const itemRowHtmls = items.map(
        (it) => `<tr>
        <td>${chargeTypeLabel(it, serviceCats)}</td>
        <td>${e(it.description)}${it.delivery_room_number ? `<div class="cell-meta">Deliver to Room ${e(it.delivery_room_number)}</div>` : ''}<div class="cell-meta" title="${e(it.created_at || '')}">Charged: ${fmtBillingDateTime(it.created_at)}</div></td>
        <td>${e(quantityLabel(it.quantity))}</td><td>${money(it.unit_price)}</td><td>${money(it.amount)}</td>
        <td>${isLocked ? '' : `<button class="btn btn-secondary btn-sm" data-remove="${it.id}">Remove</button>`}</td></tr>`
      );
      paginateTable(itemsTbody, itemRowHtmls, {
        emptyHtml: '<tr><td colspan="6" class="empty">No charges yet.</td></tr>',
        onRender: (tb) => {
          tb.querySelectorAll('[data-remove]').forEach((btn) => {
            btn.addEventListener('click', async () => {
              try {
                await apiPost('bills.php', 'removeItem', { bill_id: bill.id, item_id: btn.getAttribute('data-remove') });
                location.reload();
              } catch (err) {
                showAlert(flash, 'error', err.message);
              }
            });
          });
        },
      });
    }

    actions?.classList.remove('hidden');
    const payLink = document.getElementById('bill-pay-link');
    const invLink = document.getElementById('bill-invoice-link');
    if (payLink) payLink.href = `payments.html?bill_id=${bill.id}`;
    if (invLink) invLink.href = `payments.html?tab=invoices&id=${bill.id}`;

    if (isLocked) {
      locked?.classList.remove('hidden');
      if (locked) {
        locked.innerHTML = `<strong>Charges locked.</strong> ${
          bill.status === 'void' ? 'This bill is void.' : `This stay is ${e(String(bill.booking_status).replace('_', ' '))}.`
        }`;
      }
      return;
    }

    chargeForms?.classList.remove('hidden');
    const deliveryRoomId = Number(bill.room_id || 0);
    const deliveryRooms = d.delivery_rooms || [];
    const deliveryTarget = document.getElementById('bill-delivery-rooms');
    const deliveryEmpty = document.getElementById('bill-delivery-empty');
    if (deliveryTarget) {
      deliveryTarget.innerHTML = deliveryRooms.map((room) => {
        const isCurrentRoom = Number(room.id) === deliveryRoomId;
        const canOpenRoomAccount = room.booking_status === 'checked_in';
        return `<article class="appointment-room-card billing-selectable-room${isCurrentRoom ? ' is-selected' : ''}${canOpenRoomAccount ? '' : ' is-unavailable'}">
          ${room.photo_url ? `<img src="${e(room.photo_url)}" alt="Room ${e(room.room_number)}">` : '<div class="billing-photo-fallback">Room photo unavailable</div>'}
          <div class="appointment-room-card-copy">
            <strong>Room ${e(room.room_number)}</strong>
            <small>${e(room.room_type || 'Guest room')} · ${statusBadge(room.booking_status)}</small>
            ${isCurrentRoom
              ? '<span class="billing-current-room">Selected · charges go here</span>'
              : `<button type="button" class="btn btn-secondary btn-sm" data-open-room-bill="${e(room.booking_id)}" ${canOpenRoomAccount ? '' : 'disabled'}>${canOpenRoomAccount ? 'Select this room' : 'Available after check-in'}</button>`}
          </div>
        </article>`;
      }).join('');
      deliveryTarget.querySelectorAll('[data-open-room-bill]').forEach((button) => {
        button.addEventListener('click', () => {
          openGuestAccount(Number(bill.guest_id), Number(button.dataset.openRoomBill));
        });
      });
    }
    if (deliveryEmpty) {
      deliveryEmpty.textContent = deliveryRooms.length
        ? 'Select a checked-in room account first. Every charge below will be added to that selected room.'
        : 'No active rooms are linked to this guest account.';
    }
    const chargeTargetLabel = document.getElementById('bill-charge-target-label');
    if (chargeTargetLabel) {
      chargeTargetLabel.textContent = bill.room_number
        ? `Charges will be posted to Room ${bill.room_number}. Select another checked-in room above to switch accounts.`
        : 'No room is linked to this account. Charges will remain on the manual guest account.';
    }

    const renderCatalogCards = (targetId, records, kind) => {
      const target = document.getElementById(targetId);
      if (!target) return;
      target.innerHTML = records.length ? records.map((record) => {
        const name = kind === 'service' ? record.name : record.item_name;
        const price = Number(kind === 'service' ? record.price : record.unit_price);
        const category = kind === 'service' ? record.category : (record.category || 'Hotel item');
        const categoryText = `${category || ''} ${name || ''}`.toLowerCase();
        const filterCategory = kind !== 'service'
          ? 'items'
          : categoryText.includes('laundry')
            ? 'laundry'
            : /restaurant|room service|food|breakfast|lunch|dinner|meal|bar/.test(categoryText)
              ? 'food'
              : 'services';
        const image = record.photo_url
          ? `<img src="${e(record.photo_url)}" alt="${e(name)}">`
          : `<div class="billing-photo-fallback">${e(category)}</div>`;
        return `<article class="billing-catalog-card" data-bill-category="${filterCategory}" data-bill-search="${e(`${name} ${category}`.toLowerCase())}">
          ${image}
          <div class="billing-catalog-copy"><strong>${e(name)}</strong><small>${e(category)} · ${money(price)}</small>
            <label>Quantity<input type="number" min="1" step="1" value="1" data-quantity></label>
            <button type="button" class="btn btn-primary" data-charge-kind="${kind}" data-charge-id="${e(record.id)}">Add charge</button>
          </div>
        </article>`;
      }).join('') : '<p class="empty">No active catalog items.</p>';
      target.querySelectorAll('[data-charge-kind]').forEach((button) => {
        button.addEventListener('click', async () => {
          const quantity = Math.max(1, Number(button.closest('.billing-catalog-card')?.querySelector('[data-quantity]')?.value || 1));
          if (bill.booking_status === 'checked_in' && !deliveryRoomId) {
            showAlert(flash, 'error', 'Select a checked-in room account before adding this charge.');
            return;
          }
          const payload = {
            bill_id: bill.id,
            quantity,
            delivery_room_id: deliveryRoomId,
          };
          const operation = button.dataset.chargeKind === 'service' ? 'addService' : 'addExtra';
          if (operation === 'addService') {
            payload.service_id = Number(button.dataset.chargeId);
          } else {
            const item = records.find((entry) => Number(entry.id) === Number(button.dataset.chargeId));
            payload.additional_item_id = Number(item.id);
            payload.description = item.item_name;
            payload.unit_price = Number(item.unit_price);
          }
          button.disabled = true;
          try {
            const result = await apiPost('bills.php', operation, payload);
            sessionStorage.setItem('billing-flash', result.message || 'Charge added.');
            location.href = `billing.html?id=${bill.id}`;
          } catch (err) {
            button.disabled = false;
            showAlert(flash, 'error', err.message);
          }
        });
      });
    };
    renderCatalogCards('bill-service-cards', d.services || [], 'service');
    renderCatalogCards('bill-item-cards', d.additional_items || [], 'extra');

    const discountCards = document.getElementById('bill-discount-cards');
    if (discountCards) {
      discountCards.innerHTML = (d.discounts || []).length ? (d.discounts || []).map((discount) => `
        <article class="billing-discount-card" data-bill-category="discounts" data-bill-search="${e(`${discount.name} ${discount.discount_type}`.toLowerCase())}">
          <div><strong>${e(discount.name)}</strong><small>${discount.discount_type === 'fixed' ? money(discount.value) : `${e(discount.value)}%`} off</small></div>
          <button type="button" class="btn btn-secondary btn-sm" data-apply-discount="${e(discount.id)}">Apply</button>
        </article>`).join('') : '<p class="empty">No active discounts.</p>';
      discountCards.querySelectorAll('[data-apply-discount]').forEach((button) => {
        button.addEventListener('click', async () => {
          button.disabled = true;
          try {
            const result = await apiPost('bills.php', 'applyDiscount', {
              bill_id: bill.id,
              discount_id: Number(button.dataset.applyDiscount),
            });
            sessionStorage.setItem('billing-flash', result.message || 'Discount applied.');
            location.href = `billing.html?id=${bill.id}`;
          } catch (error) {
            button.disabled = false;
            showAlert(flash, 'error', error.message);
          }
        });
      });
    }

    const servicesPanel = document.getElementById('bill-services-panel');
    const itemsPanel = document.getElementById('bill-items-panel');
    const discountsPanel = document.getElementById('bill-discounts-panel');
    const catalogSearch = document.getElementById('bill-catalog-search');
    const filterButtons = [...document.querySelectorAll('[data-bill-filter]')];
    let activeCatalogFilter = 'food';
    const applyCatalogFilter = () => {
      const query = String(catalogSearch?.value || '').trim().toLowerCase();
      const cards = [...document.querySelectorAll('#bill-service-cards [data-bill-category], #bill-item-cards [data-bill-category], #bill-discount-cards [data-bill-category]')];
      cards.forEach((card) => {
        const category = card.dataset.billCategory;
        const matchesCategory = activeCatalogFilter === 'all'
          || category === activeCatalogFilter
          || (activeCatalogFilter === 'all-services' && ['food', 'laundry', 'services'].includes(category));
        const matchesSearch = !query || (card.dataset.billSearch || '').includes(query);
        card.classList.toggle('hidden', !(matchesCategory && matchesSearch));
      });
      if (servicesPanel) {
        servicesPanel.classList.toggle('hidden', ![...servicesPanel.querySelectorAll('[data-bill-category]')].some((card) => !card.classList.contains('hidden')));
      }
      if (itemsPanel) {
        itemsPanel.classList.toggle('hidden', ![...itemsPanel.querySelectorAll('[data-bill-category]')].some((card) => !card.classList.contains('hidden')));
      }
      if (discountsPanel) {
        discountsPanel.classList.toggle('hidden', ![...discountsPanel.querySelectorAll('[data-bill-category]')].some((card) => !card.classList.contains('hidden')));
      }
    };
    filterButtons.forEach((button) => {
      const isDefault = button.dataset.billFilter === activeCatalogFilter;
      button.classList.toggle('is-active', isDefault);
      button.setAttribute('aria-pressed', String(isDefault));
      button.addEventListener('click', () => {
        activeCatalogFilter = button.dataset.billFilter || 'all';
        filterButtons.forEach((filterButton) => {
          const isActive = filterButton === button;
          filterButton.classList.toggle('is-active', isActive);
          filterButton.setAttribute('aria-pressed', String(isActive));
        });
        applyCatalogFilter();
      });
    });
    catalogSearch?.addEventListener('input', applyCatalogFilter);
    applyCatalogFilter();

  }

  async function renderExtendStay(main, options = {}) {
    if (!options.skipMount) mountPageTemplate(main);
    const flash = document.getElementById('flash');
    const accountSel = document.getElementById('extend-account');
    const detail = document.getElementById('extend-detail');
    const tbody = document.getElementById('extend-rooms-tbody');
    const allAt = document.getElementById('extend-all-at');
    const selectAll = document.getElementById('extend-select-all');
    const totalBox = document.getElementById('extend-total');
    const historyPanel = document.getElementById('extend-history-panel');
    const historyTbody = document.getElementById('extend-history-tbody');
    const p2 = (v) => String(v).padStart(2, '0');
    const curOf = (b) => `${b.check_out}T14:00:00`;
    const lowest = (b) => {
      const currentCheckout = new Date(curOf(b));
      currentCheckout.setDate(currentCheckout.getDate() + 1);
      const today = new Date();
      if (today.getHours() >= 14 && currentCheckout.toDateString() === today.toDateString()) {
        currentCheckout.setDate(currentCheckout.getDate() + 1);
      }
      return `${currentCheckout.getFullYear()}-${p2(currentCheckout.getMonth() + 1)}-${p2(currentCheckout.getDate())}`;
    };
    let stays = [];
    let extensionHistory = [];
    let current = [];

    const accountKey = (b) => (b.stay_group ? `g${b.stay_group}` : `b${b.id}`);
    const buildAccounts = () => {
      const map = new Map();
      stays.forEach((b) => {
        const k = accountKey(b);
        if (!map.has(k)) map.set(k, []);
        map.get(k).push(b);
      });
      return map;
    };

    // Same maths as the server: quantity (nights) rounded to 2 decimals × room rate.
    const extra = (b, value) => {
      if (!value) return 0;
      const days = (new Date(`${value}T14:00:00`) - new Date(curOf(b))) / 86400000;
      if (!(days > 0)) return 0;
      const chargeDays = Math.max(0.01, Math.round(days * 100) / 100);
      return Math.round(chargeDays * Number(b.rate_per_night) * 100) / 100;
    };

    const refreshTotal = () => {
      let sum = 0;
      let rooms = 0;
      tbody.querySelectorAll('tr').forEach((tr) => {
        const b = current.find((x) => String(x.id) === tr.dataset.id);
        const cost = tr.querySelector('.extend-check').checked ? extra(b, tr.querySelector('.extend-at').value) : 0;
        tr.querySelector('.extend-cost').textContent = cost > 0 ? money(cost) : '—';
        if (tr.querySelector('.extend-check').checked) rooms += 1;
        sum += cost;
      });
      totalBox.innerHTML = `<div><span>Rooms selected</span><strong>${rooms}</strong></div><div><span>Extra charge to add to the guest account</span><strong>${money(sum)}</strong></div>`;
    };

    const showAccount = (key) => {
      current = key ? buildAccounts().get(key) || [] : [];
      detail.classList.toggle('hidden', !current.length);
      historyPanel.classList.toggle('hidden', !current.length);
      if (!current.length) {
        historyTbody.innerHTML = '';
        return;
      }
      const first = current[0];
      document.getElementById('extend-title').textContent = `${first.full_name} · ${current.length} room${current.length > 1 ? 's' : ''} on this account`;
      const link = document.getElementById('extend-bill-link');
      const roleFolder = document.body.dataset.role === 'admin' ? 'admin' : 'frontdesk';
      link.href = `${BASE_PATH}/${roleFolder}/billing.html?${first.bill_id ? `id=${first.bill_id}` : `booking_id=${first.id}`}`;
      tbody.innerHTML = current.map((b) => {
        const reopen = b.status === 'checked_out';
        const min = lowest(b);
        return `<tr data-id="${b.id}">
      <td><input type="checkbox" class="extend-check" checked aria-label="Extend room ${e(b.room_number)}"></td>
      <td><strong>${e(b.room_number)}</strong> · ${e(b.room_type)}<div class="cell-meta">${money(b.rate_per_night)} / night · Booking #${e(b.id)}</div></td>
      <td>${statusBadge(b.status)}${reopen ? '<div class="cell-meta">Will be reopened</div>' : ''}</td>
      <td>${fmtStayPoint(b.check_out, '14:00:00')}</td>
      <td><input type="date" class="extend-at" min="${min}" value="${min}" required></td>
      <td class="extend-cost">—</td>
    </tr>`;
      }).join('');
      const mins = current.map(lowest).sort();
      allAt.min = mins[mins.length - 1];
      allAt.value = allAt.min;
      selectAll.checked = true;
      tbody.querySelectorAll('.extend-check, .extend-at').forEach((el) => el.addEventListener('input', refreshTotal));
      refreshTotal();

      const bookingIds = new Set(current.map((booking) => Number(booking.id)));
      const entries = extensionHistory.filter((entry) => bookingIds.has(Number(entry.booking_id)));
      const historyRows = entries.map((entry) => {
        const booking = current.find((item) => Number(item.id) === Number(entry.booking_id));
        const action = entry.action === 'booking_reopen' ? 'Reopened and extended' : 'Extended';
        return `<tr>
          <td data-label="Date Recorded">${fmtBillingDateTime(entry.created_at)}</td>
          <td data-label="Room">${booking ? `Room ${e(booking.room_number)} · Booking #${e(booking.id)}` : `Booking #${e(entry.booking_id)}`}</td>
          <td data-label="Extension Log"><span class="badge badge-info">${action}</span><div class="cell-meta">${e(entry.details)}</div></td>
          <td data-label="Recorded By">${e(entry.performed_by || 'Staff unavailable')}</td>
        </tr>`;
      });
      paginateTable(historyTbody, historyRows, {
        emptyHtml: '<tr><td colspan="4" class="empty">No room extensions have been recorded for this account.</td></tr>',
      });
    };

    const loadStays = async (keepKey) => {
      const response = (await apiGet('bookings.php', 'getExtendData')).data;
      stays = response.stays || [];
      extensionHistory = response.extension_history || [];
      const accounts = buildAccounts();
      accountSel.innerHTML = '<option value="">Select guest account…</option>' + [...accounts.entries()].map(([k, rows]) => {
        const rooms = rows.map((r) => r.room_number).join(', ');
        const open = rows.some((r) => r.status === 'checked_in');
        return `<option value="${k}">${e(rows[0].full_name)} · Room${rows.length > 1 ? 's' : ''} ${e(rooms)} · ${open ? 'In-house' : 'Checked out'}${rows[0].invoice_number ? ' · ' + e(rows[0].invoice_number) : ''}</option>`;
      }).join('');
      if (keepKey && accounts.has(keepKey)) accountSel.value = keepKey;
      showAccount(accountSel.value);
    };

    await loadStays();
    const wanted = Number(qs('booking_id') || 0);
    const target = stays.find((b) => b.id === wanted || Number(b.id) === wanted);
    if (target) {
      accountSel.value = accountKey(target);
      showAccount(accountSel.value);
    }

    accountSel.addEventListener('change', () => showAccount(accountSel.value));
    selectAll.addEventListener('change', () => {
      tbody.querySelectorAll('.extend-check').forEach((c) => { c.checked = selectAll.checked; });
      refreshTotal();
    });
    document.getElementById('extend-apply-all').addEventListener('click', () => {
      if (!allAt.value) return;
      tbody.querySelectorAll('tr').forEach((tr) => {
        if (!tr.querySelector('.extend-check').checked) return;
        const at = tr.querySelector('.extend-at');
        at.value = allAt.value < at.min ? at.min : allAt.value;
      });
      refreshTotal();
    });

    document.getElementById('extend-submit').addEventListener('click', async (ev) => {
      const btn = ev.currentTarget;
      const rows = [...tbody.querySelectorAll('tr')].filter((tr) => tr.querySelector('.extend-check').checked);
      if (!rows.length) {
        showAlert(flash, 'error', 'Select at least one room to extend.');
        return;
      }
      for (const tr of rows) {
        const b = current.find((x) => String(x.id) === tr.dataset.id);
        const at = tr.querySelector('.extend-at').value;
        const checkoutAt = at ? new Date(`${at}T14:00:00`).getTime() : 0;
        if (!at || checkoutAt <= new Date(curOf(b)).getTime() || checkoutAt <= Date.now()) {
          showAlert(flash, 'error', `Room ${b.room_number}: choose a future check-out later than the current one.`);
          return;
        }
      }
      btn.disabled = true;
      const done = [];
      const failed = [];
      for (const tr of rows) {
        const b = current.find((x) => String(x.id) === tr.dataset.id);
        try {
          await apiPost('bookings.php', 'extendStay', { id: b.id, check_out_at: `${tr.querySelector('.extend-at').value}T14:00` });
          done.push(b.room_number);
        } catch (err) {
          failed.push(`Room ${b.room_number}: ${err.message}`);
        }
      }
      btn.disabled = false;
      const key = accountSel.value;
      await loadStays(key);
      if (done.length) showAlert(flash, failed.length ? 'error' : 'success', `Extended Room${done.length > 1 ? 's' : ''} ${done.join(', ')}. The extra charge was added to the guest account.${failed.length ? ' ' + failed.join(' ') : ''}`);
      else showAlert(flash, 'error', failed.join(' '));
    });
  }

  async function renderPaymentsFd(main, options = {}) {
    if (!options.skipMount) mountPageTemplate(main);
    const billId = qs('bill_id');
    const res = await apiGet('payments.php', 'getPaymentsPage', billId ? { bill_id: Number(billId) } : {});
    const d = res.data;

    const accountSel = document.getElementById('payment-account-select');
    const amountInput = document.querySelector('#payment-form input[name="amount"]');
    const submitButton = document.querySelector('#payment-form button[type="submit"]');
    const accountSummary = document.getElementById('payment-account-summary');
    const bills = d.bills || [];
    const accounts = d.accounts || [];
    const findAccountForBill = (id) => {
      const bill = bills.find((item) => String(item.id) === String(id));
      if (!bill) return null;
      return accounts.find((account) => Number(account.guest_id) === Number(bill.guest_id)) || accounts.find((account) => {
        if (Number(account.stay_group)) {
          return Number(account.stay_group) === Number(bill.stay_group) && Number(account.guest_id) === Number(bill.guest_id);
        }
        if (Number(account.representative_booking_id)) {
          return Number(account.representative_booking_id) === Number(bill.booking_id);
        }
        return Number(account.representative_bill_id) === Number(bill.id);
      }) || null;
    };
    if (accountSel) {
      accountSel.innerHTML = `<option value="">Select guest account…</option>${accounts.map((account) => `
        <option value="${e(account.account_key)}">${e(account.full_name)} · ${Number(account.room_count) ? `${e(account.room_numbers || '')} · ${e(account.stay_count || 0)} stays · ` : ''}${money(account.balance)} due</option>`
      ).join('')}`;
      const initialAccount = findAccountForBill(billId);
      if (initialAccount) accountSel.value = initialAccount.account_key;
    }
    const selectedAccount = () => accounts.find((account) => account.account_key === accountSel?.value) || null;
    let breakdownRequest = 0;
    const loadBreakdown = async (account) => {
      const box = document.getElementById('bill-breakdown');
      const requestId = ++breakdownRequest;
      if (!box) return;
      if (!account) {
        box.hidden = true;
        box.innerHTML = '';
        return;
      }
      box.hidden = false;
      box.innerHTML = '<p class="muted">Loading every room charge for this account…</p>';
      try {
        const result = await apiGet('payments.php', 'getAccountPaymentDetails', {
          booking_id: Number(account.representative_booking_id || 0),
          bill_id: Number(account.representative_bill_id || 0),
        });
        if (requestId !== breakdownRequest) return;
        const accountBills = result.data.bills || [];
        box.innerHTML = `<h3>All charges and balances</h3>${accountBills.map((bill) => {
          const items = bill.items || [];
          const charges = items.map((item) => `<li><span>${e(item.description)}${Number(item.quantity) !== 1 ? ` × ${e(quantityLabel(item.quantity))}` : ''}${item.delivery_room_number ? `<small>Deliver to Room ${e(item.delivery_room_number)}</small>` : ''}</span><strong>${money(item.amount)}</strong></li>`).join('');
          return `<section class="bill-account-breakdown">
            <div class="row-between"><strong>${bill.room_number ? `Room ${e(bill.room_number)}` : 'Guest account'} · ${e(bill.invoice_number || 'No invoice yet')}</strong><span>${bill.bill_status ? statusBadge(bill.bill_status) : '<span class="muted">No bill</span>'}</span></div>
            <ul>${charges || '<li><span>No billed items.</span><strong>—</strong></li>'}</ul>
            <ul><li><span>Total charges</span><strong>${money(bill.total_amount || 0)}</strong></li><li><span>Already paid</span><strong>− ${money(bill.paid_amount || 0)}</strong></li><li><span>Balance due</span><strong>${money(bill.balance || 0)}</strong></li></ul>
          </section>`;
        }).join('') || '<p class="muted">No bills or billed items are available for this account.</p>'}`;
      } catch (error) {
        if (requestId !== breakdownRequest) return;
        box.innerHTML = `<p class="muted">Could not load this account’s charges: ${e(error.message)}</p>`;
      }
    };
    const updatePaymentSelection = () => {
      const account = selectedAccount();
      const payable = account && Number(account.balance) > 0.009;
      if (accountSummary) {
        accountSummary.textContent = account
          ? `${Number(account.room_count) ? `Rooms ${account.room_numbers || '—'} · ${account.stay_count} stay(s) · ` : ''}${account.bill_count} bill(s) · Total charges ${money(account.total_amount)} · Paid ${money(account.paid_amount)} · Balance due ${money(account.balance)}`
          : 'Choose the guest account before recording a payment.';
      }
      if (amountInput) {
        const due = Number(account?.balance || 0);
        amountInput.value = payable ? due.toFixed(2) : '';
        amountInput.max = payable ? due.toFixed(2) : '0';
      }
      if (submitButton) submitButton.disabled = !payable;
      const payAll = document.getElementById('pay-all');
      if (payAll) payAll.disabled = !payable;
      loadBreakdown(account);
    };
    document.getElementById('pay-all')?.addEventListener('click', () => {
      const account = selectedAccount();
      if (amountInput) amountInput.value = Number(account?.balance || 0).toFixed(2);
      document.getElementById('payment-form')?.requestSubmit();
    });
    accountSel?.addEventListener('change', updatePaymentSelection);
    updatePaymentSelection();

    const refInput = document.getElementById('payment-ref');
    const refLabel = document.getElementById('payment-ref-label');
    const destination = document.getElementById('payment-destination');
    const syncMethod = () => {
      const method = document.querySelector('#payment-form input[name="method"]:checked')?.value || 'cash';
      const isGcash = method === 'gcash';
      const isBank = method === 'bank_transfer';
      const requiresReference = isGcash || isBank;
      document.querySelectorAll('#payment-form .pay-method').forEach((card) => {
        card.classList.toggle('is-selected', card.querySelector('input')?.checked);
      });
      if (destination) {
        destination.innerHTML = paymentDestinationMarkup(method);
        destination.classList.toggle('hidden', !isGcash && !isBank);
      }
      if (refLabel) refLabel.textContent = isGcash ? 'GCash Reference No. (required)' : 'Bank Transfer Reference No. (required)';
      if (refInput) {
        refInput.required = requiresReference;
        refInput.disabled = !requiresReference;
        if (!requiresReference) refInput.value = '';
        const refWrap = document.getElementById('payment-ref-wrap');
        if (refWrap) refWrap.hidden = !requiresReference;
        refInput.placeholder = isGcash ? 'e.g. 1012 345 678901' : isBank ? 'e.g. BDO-20261021-0001' : 'e.g. OR-1004';
        refInput.inputMode = isGcash ? 'numeric' : 'text';
      }
    };
    document.querySelectorAll('#payment-form input[name="method"]').forEach((r) => r.addEventListener('change', syncMethod));
    syncMethod();

    const paymentHistory = (d.history || []).filter((payment) => Number(payment.amount) >= 0.01);
    const tbody = document.getElementById('payments-tbody');
    if (tbody) {
      const rowHtmls = paymentHistory.map(
        (p) => `<tr>
          <td>${fmtBillingDateTime(p.paid_at || p.created_at)}</td>
          <td>${e(p.invoice_number)}${p.room_number ? `<div class="cell-meta">Room ${e(p.room_number)}</div>` : ''}${p.reference_no ? `<div class="cell-meta">Ref ${e(p.reference_no)}</div>` : ''}</td>
          <td>${money(p.amount)}</td>
          <td>${payMethodBadge(p.method)}</td>
          <td><a class="btn btn-secondary btn-sm" href="${e(paymentReceiptExportUrl(p.id))}" download>Export PDF</a></td>
        </tr>`
      );
      paginateTable(tbody, rowHtmls, { emptyHtml: '<tr><td colspan="5" class="empty">No payments yet.</td></tr>' });
    }

    document.getElementById('payment-form')?.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      try {
        const payload = Object.fromEntries(new FormData(ev.target).entries());
        if (Math.round(Number(payload.amount) * 100) / 100 < 0.01) {
          throw new Error('Payment amount must be at least 0.01.');
        }
        const account = selectedAccount();
        if (!account) throw new Error('Select a guest account before recording a payment.');
        if (payload.method !== 'cash' && !String(payload.reference_no || '').trim()) {
          throw new Error('Enter a transfer reference before recording the payment.');
        }
        payload.booking_id = account.representative_booking_id || '';
        payload.bill_id = account.representative_bill_id;
        const result = await apiPost('payments.php', 'recordAccountPayment', payload);
        location.href = `payments.html?tab=invoices&id=${encodeURIComponent(result.data?.bill_id || account.representative_bill_id || '')}`;
      } catch (err) {
        showAlert(document.getElementById('flash'), 'error', err.message);
      }
    });
  }

  async function renderInvoicesFd(main, options = {}) {
    if (!options.skipMount) mountPageTemplate(main);
    const id = qs('id');
    const res = await apiGet('bills.php', 'getInvoices', id ? { id: Number(id) } : {});
    const d = res.data;

    const listTbody = document.getElementById('invoices-list');
    if (listTbody) {
      const rowHtmls = (d.account_history || []).map((account) => {
        const accountUrl = account.representative_booking_id
          ? `guest-account.html?booking_id=${account.representative_booking_id}`
          : `guest-account.html?bill_id=${account.representative_bill_id}`;
        const stay = account.check_in
          ? `${fmtBillingDateTime(account.check_in)} → ${fmtBillingDateTime(account.check_out)}`
          : 'Manual guest account';
        return `<tr>
          <td data-label="Guest account"><strong>${e(account.full_name)}</strong><div class="cell-meta">${e(account.invoice_number || '')}</div></td>
          <td data-label="Rooms / Stay">${Number(account.room_count) ? `Room${Number(account.room_count) === 1 ? '' : 's'} ${e(account.room_numbers || '')}` : 'Manual account'}<div class="cell-meta">${stay}</div></td>
          <td data-label="Total charges">${money(account.total_amount)}</td>
          <td data-label="Paid">${money(account.paid_amount)}</td>
          <td data-label="Balance">${money(account.balance)}</td>
          <td data-label="Status">${statusBadge(account.account_status)}</td>
          <td class="actions" data-label="Actions">
            <a class="btn btn-secondary btn-sm" href="${accountUrl}">Open Account</a>
            <a class="btn btn-teal btn-sm" href="payments.html?tab=invoices&id=${account.representative_bill_id}">Invoice / Receipts</a>
          </td>
        </tr>`;
      });
      paginateTable(listTbody, rowHtmls, {
        emptyHtml: '<tr><td colspan="7" class="empty">No billing history is available for checked-in or checked-out accounts.</td></tr>',
      });
    }

    const detail = document.getElementById('invoice-detail') || document.getElementById('invoice-print');
    if (detail) {
      if (!d.bill) {
        detail.innerHTML = '<p class="empty">Select an account invoice to view charges and payment receipts.</p>';
      } else {
        const bill = d.bill;
        const accountUrl = bill.booking_id
          ? `guest-account.html?booking_id=${bill.booking_id}`
          : `guest-account.html?bill_id=${bill.id}`;
        const hasInvoiceAmount = Number(bill.total_amount) >= 0.01;
        detail.innerHTML = `<div class="row-between">
          <div>
            <h2>${e(d.hotel?.hotel_name || 'Invoice')} — ${e(bill.invoice_number)}</h2>
            <p><strong>${e(bill.full_name || '')}</strong>${bill.phone ? ` · ${e(bill.phone)}` : ''}</p>
            <p class="muted">Room ${e(bill.room_number || '—')} · Booking #${e(bill.booking_id || '—')} · Stay ${fmtBillingDateTime(bill.stay_check_in)} → ${fmtBillingDateTime(bill.stay_check_out)}</p>
          </div>
          <div class="actions no-print">
            <a class="btn btn-secondary btn-sm" href="${accountUrl}">Open Guest Account</a>
            ${Number(bill.balance) > 0.009 ? `<a class="btn btn-teal btn-sm" href="payments.html?bill_id=${bill.id}">Record Payment</a>` : ''}
            ${hasInvoiceAmount ? `<a class="btn btn-primary btn-sm" href="${e(invoiceExportUrl(bill.id))}" download>Export PDF</a>` : ''}
          </div>
        </div>
        ${hasInvoiceAmount ? '' : '<p class="notice-panel">This invoice cannot be exported until its charges total at least 0.01.</p>'}
        <h3>Charges</h3>
        ${tableWrap(`<thead><tr><th>Date &amp; Time</th><th>Description</th><th>Qty</th><th>Amount</th></tr></thead>
          <tbody id="invoice-items-tbody"></tbody>`)}
        <ul class="kpi-list">
          <li><span>Total charges</span><strong>${money(bill.total_amount)}</strong></li>
          <li><span>Total paid</span><strong>${money(bill.paid_amount)}</strong></li>
          <li><span>Balance due</span><strong>${money(bill.balance)}</strong></li>
          <li><span>Payment status</span><strong>${statusBadge(bill.status)}</strong></li>
        </ul>
        <h3>Payment Receipts</h3>
        ${tableWrap(`<thead><tr><th>Date &amp; Time</th><th>Room / Invoice</th><th>Amount</th><th>Method / Reference</th><th>Received By</th><th></th></tr></thead>
          <tbody id="invoice-payments-tbody"></tbody>`)}`;
        const itemsTbody = document.getElementById('invoice-items-tbody');
        if (itemsTbody) {
          const itemRowHtmls = (d.items || []).map(
            (i) => `<tr><td>${fmtBillingDateTime(i.created_at)}</td><td>${e(i.description)}${i.delivery_room_number ? `<div class="cell-meta">Deliver to Room ${e(i.delivery_room_number)}</div>` : ''}</td><td>${e(quantityLabel(i.quantity))}</td><td>${money(i.amount)}</td></tr>`
          );
          paginateTable(itemsTbody, itemRowHtmls, { emptyHtml: '<tr><td colspan="4" class="empty">No charges.</td></tr>' });
        }
        const paymentsTbody = document.getElementById('invoice-payments-tbody');
        if (paymentsTbody) {
          const paymentRows = (d.payments || []).filter((payment) => Number(payment.amount) >= 0.01).map((payment) => `<tr>
            <td data-label="Date &amp; Time">${fmtBillingDateTime(payment.paid_at)}</td>
            <td data-label="Room / Invoice">${payment.room_number ? `Room ${e(payment.room_number)}` : 'Guest account'}<div class="cell-meta">${e(payment.invoice_number || '')}</div></td>
            <td data-label="Amount">${money(payment.amount)}</td>
            <td data-label="Method / Reference">${payMethodBadge(payment.method)}${payment.reference_no ? `<div class="cell-meta">Ref ${e(payment.reference_no)}</div>` : ''}</td>
            <td data-label="Received By">${e(payment.cashier || 'Staff unavailable')}</td>
            <td class="actions" data-label="Receipt"><a class="btn btn-secondary btn-sm" href="${e(paymentReceiptExportUrl(payment.id))}" download>Export PDF</a></td>
          </tr>`);
          paginateTable(paymentsTbody, paymentRows, {
            emptyHtml: '<tr><td colspan="6" class="empty">No payment receipts have been recorded on this account.</td></tr>',
          });
        }
      }
    }
  }

  async function renderProfile(main) {
    mountPageTemplate(main);
    const res = await apiGet('profile.php', 'getProfile');
    const { profile, show_gender } = res.data;

    const profForm = document.getElementById('prof-form');
    if (profForm) {
      fillFormValues(profForm, {
        full_name: profile.full_name,
        email: profile.email,
        phone: profile.phone || '',
        gender: profile.gender,
      });
    }
    const genderWrap = document.getElementById('prof-gender-wrap');
    if (genderWrap) genderWrap.hidden = !show_gender;
    const genderSel = document.getElementById('prof-gender');
    if (genderSel && profile.gender) {
      genderSel.value = profile.gender;
    }

    const flash = document.getElementById('flash');
    document.getElementById('prof-form').addEventListener('submit', async (ev) => {
      ev.preventDefault();
      try {
        const r = await apiPost('profile.php', 'updateProfile', Object.fromEntries(new FormData(ev.target).entries()));
        showAlert(flash, 'success', r.message);
      } catch (err) {
        showAlert(flash, 'error', err.message);
      }
    });
    document.getElementById('pw-form').addEventListener('submit', async (ev) => {
      ev.preventDefault();
      try {
        const r = await apiPost('profile.php', 'changePassword', Object.fromEntries(new FormData(ev.target).entries()));
        showAlert(flash, 'success', r.message);
        ev.target.reset();
      } catch (err) {
        showAlert(flash, 'error', err.message);
      }
    });
  }

  async function renderGuestBill(main) {
    mountPageTemplate(main);
    const pendingPaymentMessage = sessionStorage.getItem('guest-account-flash');
    if (pendingPaymentMessage) {
      sessionStorage.removeItem('guest-account-flash');
      showAlert(document.getElementById('flash'), 'success', pendingPaymentMessage);
    }
    const id = qs('id');
    const res = await apiGet('bills.php', 'getGuestBill', id ? { id: Number(id) } : {});
    const d = res.data;
    const bill = d.bill;
    const flash = document.getElementById('flash');

    const summary = document.getElementById('guest-bill-summary');
    if (summary) {
      summary.innerHTML = !bill
        ? '<p class="empty">No bill yet.</p>'
        : `<ul class="kpi-list">
    <li><span>Invoice</span><strong>${e(bill.invoice_number)}</strong></li>
    <li><span>Total</span><strong>${money(bill.total_amount)}</strong></li>
    <li><span>Paid</span><strong>${money(bill.paid_amount)}</strong></li>
    <li><span>Balance</span><strong>${money(bill.balance)}</strong></li>
    <li><span>Payment Status</span><strong>${statusBadge(bill.status)}<small style="display:block;font-weight:400;">${e(paymentStatusDescription(bill.status))}</small></strong></li>
  </ul>`;
    }

    const roomsTbody = document.getElementById('guest-account-rooms');
    if (roomsTbody) {
      const roomRows = (d.account_rooms || []).map((room) => {
        const stay = room.check_in
          ? `${fmtStayPoint(room.check_in, room.check_in_time)} → ${fmtStayPoint(room.check_out, room.check_out_time)}`
          : 'Manual guest account';
        return `<tr>
          <td><strong>${room.room_number ? `Room ${e(room.room_number)} · ${e(room.room_type || '')}` : 'Guest account'}</strong><div class="cell-meta stay-dates-cell">${stay}</div></td>
          <td>${e(room.invoice_number || 'No invoice')}</td>
          <td class="numeric-cell">${money(room.total_amount || 0)}</td>
          <td class="numeric-cell">${money(room.paid_amount || 0)}</td>
          <td class="numeric-cell">${money(room.balance || 0)}</td>
          <td>${room.bill_status ? statusBadge(room.bill_status) : '<span class="badge badge-muted">No bill</span>'}</td>
          <td>${roomRequestsDropdown(room)}</td>
        </tr>`;
      });
      paginateTable(roomsTbody, roomRows, {
        emptyHtml: '<tr><td colspan="7" class="empty">No rooms are linked to this guest account.</td></tr>',
      });
      renderStayChangeHistory(
        document.getElementById('guest-account-stay-changes'),
        d.extension_history,
        d.transfer_history
      );
    }

    const paymentRequests = d.payment_requests || [];
    const pendingRequested = paymentRequests
      .filter((request) => request.status === 'pending')
      .reduce((sum, request) => sum + Number(request.amount || 0), 0);
    const balance = Number(d.account_balance || bill?.balance || 0);
    const availableToRequest = Math.max(0, balance - pendingRequested);
    const paymentPanel = document.getElementById('guest-payment-request-panel');
    if (paymentPanel) {
      paymentPanel.classList.toggle('hidden', !bill || availableToRequest <= 0.009);
      document.getElementById('guest-payment-request-balance').textContent =
        `Account balance ${money(balance)} · Pending requests ${money(pendingRequested)} · Available to request ${money(availableToRequest)}`;
      document.getElementById('guest-payment-request-bill-id').value = bill?.id || '';
      const amountInput = document.getElementById('guest-payment-request-amount');
      amountInput.max = availableToRequest.toFixed(2);
      amountInput.value = availableToRequest > 0 ? availableToRequest.toFixed(2) : '';
      const methodInput = document.getElementById('guest-payment-request-method');
      const referenceWrap = document.getElementById('guest-payment-request-reference-wrap');
      const referenceInput = document.getElementById('guest-payment-request-reference');
      const paymentDestination = document.getElementById('guest-payment-request-destination');
      const syncRequestMethod = () => {
        const requiresReference = methodInput.value !== 'cash';
        referenceWrap.hidden = !requiresReference;
        referenceInput.required = requiresReference;
        referenceInput.inputMode = methodInput.value === 'gcash' ? 'numeric' : 'text';
        referenceInput.placeholder = methodInput.value === 'gcash'
          ? 'GCash reference (at least 8 digits)'
          : 'Bank reference (at least 6 letters or digits)';
        if (paymentDestination) {
          paymentDestination.innerHTML = paymentDestinationMarkup(methodInput.value);
          paymentDestination.classList.toggle('hidden', !requiresReference);
        }
      };
      methodInput.addEventListener('change', syncRequestMethod);
      syncRequestMethod();
      paymentPanel.querySelector('#guest-payment-request-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const payload = Object.fromEntries(new FormData(event.currentTarget).entries());
        const amount = Number(payload.amount);
        if (Math.round(amount * 100) / 100 < 0.01) {
          showAlert(flash, 'error', 'Payment request amount must be at least 0.01.');
          return;
        }
        if (amount > availableToRequest + 0.009) {
          showAlert(flash, 'error', `Enter an amount up to ${money(availableToRequest)}. Pending requests are excluded.`);
          return;
        }
        const submit = document.getElementById('guest-payment-request-submit');
        submit.disabled = true;
        try {
          const result = await apiPost('payments.php', 'createGuestPaymentRequest', payload);
          sessionStorage.setItem('guest-account-flash', result.message || 'Payment request submitted.');
          location.reload();
        } catch (error) {
          showAlert(flash, 'error', error.message);
          submit.disabled = false;
        }
      });
    }

    const requestTbody = document.getElementById('guest-payment-requests');
    if (requestTbody) {
      const requestRows = paymentRequests.map((request) => {
        const ids = String(request.payment_ids || '').split(',').filter(Boolean);
        return `<tr>
          <td>${fmtBillingDateTime(request.requested_at)}</td>
          <td>${request.room_numbers ? `Rooms ${e(request.room_numbers)}` : 'Guest account'}<div class="cell-meta">${e(request.invoice_number || '')}</div></td>
          <td>${money(request.amount)}</td>
          <td>${payMethodBadge(request.method)}${request.reference_no ? `<div class="cell-meta">${e(request.reference_no)}</div>` : ''}</td>
          <td>${statusBadge(request.status)}${request.processed_by_name ? `<div class="cell-meta">By ${e(request.processed_by_name)}</div>` : ''}</td>
          <td class="actions">${request.status === 'pending'
            ? `<button class="btn btn-danger btn-sm" type="button" data-delete-payment-request="${e(request.id)}">Delete request</button>`
            : request.status === 'completed' && ids.length
              ? `<a class="btn btn-secondary btn-sm" href="${e(paymentReceiptExportUrl(ids))}" download>Export PDF</a>`
              : '—'}</td>
        </tr>`;
      });
      paginateTable(requestTbody, requestRows, {
        emptyHtml: '<tr><td colspan="6" class="empty">No payment requests for this account.</td></tr>',
      });
      requestTbody.addEventListener('click', async (event) => {
        const remove = event.target.closest('[data-delete-payment-request]');
        if (remove) {
          if (!window.confirm('Delete this pending payment request?')) return;
          try {
            await apiPost('payments.php', 'cancelGuestPaymentRequest', { request_id: remove.dataset.deletePaymentRequest });
            sessionStorage.setItem('guest-account-flash', 'Payment request deleted.');
            location.reload();
          } catch (error) {
            showAlert(flash, 'error', error.message);
          }
          return;
        }
      });
    }

    const roomTbody = document.getElementById('guest-bill-room-tbody');
    if (roomTbody) {
      const rowHtmls = (d.room_items || []).map((i) => `<tr><td>${e(i.description)}${i.delivery_room_number ? `<div class="cell-meta">Deliver to Room ${e(i.delivery_room_number)}</div>` : ''}</td><td>${money(i.amount)}</td></tr>`);
      paginateTable(roomTbody, rowHtmls, { emptyHtml: '<tr><td colspan="2" class="empty">None</td></tr>' });
    }

    const svcTbody = document.getElementById('guest-bill-service-tbody');
    if (svcTbody) {
      const rowHtmls = (d.service_items || []).map((i) => `<tr><td>${e(i.description)}${i.delivery_room_number ? `<div class="cell-meta">Deliver to Room ${e(i.delivery_room_number)}</div>` : ''}</td><td>${money(i.amount)}</td></tr>`);
      paginateTable(svcTbody, rowHtmls, { emptyHtml: '<tr><td colspan="2" class="empty">None</td></tr>' });
    }
  }

  async function renderGuestPayments(main) {
    mountPageTemplate(main);
    const res = await apiGet('payments.php', 'getGuestPayments');
    const rows = res.data || [];
    const tbody = document.getElementById('guest-pay-tbody');
    if (tbody) {
      const rowHtmls = rows.map(
        (p) => `<tr>
          <td>${fmtBillingDateTime(p.paid_at || p.created_at)}</td>
          <td>${e(p.invoice_number)}${p.room_number ? `<div class="cell-meta">Room ${e(p.room_number)}</div>` : ''}${p.reference_no ? `<div class="cell-meta">Ref ${e(p.reference_no)}</div>` : ''}</td>
          <td>${money(p.amount)}</td>
          <td>${payMethodBadge(p.method)}</td>
          <td><a class="btn btn-secondary btn-sm" href="${e(paymentReceiptExportUrl(p.id))}" download>Export PDF</a></td>
        </tr>`
      );
      paginateTable(tbody, rowHtmls, { emptyHtml: '<tr><td colspan="5" class="empty">No payments.</td></tr>' });
    }
  }

  async function renderGuestInvoices(main) {
    mountPageTemplate(main);
    const id = qs('id');
    const res = await apiGet('bills.php', 'getGuestInvoices', id ? { id: Number(id) } : {});
    const d = res.data;

    const listTbody = document.getElementById('guest-inv-list');
    if (listTbody) {
      const rowHtmls = (d.list || []).filter((bill) => Number(bill.total_amount) >= 0.01).map(
        (b) => `<tr>
      <td>${e(b.invoice_number)}</td><td>${money(b.total_amount)}</td><td>${statusBadge(b.status)}</td>
      <td><a class="btn btn-secondary btn-sm" href="invoices.html?id=${b.id}">View Invoice / Receipts</a></td></tr>`
      );
      paginateTable(listTbody, rowHtmls, { emptyHtml: '<tr><td colspan="4" class="empty">No invoices.</td></tr>' });
    }

    const detail = document.getElementById('guest-inv-detail');
    if (detail) {
      if (!d.bill) {
        detail.innerHTML = '<p class="empty">Select an invoice.</p>';
      } else {
        const hasInvoiceAmount = Number(d.bill.total_amount) >= 0.01;
        detail.innerHTML = `<div class="row-between"><h2>${e(d.bill.invoice_number)}</h2>
          ${hasInvoiceAmount ? `<a class="btn btn-primary btn-sm" href="${e(invoiceExportUrl(d.bill.id))}" download>Export PDF</a>` : ''}</div>
      ${hasInvoiceAmount ? '' : '<p class="notice-panel">This invoice cannot be exported until its charges total at least 0.01.</p>'}
      ${tableWrap(`<thead><tr><th>Item</th><th>Amount</th></tr></thead>
      <tbody id="guest-inv-items-tbody"></tbody>`)}
      <p>Total ${money(d.bill.total_amount)} · Paid ${money(d.bill.paid_amount)} · Balance ${money(d.bill.balance)}</p>
      <h3>Payment Receipts</h3>
      ${tableWrap(`<thead><tr><th>Date &amp; Time</th><th>Invoice / Room</th><th>Amount</th><th>Method</th><th>Receipt</th></tr></thead>
      <tbody id="guest-inv-payments-tbody"></tbody>`)}`;
        const itemsTbody = document.getElementById('guest-inv-items-tbody');
        if (itemsTbody) {
          const itemRowHtmls = (d.items || []).map((i) => `<tr><td>${e(i.description)}${i.delivery_room_number ? `<div class="cell-meta">Deliver to Room ${e(i.delivery_room_number)}</div>` : ''}</td><td>${money(i.amount)}</td></tr>`);
          paginateTable(itemsTbody, itemRowHtmls, { emptyHtml: '<tr><td colspan="2" class="empty">No items.</td></tr>' });
        }
        const paymentsTbody = document.getElementById('guest-inv-payments-tbody');
        if (paymentsTbody) {
          const paymentRows = (d.payments || []).filter((payment) => Number(payment.amount) >= 0.01).map((payment) => `<tr>
            <td>${fmtBillingDateTime(payment.paid_at)}</td>
            <td>${e(payment.invoice_number)}${payment.room_number ? `<div class="cell-meta">Room ${e(payment.room_number)}</div>` : ''}</td>
            <td>${money(payment.amount)}</td>
            <td>${payMethodBadge(payment.method)}${payment.reference_no ? `<div class="cell-meta">Ref ${e(payment.reference_no)}</div>` : ''}</td>
            <td><a class="btn btn-secondary btn-sm" href="${e(paymentReceiptExportUrl(payment.id))}" download>Export PDF</a></td>
          </tr>`);
          paginateTable(paymentsTbody, paymentRows, {
            emptyHtml: '<tr><td colspan="5" class="empty">No payment receipts have been recorded for this invoice.</td></tr>',
          });
        }
      }
    }
  }

  async function renderGuestBooking(main) {
    mountPageTemplate(main);
    const res = await apiGet('bookings.php', 'getGuestBookings');
    const { active, history } = res.data;

    const activeBox = document.getElementById('guest-booking-active');
    if (activeBox) {
      activeBox.innerHTML = !active
        ? '<p class="empty">No active booking.</p>'
        : `<ul class="kpi-list">
    <li><span>Room</span><strong>${e(active.room_number)} · ${e(active.room_type)}</strong></li>
    <li><span>Room Features</span><strong>${e(active.room_features || '—')}</strong></li>
    <li><span>Check-In</span><strong>${fmtStayPoint(active.check_in, active.check_in_time)}</strong></li>
    <li><span>Check-Out</span><strong>${fmtStayPoint(active.check_out, active.check_out_time)}</strong></li>
    <li><span>Status</span><strong>${statusBadge(active.status)}</strong></li>
  </ul>`;
    }

    const histTbody = document.getElementById('guest-booking-history');
    if (histTbody) {
      const rowHtmls = (history || []).map(
        (b) => `<tr><td>${e(b.room_number)}</td><td>${fmtStayPoint(b.check_in, b.check_in_time)} → ${fmtStayPoint(b.check_out, b.check_out_time)}</td><td>${statusBadge(b.status)}</td></tr>`
      );
      paginateTable(histTbody, rowHtmls, { emptyHtml: '<tr><td colspan="3" class="empty">No history.</td></tr>' });
    }
  }


  /* ---------- Router ---------- */

  /* Copies each column heading onto its cells so tables can stack as cards on phones. */
  function labelTableCells(root) {
    root.querySelectorAll('table').forEach((table) => {
      const heads = [...table.querySelectorAll('thead th')].map((th) => th.textContent.trim());
      if (!heads.length) return;
      table.querySelectorAll('tbody tr').forEach((tr) => {
        [...tr.children].forEach((td, i) => {
          if (td.tagName === 'TD' && !td.hasAttribute('colspan') && !td.hasAttribute('data-label') && heads[i]) {
            td.setAttribute('data-label', heads[i]);
          }
        });
      });
    });
  }
  let labelQueued = false;
  new MutationObserver(() => {
    if (labelQueued) return;
    labelQueued = true;
    requestAnimationFrame(() => {
      labelQueued = false;
      labelTableCells(document.body);
    });
  }).observe(document.documentElement, { childList: true, subtree: true });

  document.addEventListener('DOMContentLoaded', async () => {
    const page = document.body.dataset.page || '';
    const role = document.body.dataset.role || '';

    if (page === 'home') {
      await pageHome();
      bindGlobalUi(document.body);
      startRealtime(async () => {
        await pageHome();
        bindGlobalUi(document.body);
      });
      return;
    }
    if (page === 'login') {
      await pageLogin();
      bindGlobalUi(document.body);
      return;
    }
    if (page === 'appointment' || page === 'manage-appointment') {
      if (page === 'appointment') await renderPublicAppointment();
      else await renderPublicAppointmentManagement();
      bindGlobalUi(document.body);
      return;
    }
    if (page === 'register') {
      window.location.href = `${BASE_PATH}/login.html`;
      return;
    }

    const map = {
      'admin:dashboard': ['Dashboard', ['admin'], 'dashboard', renderAdminDashboard],
      'admin:room-management': ['Rooms, Stays & Transfers', ['admin'], 'room-management', renderRoomStayManagement],
      'admin:users': ['Users & Roles', ['admin'], 'users', renderUsers],
      'admin:room-types': ['Room Management System', ['admin'], 'room-types', renderRoomTypes],
      'admin:requested-items': ['Guest Requests & Items', ['admin'], 'requested-items', renderCombinedRequests],
      'admin:rooms': ['Rooms & Hotel Services', ['admin'], 'rooms', (m, s) => renderRoomsServices(m, s, true)],
      'admin:guests': ['Guest Management', ['admin'], 'guests', renderGuestManagement],
      'admin:housekeeping': ['Request Assignments & Room Inspections', ['admin'], 'housekeeping', renderFrontdeskOperations],
      'admin:billing': ['Billing & Discounts', ['admin'], 'billing', renderAdminBillingDiscounts],
      'admin:stay-management': ['Check-In / Check-Out', ['admin'], 'stay-management', renderStayManagement],
      'admin:appointments': ['Room Appointments', ['admin'], 'appointments', renderStaffAppointments],
      'admin:appointment-view': ['Appointment Request Details', ['admin'], 'appointment-view', renderAdminAppointmentView],
      'admin:reports': ['Reports', ['admin'], 'reports', renderReports],
      'admin:audit': ['System Audit Logs', ['admin'], 'audit', (m) => renderAudit(m, false)],
      'admin:profile': ['My Profile', ['admin'], 'profile', renderProfile],

      'frontdesk:dashboard': ['Front Desk', ['frontdesk', 'admin'], 'dashboard', renderFrontdeskDashboard],
      'frontdesk:room-management': ['Rooms, Stays & Transfers', ['frontdesk', 'admin'], 'room-management', renderRoomStayManagement],
      'frontdesk:guests': ['Guest Management', ['frontdesk', 'admin'], 'guests', renderGuestManagement],
      'frontdesk:operations': ['Request Assignments & Room Inspections', ['frontdesk', 'admin'], 'operations', renderFrontdeskOperations],
      'frontdesk:stay-management': ['Check-In / Check-Out', ['frontdesk', 'admin'], 'stay-management', renderStayManagement],
      'frontdesk:appointments': ['Room Appointments', ['frontdesk', 'admin'], 'appointments', renderStaffAppointments],
      'frontdesk:rooms': ['Rooms & Hotel Services', ['frontdesk', 'admin'], 'rooms', (m, s) => renderRoomsServices(m, s, false)],
      'frontdesk:billing': ['Guest Accounts & Charges', ['frontdesk', 'admin'], 'billing', renderBillingFd],
      'frontdesk:reports': ['Reports', ['frontdesk', 'admin'], 'reports', renderReports],
      'frontdesk:guest-account': ['Guest Account', ['frontdesk', 'admin'], 'guest-account', renderGuestAccount],
      'frontdesk:payments': ['Payments & Invoices', ['frontdesk', 'admin'], 'payments', renderPaymentsInvoices],
      'frontdesk:audit': ['Staff Audit Logs', ['frontdesk', 'admin'], 'audit', (m) => renderAudit(m, true)],
      'frontdesk:profile': ['My Profile', ['frontdesk', 'admin'], 'profile', renderProfile],

      'kitchen:dashboard': ['Kitchen Requests', ['kitchen'], 'dashboard', renderOperationsQueue],
      'kitchen:profile': ['My Profile', ['kitchen'], 'profile', renderProfile],
      'laundry:dashboard': ['Amenities & Laundry Requests', ['laundry'], 'dashboard', renderOperationsQueue],
      'laundry:profile': ['My Profile', ['laundry'], 'profile', renderProfile],
      'housekeeping:dashboard': ['Housekeeping Inspections', ['housekeeping'], 'dashboard', renderHousekeepingQueue],
      'housekeeping:profile': ['My Profile', ['housekeeping'], 'profile', renderProfile],

      'guest:dashboard': ['My Stay', ['guest'], 'dashboard', renderGuestDashboard],
      'guest:bill': ['Current Bill', ['guest'], 'bill', renderGuestBill],
      'guest:requests': ['Request Items', ['guest'], 'requests', renderGuestRequests],
      'guest:payments': ['Payment History', ['guest'], 'payments', renderGuestPayments],
      'guest:invoices': ['Invoices', ['guest'], 'invoices', renderGuestInvoices],
      'guest:booking': ['Booking Info', ['guest'], 'booking', renderGuestBooking],
      'guest:transfers': ['Room Transfers', ['guest'], 'transfers', renderGuestTransfers],
      'guest:profile': ['My Profile', ['guest'], 'profile', renderProfile],
    };

    const key = `${role}:${page}`;
    const cfg = map[key];
    if (!cfg) {
      document.getElementById('app').innerHTML = `<div class="alert alert-error">Unknown page: ${e(key)}</div>`;
      return;
    }
    const [title, roles, active, fn] = cfg;
    await bootAppPage(title, roles, active, async (main, session) => {
      await fn(main, session);
      bindGlobalUi(main);
    });
  });
})();
