/* Meet With Me — Booking Wizard
 * Vanilla JS, no dependencies.
 * Reads mwmData (restUrl, siteUrl, maxAdvanceDays, strings) injected by wp_localize_script.
 */
/* jshint esversion: 11 */
(function () {
    'use strict';

    const REST = (window.mwmData || {}).restUrl || '';
    const S    = (window.mwmData || {}).strings || {};

    // -------------------------------------------------------------------------
    // i18n + utilities
    // -------------------------------------------------------------------------
    function fmt(str, ...args) {
        let auto = 0;
        return String(str).replace(/%(\d+)\$s|%s/g, (m, n) => {
            const idx = n ? (parseInt(n, 10) - 1) : (auto++);
            return args[idx] != null ? String(args[idx]) : '';
        });
    }

    function t(key, fallback, ...args) {
        const str = (S[key] != null && S[key] !== '') ? S[key] : (fallback != null ? fallback : key);
        return args.length ? fmt(str, ...args) : str;
    }

    function prefersReducedMotion() {
        try {
            return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        } catch (e) {
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // REST helper
    // -------------------------------------------------------------------------
    async function api(path, options = {}) {
        const headers = { ...(options.headers || {}) };
        if (options.body && !headers['Content-Type']) {
            headers['Content-Type'] = 'application/json';
        }

        const res = await fetch(REST + path, {
            headers,
            ...options,
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(json.message || t('requestFailed', 'Request failed. Please try again.'));
        return json;
    }

    // -------------------------------------------------------------------------
    // Wizard class
    // -------------------------------------------------------------------------
    class MWMWizard {
        constructor(container, options = {}) {
            MWMWizard.counter = (MWMWizard.counter || 0) + 1;
            this.uid         = 'mwm' + MWMWizard.counter;
            this.options     = options;
            this.container   = container;
            this.presetSlug  = container.dataset.eventType || '';
            this.tz          = (() => { try { return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'; } catch(e) { return 'UTC'; } })();
            this.maxAdvanceDays = Math.max(1, Number((window.mwmData || {}).maxAdvanceDays || 60));
            this._isBound    = false;
            this._monthRequestId = 0;
            this._slotsRequestId = 0;
            this._eventTypesRequestId = 0;
            this._lastStep   = undefined;   // Used to detect step changes for focus/announcements.
            this._pendingStepFocus = false;
            this._refocus    = null;        // Pending focus selector for non-step re-renders (month nav / Today / Retry).
            this._pickerFocusPending = false;
            this._pickerReturnFocus  = false;
            this._pickerYearFocusPending = false;
            const now        = new Date();
            this.s           = {                    // state
                step: 1,
                eventTypes: null,
                eventType: null,
                year: now.getFullYear(),
                month: now.getMonth() + 1,
                pickerYear: now.getFullYear(),
                monthPickerOpen: false,
                availableDates: null,
                date: null,
                slots: null,
                slot: null,
                meetingType: null,
                booker: {},
                fieldAnswers: {},
                loading: false,
                error: null,
                confirmation: null,
            };
            this.el = container.querySelector('.mwm-booking-wizard__inner') || container;

            // Persistent live regions (kept outside the repainted inner element).
            this._livePolite = document.createElement('div');
            this._livePolite.className = 'mwm-sr-only';
            this._livePolite.setAttribute('aria-live', 'polite');
            this.container.appendChild(this._livePolite);

            this._liveAssertive = document.createElement('div');
            this._liveAssertive.className = 'mwm-sr-only';
            this._liveAssertive.setAttribute('aria-live', 'assertive');
            this._liveAssertive.setAttribute('role', 'alert');
            this.container.appendChild(this._liveAssertive);

            this._init();
        }

        async _init() {
            if (this.presetSlug) {
                try {
                    const types = await api('/event-types');
                    const et = types.find(t => t.slug === this.presetSlug);
                    if (et) { this.s.eventType = et; this.s.step = 2; }
                } catch(e) { /* fall through to step 1 */ }
            }
            this._render();
        }

        // -- Announcements / focus --------------------------------------------

        _announce(msg) {
            if (this._livePolite) this._livePolite.textContent = msg;
        }

        _announceError(msg) {
            if (this._liveAssertive) this._liveAssertive.textContent = msg;
        }

        _scrollTo(target, options = {}) {
            if (!target || typeof target.scrollIntoView !== 'function') return;
            const behavior = prefersReducedMotion() ? 'auto' : (options.behavior || 'smooth');
            target.scrollIntoView({ behavior, block: options.block || 'start' });
        }

        // -- Render -----------------------------------------------------------

        _render() {
            const stepChanged = this._lastStep !== undefined && this.s.step !== this._lastStep;
            if (stepChanged) this._pendingStepFocus = true;

            this.el.innerHTML = this._html();
            this._bind();

            if (stepChanged) this._announceStepChange();

            // After a step change, move focus to the step heading as soon as it
            // exists (some steps render a loading placeholder first).
            if (this._pendingStepFocus) {
                const heading = this.el.querySelector('.mwm-step__title, .mwm-confirm__title');
                if (heading) {
                    this._pendingStepFocus = false;
                    try { heading.focus(); } catch (e) { /* noop */ }
                }
            }

            // Month picker focus management (open → first month, close → trigger button).
            if (this._pickerFocusPending && this.s.monthPickerOpen) {
                const target = this.el.querySelector('.mwm-cal__picker-month:not(:disabled)') ||
                    this.el.querySelector('.mwm-cal__picker-select');
                if (target) {
                    this._pickerFocusPending = false;
                    try { target.focus(); } catch (e) { /* noop */ }
                }
            }
            if (this._pickerYearFocusPending) {
                const yearSelect = this.el.querySelector('[data-a="picker-year"]');
                if (yearSelect) {
                    this._pickerYearFocusPending = false;
                    try { yearSelect.focus(); } catch (e) { /* noop */ }
                }
            }
            if (this._pickerReturnFocus) {
                const trigger = this.el.querySelector('[data-a="toggle-month-picker"]');
                if (trigger) {
                    this._pickerReturnFocus = false;
                    try { trigger.focus(); } catch (e) { /* noop */ }
                }
            }

            // Pending focus for non-step re-renders (month nav / Today / Retry):
            // keep the flag until the target exists so focus lands after any
            // async loader has painted (same semantics as _pendingStepFocus).
            if (this._refocus) {
                const refocusTarget = this.el.querySelector(this._refocus);
                if (refocusTarget) {
                    this._refocus = null;
                    try { refocusTarget.focus(); } catch (e) { /* noop */ }
                }
            }

            this._lastStep = this.s.step;
        }

        _announceStepChange() {
            const step = this.s.step;
            // Guard against re-entrant renders (async loaders set state mid-render),
            // which would otherwise announce the same step twice.
            if (this._lastAnnouncedStep === step) return;
            this._lastAnnouncedStep = step;

            if (step >= 2 && step <= 4) {
                const labels = { 2: t('stepDate', 'Choose a date'), 3: t('stepTime', 'Choose a time'), 4: t('stepDetails', 'Your details') };
                this._announce(fmt(t('stepAnnounce', 'Step %1$s of 3: %2$s'), step - 1, labels[step]));
            } else if (step === 5) {
                this._announce(t('booked', 'You’re booked!'));
            } else if (step === 1) {
                this._announce(t('chooseTypeTitle', 'What type of meeting?'));
            }
        }

        _html() {
            if (this.s.loading) return this._tplLoading();
            if (this.s.error)   return this._tplError(this.s.error);
            let out = '';
            if (this.s.step > 1 && this.s.step < 5) out += this._tplHeader();
            switch (this.s.step) {
                case 1: out += this._tplStep1(); break;
                case 2: out += this._tplStep2(); break;
                case 3: out += this._tplStep3(); break;
                case 4: out += this._tplStep4(); break;
                case 5: out += this._tplStep5(); break;
            }
            return out;
        }

        _tplLoading() {
            return `<div class="mwm-loading"><span class="mwm-spinner" aria-hidden="true"></span><span class="mwm-loading__text">${this._e(t('loading', 'Loading…'))}</span></div>`;
        }

        _tplError(msg) {
            return `<div class="mwm-error"><p>${this._e(msg)}</p><button class="mwm-btn-secondary" data-a="retry">${this._e(t('tryAgain', 'Try Again'))}</button></div>`;
        }

        _tplHeader() {
            const labels = {
                2: t('stepDate', 'Choose a date'),
                3: t('stepTime', 'Choose a time'),
                4: t('stepDetails', 'Your details'),
            };
            const stepNum = this.s.step - 1;
            const pct = Math.round((stepNum / 3) * 100);
            const showBack = !(this.s.step === 2 && this.presetSlug);
            return `
<div class="mwm-wiz-header">
  ${showBack ? `<button class="mwm-wiz-back" data-a="back">${this._e(t('back', '← Back'))}</button>` : '<span></span>'}
  <div class="mwm-wiz-progress">
    <div class="mwm-wiz-progress__label">${this._e(labels[this.s.step])}</div>
    <div class="mwm-wiz-progress__bar" aria-hidden="true"><div class="mwm-wiz-progress__fill" style="width:${pct}%"></div></div>
  </div>
  ${this.s.eventType ? `<div class="mwm-wiz-et-name"><span class="mwm-dot" style="background:${this._e(this.s.eventType.color)}"></span>${this._e(this.s.eventType.name)}</div>` : '<span></span>'}
</div>`;
        }

        // Step 1 — choose event type
        _tplStep1() {
            if (!this.s.eventTypes) { this._loadEventTypes(); return this._tplLoading(); }
            if (!this.s.eventTypes.length) return `<div class="mwm-step"><p>${this._e(t('noMeetingTypes', 'No meeting types available.'))}</p></div>`;
            return `
<div class="mwm-step mwm-step--types">
  <h2 class="mwm-step__title" tabindex="-1">${this._e(t('chooseTypeTitle', 'What type of meeting?'))}</h2>
  <div class="mwm-type-list">
    ${this.s.eventTypes.map(et => `
      <button class="mwm-type-option" data-a="pick-type" data-slug="${this._e(et.slug)}">
        <span class="mwm-type-option__bar" style="background:${this._e(et.color)}"></span>
        <span class="mwm-type-option__body">
          <strong class="mwm-type-option__name">${this._e(et.name)}</strong>
          <span class="mwm-type-option__meta">${et.duration_minutes} ${this._e(t('minShort', 'min'))} &middot; ${this._e(this._formatLabel(et.meeting_type))}</span>
          ${et.description ? `<span class="mwm-type-option__desc">${this._e(et.description)}</span>` : ''}
        </span>
        <span class="mwm-type-option__arrow" aria-hidden="true">&#8250;</span>
      </button>`).join('')}
  </div>
</div>`;
        }

        // Step 2 — choose date
        _tplStep2() {
            if (!this.s.availableDates) {
                this._loadMonth();
                // Months outside the booking window resolve synchronously
                // (overlay view, no fetch) — fall through and render them;
                // only show the loading shell while a fetch is pending.
                if (!this.s.availableDates) return this._tplLoading();
            }
            return `
<div class="mwm-step mwm-step--date">
  <h2 class="mwm-step__title" tabindex="-1">${this._e(t('stepDate', 'Choose a date'))}</h2>
  ${this._tplCalendar()}
</div>`;
        }

        _months() {
            const fallback = ['January','February','March','April','May','June','July','August','September','October','November','December'];
            return Array.isArray(S.months) && S.months.length === 12 ? S.months : fallback;
        }

        _dayHeaders() {
            const fallback = ['Mo','Tu','We','Th','Fr','Sa','Su'];
            return Array.isArray(S.dayHeaders) && S.dayHeaders.length === 7 ? S.dayHeaders : fallback;
        }

        _tplCalendar() {
            const { year, month } = this.s;
            const MONTHS = this._months();
            const DAY_HDRS = this._dayHeaders();
            const meta = this._getCalendarMeta(year, month);
            const firstDow = (new Date(year, month - 1, 1).getDay() + 6) % 7; // Mon=0
            const totalDays = new Date(year, month, 0).getDate();
            const todayKey = this._dateKey(meta.today);

            let cells = '';
            const totalCells = Math.ceil((firstDow + totalDays) / 7) * 7;
            for (let i = 0; i < totalCells; i++) {
                const d = i - firstDow + 1;
                if (d < 1 || d > totalDays) { cells += '<span class="mwm-cal__day mwm-cal__day--empty" aria-hidden="true"></span>'; continue; }
                const ds   = `${year}-${String(month).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
                const past = new Date(year, month - 1, d) < meta.today;
                const avail = !past && this.s.availableDates.includes(ds);
                const sel  = this.s.date === ds;
                const isToday = ds === todayKey;
                const dayLabel = new Date(year, month - 1, d).toLocaleDateString(undefined, {weekday:'long', month:'long', day:'numeric'});
                const todayAttr = isToday ? ' aria-current="date"' : '';
                if (!avail) {
                    // Unavailable days are inert text, not controls: expose the
                    // date and state as real (visually hidden) content —
                    // aria-label/aria-disabled are ignored on generic-role spans.
                    cells += `<span class="mwm-cal__day mwm-cal__day--off${isToday ? ' mwm-cal__day--today' : ''}"${todayAttr}><span aria-hidden="true">${d}</span><span class="mwm-sr-only">${this._e(dayLabel)}, ${this._e(t('unavailable', 'unavailable'))}</span></span>`;
                } else {
                    cells += `<button class="mwm-cal__day mwm-cal__day--on${sel ? ' mwm-cal__day--sel' : ''}${isToday ? ' mwm-cal__day--today' : ''}" data-a="pick-date" data-date="${ds}" aria-pressed="${sel}"${todayAttr} aria-label="${this._e(dayLabel)}">${d}</button>`;
                }
            }

            const outOfRangeNote = meta.status
                ? `<div class="mwm-cal__overlay mwm-cal__overlay--${meta.status}">
                    <div class="mwm-cal__overlay-card">
                      <p>${this._e(meta.message)}</p>
                      <button type="button" class="mwm-btn-secondary mwm-cal__overlay-btn" data-a="jump-today">${this._e(t('backToToday', 'Back to Today'))}</button>
                    </div>
                  </div>`
                : '';

            const monthEmptyNote = !meta.status && !this.s.availableDates.length
                ? `<p class="mwm-cal__note">${this._e(fmt(t('noDatesInMonth', 'No bookable dates are available in %1$s %2$s. Try another month.'), MONTHS[month - 1], year))}</p>`
                : '';

            return `
<div class="mwm-cal">
  <div class="mwm-cal__nav">
    <button class="mwm-cal__nav-btn" data-a="prev-month" aria-label="${this._e(t('prevMonth', 'Previous month'))}">&#8249;</button>
    <div class="mwm-cal__nav-main">
      <button type="button" class="mwm-cal__title-btn" data-a="toggle-month-picker" aria-expanded="${this.s.monthPickerOpen}" aria-controls="${this.uid}-picker">${MONTHS[month-1]} ${year}</button>
      <button type="button" class="mwm-cal__today-btn" data-a="jump-today">${this._e(t('today', 'Today'))}</button>
    </div>
    <button class="mwm-cal__nav-btn" data-a="next-month" aria-label="${this._e(t('nextMonth', 'Next month'))}">&#8250;</button>
  </div>
  ${this.s.monthPickerOpen ? this._tplMonthPicker(MONTHS) : ''}
  <div class="mwm-cal__frame${meta.status ? ` mwm-cal__frame--${meta.status}` : ''}">
    <div class="mwm-cal__grid" role="group" aria-label="${this._e(MONTHS[month-1] + ' ' + year)}">
      ${DAY_HDRS.map(h => `<span class="mwm-cal__hdr" aria-hidden="true">${this._e(h)}</span>`).join('')}
      ${cells}
    </div>
    ${outOfRangeNote}
  </div>
  ${monthEmptyNote}
</div>`;
        }

        _tplMonthPicker(months) {
            const years = this._getPickerYears();
            const pickerYear = this.s.pickerYear || this.s.year;
            const current = this._getCalendarMeta();

            return `
<div class="mwm-cal__picker" id="${this.uid}-picker" aria-label="${this._e(t('chooseMonth', 'Choose a month'))}">
  <div class="mwm-cal__picker-head">
    <label class="mwm-cal__picker-label" for="${this.uid}-picker-year">${this._e(t('yearLabel', 'Year'))}</label>
    <select id="${this.uid}-picker-year" class="mwm-cal__picker-select" data-a="picker-year">
      ${years.map(year => `<option value="${year}" ${year === pickerYear ? 'selected' : ''}>${year}</option>`).join('')}
    </select>
  </div>
  <div class="mwm-cal__picker-grid">
    ${months.map((label, index) => {
        const month = index + 1;
        const isActive = pickerYear === this.s.year && month === this.s.month;
        const isTodayMonth = pickerYear === current.today.getFullYear() && month === current.today.getMonth() + 1;
        const canPick = this._isMonthWithinBookingWindow(pickerYear, month);
        const disabled = !canPick && !isActive;

        return `<button type="button" class="mwm-cal__picker-month${isActive ? ' mwm-cal__picker-month--active' : ''}${isTodayMonth ? ' mwm-cal__picker-month--today' : ''}" data-a="pick-month" data-year="${pickerYear}" data-month="${month}" ${disabled ? 'disabled' : ''}>${this._e(label.slice(0, 3))}</button>`;
    }).join('')}
  </div>
  <p class="mwm-cal__picker-note">${this._e(t('pickerNote', 'Choose a month in the active booking window, or use the arrows to preview months outside it.'))}</p>
</div>`;
        }

        // Step 3 — choose time
        _tplStep3() {
            if (!this.s.slots) { this._loadSlots(); return this._tplLoading(); }
            const dl = new Date(this.s.date + 'T12:00:00').toLocaleDateString(undefined, {weekday:'long', month:'long', day:'numeric'});
            if (!this.s.slots.length) {
                return `<div class="mwm-step mwm-step--time"><h2 class="mwm-step__title" tabindex="-1">${this._e(dl)}</h2><p class="mwm-step__desc">${this._e(t('noTimes', 'No times available on this day. Please go back and pick another date.'))}</p></div>`;
            }
            return `
<div class="mwm-step mwm-step--time">
  <h2 class="mwm-step__title" tabindex="-1">${this._e(dl)}</h2>
  <ul class="mwm-slots" aria-label="${this._e(t('availableTimes', 'Available Times'))}">
    ${this.s.slots.map(slot => {
        const sel = this.s.slot?.start_utc === slot.start_utc;
        // Escape the JSON payload so slot data can never break the attribute;
        // the parser decodes entities before dataset reads, so
        // JSON.parse(btn.dataset.slot) still receives the original string.
        return `<li><button class="mwm-slot${sel ? ' mwm-slot--sel' : ''}" aria-pressed="${sel}" data-a="pick-slot" data-slot='${this._e(JSON.stringify(slot))}'>${this._e(slot.start_local)}</button></li>`;
    }).join('')}
  </ul>
</div>`;
        }

        // Step 4 — booker details
        _tplStep4() {
            const et = this.s.eventType;
            const b  = this.s.booker;
            const bothMt = et.meeting_type === 'both';
            return `
<div class="mwm-step mwm-step--details">
  <h2 class="mwm-step__title" tabindex="-1">${this._e(t('stepDetails', 'Your details'))}</h2>
  <form class="mwm-form" data-a="submit" novalidate>
    <div class="mwm-hp" aria-hidden="true"><input type="text" name="mwm_hp" tabindex="-1" autocomplete="off"></div>

    <div class="mwm-form__group">
      <label class="mwm-form__label mwm-form__label--req" for="${this.uid}-name">${this._e(t('name', 'Name'))}</label>
      <input type="text" id="${this.uid}-name" name="booker_name" class="mwm-form__input" required autocomplete="name" placeholder="${this._e(t('namePlaceholder', 'Your full name'))}" value="${this._e(b.booker_name||'')}">
    </div>
    <div class="mwm-form__group">
      <label class="mwm-form__label mwm-form__label--req" for="${this.uid}-email">${this._e(t('email', 'Email'))}</label>
      <input type="email" id="${this.uid}-email" name="booker_email" class="mwm-form__input" required autocomplete="email" placeholder="${this._e(t('emailPlaceholder', 'you@example.com'))}" value="${this._e(b.booker_email||'')}">
    </div>
    <div class="mwm-form__group">
      <label class="mwm-form__label" for="${this.uid}-phone">${this._e(t('phone', 'Phone'))} <span class="mwm-optional">${this._e(t('optional', '(optional)'))}</span></label>
      <input type="tel" id="${this.uid}-phone" name="booker_phone" class="mwm-form__input" autocomplete="tel" value="${this._e(b.booker_phone||'')}">
    </div>

    ${bothMt ? `
    <div class="mwm-form__group">
      <label class="mwm-form__label mwm-form__label--req">${this._e(t('howToMeet', 'How would you like to meet?'))}</label>
      <div class="mwm-mt-opts" role="radiogroup" aria-label="${this._e(t('howToMeet', 'How would you like to meet?'))}">
        <label class="mwm-mt-opt${this.s.meetingType==='online'?' mwm-mt-opt--sel':''}"><input type="radio" name="meeting_type" value="online" ${this.s.meetingType==='online'?'checked':''}> ${this._e(t('online', 'Online'))}</label>
        <label class="mwm-mt-opt${this.s.meetingType==='in_person'?' mwm-mt-opt--sel':''}"><input type="radio" name="meeting_type" value="in_person" ${this.s.meetingType==='in_person'?'checked':''}> ${this._e(t('inPerson', 'In person'))}</label>
      </div>
    </div>` : ''}

    ${(et.fields||[]).map(f => this._tplField(f)).join('')}

    <div class="mwm-form__group">
      <label class="mwm-form__label" for="${this.uid}-notes">${this._e(t('anythingElse', 'Anything else?'))} <span class="mwm-optional">${this._e(t('optional', '(optional)'))}</span></label>
      <textarea id="${this.uid}-notes" name="booker_notes" class="mwm-form__textarea" placeholder="${this._e(t('notesPlaceholder', 'Notes or context for our meeting…'))}">${this._e(b.booker_notes||'')}</textarea>
    </div>

    <div class="mwm-form__error" id="${this.uid}-form-error" role="alert" tabindex="-1" hidden></div>
    <div class="mwm-form__actions">
      <button type="submit" class="mwm-btn-primary">${this._e(t('confirmBooking', 'Confirm Booking'))}</button>
    </div>
  </form>
</div>`;
        }

        _tplField(f) {
            const fid  = `${this.uid}-f-${f.id}`;
            const req  = f.required;
            const lbl  = `<label class="mwm-form__label${req?' mwm-form__label--req':''}" for="${fid}">${this._e(f.label)}</label>`;
            const sv   = this.s.fieldAnswers[f.id] || '';
            let inp = '';
            switch (f.type) {
                case 'textarea':
                    inp = `<textarea id="${fid}" name="ff_${f.id}" class="mwm-form__textarea" placeholder="${this._e(f.placeholder)}" ${req?'required':''}>${this._e(sv)}</textarea>`;
                    break;
                case 'select':
                    inp = `<select id="${fid}" name="ff_${f.id}" class="mwm-form__select" ${req?'required':''}><option value="">${this._e(t('choosePlaceholder', '— Choose —'))}</option>${f.options.map(o=>`<option value="${this._e(o)}"${sv===o?' selected':''}>${this._e(o)}</option>`).join('')}</select>`;
                    break;
                case 'radio':
                    inp = f.options.map(o=>`<label class="mwm-radio-label"><input type="radio" name="ff_${f.id}" value="${this._e(o)}"${sv===o?' checked':''} ${req?'required':''}> ${this._e(o)}</label>`).join('');
                    break;
                case 'checkbox':
                    const sva = Array.isArray(sv)?sv:[];
                    inp = f.options.map(o=>`<label class="mwm-radio-label"><input type="checkbox" name="ff_${f.id}[]" value="${this._e(o)}"${sva.includes(o)?' checked':''}> ${this._e(o)}</label>`).join('');
                    break;
                default: // text
                    inp = `<input type="text" id="${fid}" name="ff_${f.id}" class="mwm-form__input" placeholder="${this._e(f.placeholder)}" ${req?'required':''} value="${this._e(sv)}">`;
            }
            return `<div class="mwm-form__group">${lbl}${inp}</div>`;
        }

        // Step 5 — confirmation
        _tplStep5() {
            const c = this.s.confirmation;
            return `
<div class="mwm-step mwm-step--confirm mwm-confirm">
  <div class="mwm-confirm__icon" aria-hidden="true">&#10003;</div>
  <h2 class="mwm-confirm__title" tabindex="-1">${this._e(t('booked', 'You’re booked!'))}</h2>
  <p class="mwm-confirm__sub">${fmt(this._e(t('confirmationSent', 'A confirmation email is heading to %s.')), `<strong>${this._e(c.booker_email)}</strong>`)}</p>
  <div class="mwm-confirm__details">
    <div class="mwm-confirm__row"><span aria-hidden="true">&#128197;</span><span>${this._e(c.event_type_name)}</span></div>
    <div class="mwm-confirm__row"><span aria-hidden="true">&#128336;</span><span>${this._e(c.start_local)}</span></div>
    ${c.meeting_type_label ? `<div class="mwm-confirm__row"><span aria-hidden="true">&#128205;</span><span>${this._e(c.meeting_type_label)}</span></div>` : ''}
    ${c.meeting_join_url ? `<div class="mwm-confirm__row"><span aria-hidden="true">&#128279;</span><span><a href="${this._e(c.meeting_join_url)}" target="_blank" rel="noopener noreferrer">${this._e(c.meeting_provider || t('openMeetingLink', 'Open meeting link'))}</a></span></div>` : ''}
  </div>
  <div class="mwm-confirm__manage">
    <p>${this._e(t('needManage', 'Need to cancel or reschedule?'))}</p>
    <a href="${this._e(c.manage_url)}" class="mwm-btn-secondary">${this._e(t('manageBooking', 'Manage Booking'))}</a>
  </div>
</div>`;
        }

        // -- Events -----------------------------------------------------------

        _bind() {
            if (this._isBound) return;
            this._isBound = true;
            this.el.addEventListener('click',  e => this._onClick(e),  { passive: true });
            this.el.addEventListener('change', e => this._onChange(e), { passive: true });
            this.el.addEventListener('submit', e => this._onSubmit(e));
            this._onDocClick = e => {
                if (!this.s.monthPickerOpen) return;
                if (!this.container.contains(e.target)) this._closeMonthPicker(false);
            };
            this._onDocKeydown = e => {
                if (e.key === 'Escape' && this.s.monthPickerOpen) this._closeMonthPicker(true);
            };
            document.addEventListener('click', this._onDocClick);
            document.addEventListener('keydown', this._onDocKeydown);
        }

        /**
         * Tear down document-level listeners (called when a modal-hosted
         * wizard is closed so re-opened modals don't accumulate listeners or
         * keep a stale open month picker).
         */
        destroy() {
            if (this._onDocClick) document.removeEventListener('click', this._onDocClick);
            if (this._onDocKeydown) document.removeEventListener('keydown', this._onDocKeydown);
            this._onDocClick = null;
            this._onDocKeydown = null;
            this.s.monthPickerOpen = false;
            this._pickerFocusPending = false;
            this._pickerReturnFocus = false;
            this._pickerYearFocusPending = false;
        }

        _onClick(e) {
            const btn = e.target.closest('[data-a]');
            if (!btn) return;
            switch (btn.dataset.a) {
                case 'back':       this._goBack(); break;
                case 'pick-type':  this._pickType(btn.dataset.slug); break;
                case 'prev-month': this._shiftMonth(-1); break;
                case 'next-month': this._shiftMonth(1); break;
                case 'toggle-month-picker': this._toggleMonthPicker(); break;
                case 'pick-month': this._pickMonth(Number(btn.dataset.year), Number(btn.dataset.month)); break;
                case 'jump-today': this._jumpToToday(); break;
                case 'pick-date':  this._pickDate(btn.dataset.date); break;
                case 'pick-slot':  this._pickSlot(JSON.parse(btn.dataset.slot)); break;
                case 'retry':
                    // After the retry re-loads, land focus on the step heading so
                    // keyboard users don't restart from the top of the dialog/page.
                    this._refocus = '.mwm-step__title';
                    this._set({ error: null });
                    break;
            }
        }

        _onChange(e) {
            if (e.target.name === 'meeting_type') {
                this.el.querySelectorAll('.mwm-mt-opt').forEach(el => {
                    el.classList.toggle('mwm-mt-opt--sel', el.querySelector('input').value === e.target.value);
                });
            } else if (e.target.matches('[data-a="picker-year"]')) {
                this._pickerYearFocusPending = true;
                this._set({ pickerYear: Number(e.target.value) || this.s.year });
            }
        }

        async _onSubmit(e) {
            e.preventDefault();
            if (!e.target.matches('[data-a="submit"]')) return;
            const form = e.target;

            if (form.querySelector('[name="mwm_hp"]').value) return;

            const name  = form.querySelector('[name="booker_name"]').value.trim();
            const email = form.querySelector('[name="booker_email"]').value.trim();
            if (!name || !email || !/\S+@\S+\.\S+/.test(email)) {
                return this._formErr(form, t('invalidNameEmail', 'Please enter a valid name and email address.'), form.querySelector('[name="booker_name"]'));
            }

            const et = this.s.eventType;
            let mt = et.meeting_type !== 'both' ? et.meeting_type : null;
            if (et.meeting_type === 'both') {
                const r = form.querySelector('[name="meeting_type"]:checked');
                if (!r) return this._formErr(form, t('chooseMeetType', 'Please choose how you would like to meet.'), form.querySelector('.mwm-mt-opts'));
                mt = r.value;
            }

            const fa = {};
            for (const f of (et.fields || [])) {
                if (f.type === 'checkbox') {
                    const vals = [...form.querySelectorAll(`[name="ff_${f.id}[]"]:checked`)].map(el => el.value);
                    if (f.required && !vals.length) return this._formErr(form, fmt(t('pleaseAnswer', 'Please answer: %s'), f.label), form.querySelector(`[name="ff_${f.id}[]"]`));
                    fa[f.id] = vals;
                } else if (f.type === 'radio') {
                    const r = form.querySelector(`[name="ff_${f.id}"]:checked`);
                    if (f.required && !r) return this._formErr(form, fmt(t('pleaseAnswer', 'Please answer: %s'), f.label), form.querySelector(`[name="ff_${f.id}"]`));
                    fa[f.id] = r ? r.value : '';
                } else {
                    const inp = form.querySelector(`[name="ff_${f.id}"]`);
                    const v = inp ? inp.value.trim() : '';
                    if (f.required && !v) return this._formErr(form, fmt(t('pleaseAnswer', 'Please answer: %s'), f.label), inp);
                    fa[f.id] = v;
                }
            }

            this.s.booker = { booker_name: name, booker_email: email, booker_phone: form.querySelector('[name="booker_phone"]').value.trim(), booker_notes: form.querySelector('[name="booker_notes"]').value.trim() };
            this.s.meetingType  = mt;
            this.s.fieldAnswers = fa;

            const btn = form.querySelector('[type="submit"]');
            btn.disabled = true; btn.textContent = t('confirming', 'Confirming…');

            try {
                const res = await api('/bookings', {
                    method: 'POST',
                    body: JSON.stringify({
                        event_type:   et.slug,
                        start_utc:    this.s.slot.start_utc,
                        end_utc:      this.s.slot.end_utc,
                        timezone:     this.tz,
                        meeting_type: mt,
                        booker_name:  name,
                        booker_email: email,
                        booker_phone: this.s.booker.booker_phone,
                        booker_notes: this.s.booker.booker_notes,
                        field_answers: fa,
                        mwm_hp: '',
                    }),
                });
                this.s.confirmation = res.booking;
                this._set({ step: 5 });
                this._scrollTo(this.container, { block: 'start' });
            } catch (err) {
                btn.disabled = false; btn.textContent = t('confirmBooking', 'Confirm Booking');
                this._formErr(form, err.message || t('genericError', 'Something went wrong. Please try again.'), null);
            }
        }

        // -- Async loaders ----------------------------------------------------

        async _loadEventTypes() {
            const requestId = ++this._eventTypesRequestId;
            this._set({ loading: true, error: null });
            try {
                const types = await api('/event-types');
                if (requestId !== this._eventTypesRequestId) return;
                this._set({ eventTypes: types, loading: false });
            } catch (e) {
                if (requestId !== this._eventTypesRequestId) return;
                this._announceError(e.message);
                this._set({ error: e.message, loading: false });
            }
        }

        async _loadMonth() {
            const requestId = ++this._monthRequestId;
            const meta = this._getCalendarMeta();
            if (meta.status) {
                // Month outside the booking window: no fetch — fill state and
                // let the render pass that called us paint the overlay. Using
                // _set() here would trigger a nested render that the outer
                // render then overwrites with a loading shell.
                Object.assign(this.s, { availableDates: [], loading: false, error: null });
                this._announce(meta.message);
                return;
            }
            this._announce(t('loadingDates', 'Loading available dates…'));
            this._set({ loading: true, error: null, availableDates: null });
            try {
                const d = await api(`/availability/month?event_type=${this.s.eventType.slug}&year=${this.s.year}&month=${this.s.month}&tz=${encodeURIComponent(this.tz)}`);
                if (requestId !== this._monthRequestId) return;
                this._set({ availableDates: d.available_dates, loading: false });
                if (d.available_dates.length) {
                    this._announce(fmt(t('datesAvailable', '%s dates available.'), d.available_dates.length));
                } else {
                    this._announce(fmt(t('noDatesInMonth', 'No bookable dates are available in %1$s %2$s. Try another month.'), this._months()[this.s.month - 1], this.s.year));
                }
            } catch (e) {
                if (requestId !== this._monthRequestId) return;
                this._announceError(e.message);
                this._set({ error: e.message, loading: false });
            }
        }

        async _loadSlots() {
            const requestId = ++this._slotsRequestId;
            this._announce(t('loadingTimes', 'Loading available times…'));
            this._set({ loading: true, error: null, slots: null });
            try {
                const d = await api(`/availability/slots?event_type=${this.s.eventType.slug}&date=${this.s.date}&tz=${encodeURIComponent(this.tz)}`);
                if (requestId !== this._slotsRequestId) return;
                this._set({ slots: d.slots, loading: false });
                this._announce(d.slots.length
                    ? fmt(t('timesAvailable', '%s times available.'), d.slots.length)
                    : t('noTimes', 'No times available on this day. Please go back and pick another date.'));
            } catch (e) {
                if (requestId !== this._slotsRequestId) return;
                this._announceError(e.message);
                this._set({ error: e.message, loading: false });
            }
        }

        // -- Navigation -------------------------------------------------------

        _pickType(slug) {
            const et = (this.s.eventTypes || []).find(t => t.slug === slug);
            if (et) this._set({ eventType: et, step: 2, availableDates: null, date: null, slot: null, monthPickerOpen: false, pickerYear: this.s.year });
        }

        _pickDate(date)  { this._set({ date, step: 3, slots: null, slot: null }); }
        _pickSlot(slot) {
            if (this.options && this.options.rescheduleMode && this.options.onSlotPicked) {
                this._set({ slot });
                this.options.onSlotPicked(slot);
                return;
            }
            this._set({ slot, step: 4 });
        }
        _shiftMonth(dir) {
            let { year, month } = this.s;
            month += dir;
            if (month > 12) { month = 1; year++; }
            if (month < 1)  { month = 12; year--; }
            // Restore focus to the same nav button after the re-render
            // (including the out-of-window overlay view).
            this._refocus = dir < 0 ? '[data-a="prev-month"]' : '[data-a="next-month"]';
            this._set({ year, month, pickerYear: year, monthPickerOpen: false, availableDates: null });
        }

        _toggleMonthPicker() {
            const opening = !this.s.monthPickerOpen;
            this._pickerFocusPending = opening;
            this._set({
                monthPickerOpen: opening,
                pickerYear: opening ? this.s.year : this.s.pickerYear,
            });
        }

        _closeMonthPicker(returnFocus) {
            this._pickerReturnFocus = !!returnFocus;
            this._set({ monthPickerOpen: false });
        }

        _pickMonth(year, month) {
            if (!year || !month) return;
            this._pickerReturnFocus = true;
            this._set({ year, month, pickerYear: year, monthPickerOpen: false, availableDates: null });
        }

        _jumpToToday() {
            const today = this._today();
            this._refocus = '[data-a="jump-today"]';
            this._set({
                year: today.getFullYear(),
                month: today.getMonth() + 1,
                pickerYear: today.getFullYear(),
                monthPickerOpen: false,
                availableDates: null,
            });
        }

        _goBack() {
            const { step } = this.s;
            if      (step === 2) this._set({ step: 1, eventType: this.presetSlug ? this.s.eventType : null, availableDates: null, date: null, slot: null, monthPickerOpen: false });
            else if (step === 3) this._set({ step: 2, slots: null, slot: null });
            else if (step === 4) this._set({ step: 3 });
        }

        // -- Helpers ----------------------------------------------------------

        _set(patch) { Object.assign(this.s, patch); this._render(); }

        _today() {
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            return today;
        }

        _dateKey(date) {
            return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
        }

        _maxBookableDate() {
            const max = this._today();
            max.setDate(max.getDate() + this.maxAdvanceDays);
            return max;
        }

        _getCalendarMeta(year = this.s.year, month = this.s.month) {
            const today = this._today();
            const currentMonthStart = new Date(today.getFullYear(), today.getMonth(), 1);
            currentMonthStart.setHours(0, 0, 0, 0);

            const maxDate = this._maxBookableDate();
            const maxMonthStart = new Date(maxDate.getFullYear(), maxDate.getMonth(), 1);
            maxMonthStart.setHours(0, 0, 0, 0);

            const monthStart = new Date(year, month - 1, 1);
            monthStart.setHours(0, 0, 0, 0);

            let status = '';
            let message = '';

            if (monthStart < currentMonthStart) {
                status = 'past';
                message = fmt(t('pastMonth', 'You’re viewing a past month. New meetings can only be booked from %s onward.'), this._fmtLongDate(today));
            } else if (monthStart > maxMonthStart) {
                status = 'future';
                message = fmt(t('futureMonth', 'You’re viewing beyond the current booking window. New meetings can be booked through %s.'), this._fmtLongDate(maxDate));
            }

            return { today, maxDate, currentMonthStart, maxMonthStart, monthStart, status, message };
        }

        _isMonthWithinBookingWindow(year, month) {
            const meta = this._getCalendarMeta(year, month);
            return !meta.status;
        }

        _getPickerYears() {
            const todayYear = this._today().getFullYear();
            const maxYear = this._maxBookableDate().getFullYear();
            const minYear = Math.min(todayYear, this.s.year, this.s.pickerYear || this.s.year);
            const upperYear = Math.max(maxYear, this.s.year, this.s.pickerYear || this.s.year);
            const years = [];

            for (let year = minYear; year <= upperYear; year++) {
                years.push(year);
            }

            return years;
        }

        _fmtLongDate(date) {
            return date.toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' });
        }

        _formErr(form, msg, fieldEl) {
            // Clear error wiring from the previous attempt so aria-invalid /
            // aria-describedby always reflect the current error set.
            form.querySelectorAll('[aria-invalid="true"]').forEach(el => {
                el.removeAttribute('aria-invalid');
                el.removeAttribute('aria-describedby');
            });

            const el = form.querySelector('.mwm-form__error');
            if (el) {
                el.hidden = false;
                el.textContent = msg;
            }
            if (fieldEl) {
                fieldEl.setAttribute('aria-invalid', 'true');
                if (el && el.id) fieldEl.setAttribute('aria-describedby', el.id);
                try { fieldEl.focus(); } catch (e) { /* noop */ }
            } else if (el) {
                // Server error with no specific field — focus the alert region
                // (tabindex="-1") so keyboard focus isn't dropped to <body>.
                try { el.focus(); } catch (e) { /* noop */ }
            }
            this._scrollTo(el || form, { block: 'nearest' });
        }

        _formatLabel(type) {
            const map = {
                online:    t('online', 'Online'),
                in_person: t('inPerson', 'In person'),
                both:      t('onlineOrInPerson', 'Online or in person'),
            };
            return map[type] || type;
        }

        _e(s) {
            if (s == null) return '';
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
        }
    }

    // -------------------------------------------------------------------------
    // Manage Booking page
    // -------------------------------------------------------------------------
    class MWMManage {
        constructor(container) {
            this.container = container;
            this.data = JSON.parse(container.dataset.booking || '{}');
            this.tz = (() => { try { return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'; } catch(e) { return 'UTC'; } })();
            this.mode = 'view'; // view | cancel-confirm | rescheduling | cancelled | rescheduled | error
            this.newBooking = null;
            this.reschedWizard = null;
            this._actionsWrap = container.querySelector('#mwm-manage-actions');
            this._reschedWrap = container.querySelector('.mwm-manage__reschedule-wrap');

            // Persistent live region for state announcements.
            this._live = document.createElement('div');
            this._live.className = 'mwm-sr-only';
            this._live.setAttribute('aria-live', 'polite');
            container.appendChild(this._live);

            // Only wire up JS if booking is confirmed and manageable (not already handled server-side)
            if ( this.data.status === 'confirmed' && !this.data.too_close ) {
                this._bindRoot();
            }
        }

        _announce(msg) {
            if (this._live) this._live.textContent = msg;
        }

        _focusEl(el) {
            if (el) { try { el.focus(); } catch (e) { /* noop */ } }
        }

        _bindRoot() {
            this.container.addEventListener('click', e => {
                const btn = e.target.closest('[data-a]');
                if (!btn) return;
                switch (btn.dataset.a) {
                    case 'start-cancel':    this._showCancelConfirm(); break;
                    case 'confirm-cancel':  this._doCancel(); break;
                    case 'abort-cancel':    this._showView(); break;
                    case 'start-reschedule': this._showReschedule(); break;
                    case 'abort-reschedule': this._showView(); break;
                    case 'confirm-reschedule': this._doReschedule(); break;
                }
            });
        }

        _showView() {
            this.mode = 'view';
            this._actionsWrap.innerHTML = `
<div class="mwm-manage__btns">
  <button class="mwm-btn-danger" data-a="start-cancel">${this._e(t('cancelThis', 'Cancel this booking'))}</button>
  ${this.data.can_reschedule ? `<button class="mwm-btn-secondary" data-a="start-reschedule">${this._e(t('reschedule', 'Reschedule'))}</button>` : ''}
</div>`;
            this._reschedWrap.hidden = true;
            this._reschedWrap.innerHTML = '';
            this._focusEl(this._actionsWrap.querySelector('button'));
        }

        _showCancelConfirm() {
            this.mode = 'cancel-confirm';
            this._actionsWrap.innerHTML = `
<div class="mwm-manage__confirm">
  <p class="mwm-manage__confirm-msg" tabindex="-1">${this._e(t('cancelConfirm', 'Cancel this booking? This cannot be undone.'))}</p>
  <div class="mwm-manage__btns">
    <button class="mwm-btn-danger" data-a="confirm-cancel">${this._e(t('yesCancel', 'Yes, Cancel It'))}</button>
    <button class="mwm-btn-secondary" data-a="abort-cancel">${this._e(t('goBack', 'Go Back'))}</button>
  </div>
</div>`;
            this._focusEl(this._actionsWrap.querySelector('.mwm-manage__confirm-msg'));
        }

        async _doCancel() {
            const btn = this._actionsWrap.querySelector('[data-a="confirm-cancel"]');
            if (btn) { btn.disabled = true; btn.textContent = t('cancelling', 'Cancelling…'); }
            try {
                await api(`/bookings/${this.data.id}/cancel`, {
                    method: 'POST',
                    body: JSON.stringify({ cancel_token: this.data.cancel_token }),
                });
                this.mode = 'cancelled';
                this._actionsWrap.innerHTML = `
<div class="mwm-manage__success">
  <div class="mwm-manage__success-icon" aria-hidden="true">&#10003;</div>
  <h3 tabindex="-1">${this._e(t('bookingCancelled', 'Booking cancelled'))}</h3>
  <p>${fmt(this._e(t('cancelledBody', 'Your %1$s on %2$s has been cancelled.')), `<strong>${this._e(this.data.event_type_name)}</strong>`, this._e(this.data.start_local))}</p>
  <a href="${this._e((window.mwmData || {}).siteUrl || '/')}" class="mwm-btn-secondary">${this._e(t('bookNewTime', 'Book a New Time'))}</a>
</div>`;
                this._announce(t('bookingCancelled', 'Booking cancelled'));
                this._focusEl(this._actionsWrap.querySelector('h3'));
            } catch(err) {
                if (btn) { btn.disabled = false; btn.textContent = t('yesCancel', 'Yes, Cancel It'); }
                this._actionsWrap.insertAdjacentHTML('beforeend', `<p class="mwm-manage__error" role="alert">${this._e(err.message || t('genericError', 'Something went wrong. Please try again.'))}</p>`);
            }
        }

        _showReschedule() {
            this.mode = 'rescheduling';
            this._actionsWrap.innerHTML = `<button class="mwm-btn-secondary" style="margin-bottom:16px" data-a="abort-reschedule">${this._e(t('backToBooking', '← Back to booking'))}</button>`;
            this._reschedWrap.hidden = false;
            this._reschedWrap.innerHTML = `
<h3 class="mwm-manage__resched-title">${this._e(t('chooseNewTime', 'Choose a new time'))}</h3>
<div class="mwm-booking-wizard" data-event-type="${this._e(this.data.event_type_slug)}">
  <div class="mwm-booking-wizard__inner"></div>
</div>
<div class="mwm-manage__slot-confirm" hidden>
  <p class="mwm-manage__slot-selected"></p>
  <button class="mwm-btn-primary" data-a="confirm-reschedule">${this._e(t('confirmNewTime', 'Confirm New Time'))}</button>
</div>`;
            this._focusEl(this._actionsWrap.querySelector('[data-a="abort-reschedule"]'));

            // Instantiate a sub-wizard for date/slot picking only (steps 2+3)
            const wizEl = this._reschedWrap.querySelector('.mwm-booking-wizard');
            this.reschedWizard = new MWMWizard(wizEl, { rescheduleMode: true, onSlotPicked: (slot) => this._onSlotPicked(slot) });
        }

        _onSlotPicked(slot) {
            this._pendingSlot = slot;
            const confirm = this._reschedWrap.querySelector('.mwm-manage__slot-confirm');
            const label   = this._reschedWrap.querySelector('.mwm-manage__slot-selected');
            if (label) label.textContent = fmt(t('newTime', 'New time: %s'), slot.start_local);
            if (confirm) confirm.hidden = false;
        }

        async _doReschedule() {
            if (!this._pendingSlot) return;
            const btn = this._reschedWrap.querySelector('[data-a="confirm-reschedule"]');
            if (btn) { btn.disabled = true; btn.textContent = t('rescheduling', 'Rescheduling…'); }
            try {
                const res = await api(`/bookings/${this.data.id}/reschedule`, {
                    method: 'POST',
                    body: JSON.stringify({
                        reschedule_token: this.data.reschedule_token,
                        start_utc:        this._pendingSlot.start_utc,
                        end_utc:          this._pendingSlot.end_utc,
                        timezone:         this.tz,
                    }),
                });
                this.newBooking = res.booking;
                this.mode = 'rescheduled';
                this._reschedWrap.hidden = true;
                this._actionsWrap.innerHTML = `
<div class="mwm-manage__success">
  <div class="mwm-manage__success-icon" aria-hidden="true">&#10003;</div>
  <h3 tabindex="-1">${this._e(t('bookingRescheduled', 'Booking rescheduled!'))}</h3>
  <p>${fmt(this._e(t('rescheduledBody', 'Your %1$s is now scheduled for %2$s.')), `<strong>${this._e(res.booking.event_type_name)}</strong>`, `<strong>${this._e(res.booking.start_local)}</strong>`)}</p>
  <p>${this._e(t('confirmationOnWay', 'A confirmation email is on its way.'))}</p>
</div>`;
                this._announce(t('bookingRescheduled', 'Booking rescheduled!'));
                this._focusEl(this._actionsWrap.querySelector('h3'));
            } catch(err) {
                if (btn) { btn.disabled = false; btn.textContent = t('confirmNewTime', 'Confirm New Time'); }
                this._reschedWrap.insertAdjacentHTML('beforeend', `<p class="mwm-manage__error" role="alert">${this._e(err.message || t('genericError', 'Something went wrong. Please try again.'))}</p>`);
            }
        }

        _e(s) {
            if (s == null) return '';
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
        }
    }

    // -------------------------------------------------------------------------
    // Modal (focus management + background inert + focus trap)
    // -------------------------------------------------------------------------
    function initModal() {
        const modal = document.getElementById('mwm-modal');
        if (!modal) return;
        const wiz = modal.querySelector('.mwm-booking-wizard');
        const closeBtn = modal.querySelector('.mwm-modal__close');

        let modalOpen = false;
        let lastFocus = null;
        let inerted = [];
        let activeWizard = null;

        function focusable() {
            return Array.from(modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]'))
                .filter(el => el.tabIndex > -1 && !el.disabled);
        }

        function setBackgroundInert(on) {
            if (on) {
                inerted = [];
                Array.from(document.body.children).forEach(node => {
                    if (node === modal) return;
                    if ('inert' in node) {
                        if (!node.inert) { node.inert = true; inerted.push(node); }
                    } else if (!node.hasAttribute('aria-hidden')) {
                        node.setAttribute('aria-hidden', 'true');
                        inerted.push(node);
                    }
                });
            } else {
                inerted.forEach(node => {
                    if ('inert' in node) node.inert = false;
                    else node.removeAttribute('aria-hidden');
                });
                inerted = [];
            }
        }

        function onKeydown(e) {
            if (!modalOpen) return;
            if (e.key === 'Escape') {
                // Let the wizard close its month picker first (it handles its own Escape).
                if (modal.querySelector('.mwm-cal__picker')) return;
                close();
                return;
            }
            if (e.key !== 'Tab') return;

            const list = focusable();
            if (!list.length) return;
            const first = list[0];
            const last  = list[list.length - 1];

            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault(); last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault(); first.focus();
            } else if (!modal.contains(document.activeElement)) {
                e.preventDefault(); first.focus();
            }
        }

        function open(slug) {
            lastFocus = document.activeElement;
            wiz.dataset.eventType = slug;
            wiz.innerHTML = '<div class="mwm-booking-wizard__inner"></div>';
            modal.hidden = false;
            modalOpen = true;
            document.body.classList.add('mwm-no-scroll');
            setBackgroundInert(true);
            activeWizard = new MWMWizard(wiz);
            if (closeBtn) { try { closeBtn.focus(); } catch (e) { /* noop */ } }
        }

        function close() {
            if (activeWizard && typeof activeWizard.destroy === 'function') {
                activeWizard.destroy();
                activeWizard = null;
            }
            modal.hidden = true;
            modalOpen = false;
            document.body.classList.remove('mwm-no-scroll');
            setBackgroundInert(false);
            if (lastFocus && typeof lastFocus.focus === 'function') {
                try { lastFocus.focus(); } catch (e) { /* noop */ }
            }
            lastFocus = null;
        }

        document.addEventListener('click', e => {
            const btn = e.target.closest('.mwm-booking-button');
            if (btn) { e.preventDefault(); open(btn.dataset.eventType || ''); }
        });
        modal.querySelector('.mwm-modal__overlay')?.addEventListener('click', close);
        closeBtn?.addEventListener('click', close);
        document.addEventListener('keydown', onKeydown);
    }

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------
    function boot() {
        document.querySelectorAll('.mwm-booking-wizard:not(.mwm-booking-wizard--modal)').forEach(el => new MWMWizard(el));
        initModal();
        document.querySelectorAll('.mwm-manage').forEach(el => new MWMManage(el));
    }

    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', boot) : boot();
}());
