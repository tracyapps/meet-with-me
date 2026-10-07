/* Meet With Me — Admin JS */
/* jshint esversion: 11 */

(function ($) {
    'use strict';

    // -------------------------------------------------------------------------
    // Translations + announcer
    // -------------------------------------------------------------------------
    const ADMIN = window.mwmAdmin || {};
    const STR   = ADMIN.strings || {};

    function fmt(str, ...args) {
        let auto = 0;
        return String(str).replace(/%(\d+)\$s|%s/g, (m, n) => {
            const idx = n ? (parseInt(n, 10) - 1) : (auto++);
            return args[idx] != null ? String(args[idx]) : '';
        });
    }

    function t(key, fallback, ...args) {
        const str = (STR[key] != null && STR[key] !== '') ? STR[key] : (fallback != null ? fallback : key);
        return args.length ? fmt(str, ...args) : str;
    }

    // Polite live region for screen-reader announcements.
    const $live = $('<div id="mwm-admin-live" class="screen-reader-text" aria-live="polite"></div>').appendTo(document.body);

    function announce(msg) {
        $live.text(msg);
    }

    // -------------------------------------------------------------------------
    // Delete confirmation
    // -------------------------------------------------------------------------
    $(document).on('click', '.mwm-delete-btn', function (e) {
        const msg = $(this).data('confirm') || t('areYouSure', 'Are you sure?');
        if (!window.confirm(msg)) {
            e.preventDefault();
        }
    });

    // -------------------------------------------------------------------------
    // Slug auto-generation from name
    // -------------------------------------------------------------------------
    const $nameInput = $('#mwm-name');
    const $slugInput = $('#mwm-slug');
    let slugManuallyEdited = $slugInput.val() !== '';

    $nameInput.on('input', function () {
        if (slugManuallyEdited) return;
        $slugInput.val(mwmSlugify($(this).val()));
    });

    $slugInput.on('input', function () {
        slugManuallyEdited = $(this).val() !== '';
    });

    function mwmSlugify(str) {
        return str
            .toLowerCase()
            .replace(/[^\w\s-]/g, '')
            .replace(/[\s_]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    // -------------------------------------------------------------------------
    // Duration "Custom…" toggle
    // -------------------------------------------------------------------------
    $('#mwm-duration').on('change', function () {
        const $wrap = $('#mwm-duration-custom-wrap');
        if ($(this).val() === '0') {
            $wrap.show();
            $('#mwm-duration-custom').focus();
        } else {
            $wrap.hide();
        }
    });

    // -------------------------------------------------------------------------
    // Copy-to-clipboard (click, Enter/Space, with announcement)
    // -------------------------------------------------------------------------
    function mwmCopyFallbackSelect(el) {
        const range = document.createRange();
        range.selectNodeContents(el);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
    }

    function mwmCopy(el) {
        const $el  = $(el);
        const text = $el.text();
        const done = () => {
            const orig = $el.text();
            $el.text(t('copied', 'Copied!'));
            announce(t('copiedAnnounce', 'Copied to clipboard.'));
            setTimeout(() => $el.text(orig), 1500);
        };
        const fallback = () => {
            mwmCopyFallbackSelect(el);
            announce(t('copyFallback', 'Text selected — press Ctrl/Cmd+C to copy.'));
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(fallback);
        } else {
            fallback();
        }
    }

    $(document).on('click', '.mwm-shortcode[data-copy]', function () {
        mwmCopy(this);
    });

    $(document).on('keydown', '.mwm-shortcode[data-copy]', function (e) {
        if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
            e.preventDefault();
            mwmCopy(this);
        }
    });

    // -------------------------------------------------------------------------
    // Replace-secret toggles (secrets are never rendered back into the page)
    // -------------------------------------------------------------------------
    $(document).on('change', '.mwm-replace-secret-toggle', function () {
        const $input = $($(this).data('target'));
        const on     = $(this).is(':checked');
        $input.prop('disabled', !on);
        if (on) $input.trigger('focus');
    });

    // =========================================================================
    // Meeting Type editor (fields builder, conditional sections, autosave,
    // availability override, live preview)
    // =========================================================================

    const $fieldsList  = $('#mwm-fields-list');
    const $fieldsJson  = $('#mwm-fields-json');
    const $addFieldBtn = $('#mwm-add-field');
    const $etForm      = $('#mwm-event-type-form');
    const tpl          = document.getElementById('mwm-field-row-tpl')?.innerHTML || '';

    if ($etForm.length) {

        let previewFormat = 'all';

        // Load existing fields from JSON hidden input
        let existingFields = [];
        try {
            existingFields = JSON.parse($fieldsJson.val() || '[]');
        } catch (e) {
            existingFields = [];
        }

        existingFields.forEach(field => addFieldRow(field));
        initOptionsSortables();
        renderOnlineProviderRules();
        updateConditionalSections();
        updateDisplayForVisibility();
        renderPreview();

        // ---- Change tracking: preview refreshes immediately, autosave debounces.
        let autosaveTimer  = null;
        let autosaveBusy   = false;
        let autosaveDirty  = false;

        function scheduleChanged() {
            renderPreview();
            if ($etForm.data('autosave') !== 'on') return;
            autosaveDirty = true;
            if (autosaveTimer) clearTimeout(autosaveTimer);
            autosaveTimer = setTimeout(runAutosave, 1500);
        }

        $etForm.on('input change', 'input, select, textarea', function () {
            if ($(this).is('[data-mwm-skip-autosave]')) return;
            scheduleChanged();
        });

        // Drag-reorder (jQuery UI sortable) fires no input/change events.
        $etForm.on('mwm:changed', scheduleChanged);

        function setAutosaveStatus(state, note) {
            const $chip = $('#mwm-autosave-status');
            if (!$chip.length) return;
            $chip.removeClass('is-saving is-saved is-error');
            if (state === 'saving') {
                $chip.addClass('is-saving').text(t('autosaving', 'Saving…'));
            } else if (state === 'saved') {
                $chip.addClass('is-saved').text(fmt(t('savedAt', 'Saved %s'), note || ''));
            } else if (state === 'error') {
                $chip.addClass('is-error').text(t('autosaveError', 'Save failed — click to retry'));
            } else {
                $chip.text('');
            }
        }

        function runAutosave() {
            if (autosaveBusy || !autosaveDirty) return;
            autosaveBusy  = true;
            autosaveDirty = false;
            serializeEditorState();
            setAutosaveStatus('saving');

            const data = $etForm.serializeArray();
            data.push({ name: 'action', value: 'mwm_autosave_event_type' });
            data.push({ name: 'nonce', value: ADMIN.nonce });

            $.post(ADMIN.ajaxUrl, $.param(data)).done(function (res) {
                if (res && res.success) {
                    setAutosaveStatus('saved', res.data && res.data.savedAt);
                    if (res.data && res.data.isNew && res.data.id) {
                        // First save of a brand-new type: switch the form into
                        // edit mode so subsequent changes autosave in place.
                        $('#mwm-event-type-id').val(res.data.id);
                        $etForm.data('autosave', 'on');
                        $etForm.find('button[name="mwm_save_event_type"]').text(t('saveChanges', 'Save Changes'));
                        const url = new URL(window.location.href);
                        url.searchParams.set('action', 'edit');
                        url.searchParams.set('id', res.data.id);
                        window.history.replaceState({}, '', url.toString());
                    }
                } else {
                    setAutosaveStatus('error');
                    autosaveDirty = true;
                }
            }).fail(function () {
                setAutosaveStatus('error');
                autosaveDirty = true;
            }).always(function () {
                autosaveBusy = false;
                if (autosaveDirty) {
                    autosaveTimer = setTimeout(runAutosave, 1500);
                }
            });
        }

        $('#mwm-autosave-status').on('click', function () {
            if ($(this).hasClass('is-error')) {
                autosaveDirty = true;
                runAutosave();
            }
        });

        // Serialize everything the server needs before submit (and autosave).
        function serializeEditorState() {
            $fieldsJson.val(JSON.stringify(serializeFields()));
            serializeAvailability();

            // Normalize automation fields to the selected mode so the stored
            // data always matches the UI (mode is derived on the server side).
            const mode = $etForm.find('input[name="automation_mode"]:checked').val() || 'none';
            const $provider = $('#mwm-online-provider');
            const $routing  = $('#mwm-online-routing-field');
            if (mode === 'none' && $provider.length) {
                $provider.val('');
            }
            if (mode !== 'conditional' && $routing.length) {
                $routing.val('');
            }
        }

        // Serialize on classic submit too.
        $etForm.on('submit', serializeEditorState);

        // ---- Collapsible cards -------------------------------------------
        const COLLAPSED_KEY = 'mwmCollapsedSections';
        let collapsed = [];
        try {
            collapsed = JSON.parse(window.localStorage.getItem(COLLAPSED_KEY) || '[]');
        } catch (e) {
            collapsed = [];
        }

        function applyCollapsed() {
            $('.mwm-collapsible').each(function () {
                const key = $(this).data('mwm-section');
                const isCollapsed = collapsed.includes(key);
                $(this).toggleClass('is-collapsed', isCollapsed);
                $(this).find('.mwm-card-toggle').attr('aria-expanded', isCollapsed ? 'false' : 'true');
            });
        }
        applyCollapsed();

        $(document).on('click', '.mwm-card-toggle', function () {
            const $card = $(this).closest('.mwm-collapsible');
            const key   = $card.data('mwm-section');
            const willCollapse = !$card.hasClass('is-collapsed');
            if (willCollapse) {
                collapsed.push(key);
            } else {
                collapsed = collapsed.filter(k => k !== key);
            }
            try {
                window.localStorage.setItem(COLLAPSED_KEY, JSON.stringify(collapsed));
            } catch (e) { /* private mode — sections just won't persist */ }
            applyCollapsed();
        });

        // ---- Conditional sections: automation + display-for ----------------
        function updateConditionalSections() {
            const format = $etForm.find('input[name="meeting_type"]:checked').val() || 'both';
            const $automationCard = $('[data-mwm-section="automation"]');
            const $modeRow   = $('#mwm-automation-mode-row');
            const hasModeRow = $modeRow.length > 0;

            // Automation only applies to online-capable formats.
            $automationCard.toggle(format !== 'in_person');

            if (hasModeRow) {
                const mode = $etForm.find('input[name="automation_mode"]:checked').val() || 'none';
                $('#mwm-automation-provider-row').toggle(mode !== 'none');
                $('#mwm-automation-routing-row').toggle(mode === 'conditional');
                $('#mwm-automation-rules-row').toggle(mode === 'conditional');
            }
        }

        function updateDisplayForVisibility() {
            const format = $etForm.find('input[name="meeting_type"]:checked').val() || 'both';
            $('#mwm-fields-list').toggleClass('mwm-hide-display-for', format !== 'both');
        }

        $etForm.on('change', 'input[name="meeting_type"], input[name="automation_mode"]', function () {
            updateConditionalSections();
            updateDisplayForVisibility();
        });

        // Display-for chips: highlighted box when on, plain outline when off.
        $fieldsList.on('change', '.mwm-fl-show-online, .mwm-fl-show-in-person', function () {
            syncDisplayChipRow($(this).closest('.mwm-display-chip'));
        });

        // ---- Per-type availability: ghost ⇄ custom -------------------------
        function serializeAvailability() {
            const mode = $etForm.find('input[name="availability_mode"]:checked').val() || 'default';
            const $json = $('#mwm-availability-json');
            if (mode !== 'custom') {
                $json.val('');
                return;
            }
            const weekly = {};
            $('#mwm-availability-custom .mwm-schedule-row').each(function () {
                const $row = $(this);
                const enabled = $row.find('.mwm-day-toggle').is(':checked');
                weekly[$row.data('dow')] = {
                    enabled: enabled,
                    start: $row.find('.mwm-time-input').eq(0).val() || '09:00',
                    end: $row.find('.mwm-time-input').eq(1).val() || '17:00',
                };
            });
            $json.val(JSON.stringify({ weekly: weekly }));
        }

        $etForm.on('change', 'input[name="availability_mode"]', function () {
            const custom = $(this).val() === 'custom';
            $('#mwm-availability-ghost').toggle(!custom);
            $('#mwm-availability-custom').toggle(custom);
        });

        // ---- Draggable cards (dashboard-style), persisted per browser --------
        const CARD_ORDER_KEY = 'mwmEditorCardOrder';
        const $editMain    = $('.mwm-edit-main');
        const $editSidebar = $('.mwm-edit-sidebar');

        function cardKey($card) {
            return $card.data('mwm-section') || $card.attr('id') || '';
        }

        function saveCardOrder() {
            const order = {
                main: $editMain.children('.mwm-card').map((i, el) => cardKey($(el))).get(),
                sidebar: $editSidebar.children('.mwm-card').map((i, el) => cardKey($(el))).get(),
            };
            try {
                window.localStorage.setItem(CARD_ORDER_KEY, JSON.stringify(order));
            } catch (e) { /* non-persistent order is fine */ }
        }

        function restoreCardOrder() {
            let order = null;
            try {
                order = JSON.parse(window.localStorage.getItem(CARD_ORDER_KEY) || 'null');
            } catch (e) {
                order = null;
            }
            if (!order) return;

            const place = function ($column, keys) {
                keys.forEach(key => {
                    if (!key) return;
                    const $card = $column.children('.mwm-card').filter(function () {
                        return cardKey($(this)) === key;
                    });
                    if ($card.length) {
                        $column.append($card);
                    }
                });
            };
            place($editMain, order.main || []);
            place($editSidebar, order.sidebar || []);
        }

        function movePreviewCard(toMain) {
            const $card = $('#mwm-preview-card');
            if (!$card.length) return;
            (toMain ? $editMain : $editSidebar).append($card);
            saveCardOrder();
        }

        restoreCardOrder();

        if ($.fn.sortable && $editMain.length && $editSidebar.length) {
            const sortableOpts = {
                items: '> .mwm-card',
                handle: '.mwm-card-drag-handle',
                connectWith: '.mwm-edit-layout .mwm-edit-main, .mwm-edit-layout .mwm-edit-sidebar',
                placeholder: 'mwm-card-sort-placeholder',
                forcePlaceholderSize: true,
                opacity: 0.75,
                tolerance: 'pointer',
                stop: saveCardOrder,
            };
            $editMain.sortable(sortableOpts);
            $editSidebar.sortable(sortableOpts);
        }

        // Keyboard-accessible stand-in for dragging the preview card.
        $('#mwm-preview-move').on('click', function () {
            const inSidebar = $('#mwm-preview-card').closest('.mwm-edit-sidebar').length > 0;
            movePreviewCard(inSidebar);
        });

        $(document).on('click', '.mwm-preview-filter', function () {
            $('.mwm-preview-filter').removeClass('is-active');
            $(this).addClass('is-active');
            previewFormat = $(this).data('preview-format');
            renderPreview();
        });

        // ---- Live preview (inert replica of the details step) --------------
        function renderPreview() {
            const $preview = $('#mwm-live-preview');
            if (!$preview.length) return;

            const name = $('#mwm-name').val() || t('previewUntitled', 'Untitled meeting type');
            const duration = $('#mwm-duration').val() === '0'
                ? ($('#mwm-duration-custom').val() || '?') + ' ' + t('minShort', 'min')
                : $('#mwm-duration option:selected').text() || '';

            let questions = serializeFields();
            if (previewFormat === 'online') {
                questions = questions.filter(f => f.show_online !== false);
            } else if (previewFormat === 'in_person') {
                questions = questions.filter(f => f.show_in_person !== false);
            }

            const questionsHtml = questions.length
                ? questions.map(f => previewQuestionHtml(f)).join('')
                : `<p class="mwm-preview-empty">${mwmEscHtml(t('previewNoQuestions', 'No questions yet — add one to see it here.'))}</p>`;

            $preview.html(`
<div class="mwm-booking-wizard mwm-preview-wizard" aria-hidden="true">
  <div class="mwm-summary">
    <div class="mwm-summary__type">${mwmEscHtml(name)} <span class="mwm-summary__meta">${mwmEscHtml(duration)}</span></div>
  </div>
  <div class="mwm-step mwm-step--details">
    <h2 class="mwm-step__title">${mwmEscHtml(t('stepDetails', 'Your details'))}</h2>
    <div class="mwm-form">
      <div class="mwm-form__group"><span class="mwm-form__label">${mwmEscHtml(t('name', 'Name'))} <span aria-hidden="true">*</span></span><div class="mwm-preview-input"></div></div>
      <div class="mwm-form__group"><span class="mwm-form__label">${mwmEscHtml(t('email', 'Email'))} <span aria-hidden="true">*</span></span><div class="mwm-preview-input"></div></div>
      ${questionsHtml}
    </div>
  </div>
</div>`);
        }

        function previewQuestionHtml(f) {
            const req = f.required ? ' <span aria-hidden="true">*</span>' : '';
            let control = '<div class="mwm-preview-input"></div>';
            const layoutClass = 'mwm-opts--' + (f.layout || 'stacked');

            if (f.type === 'textarea') {
                control = '<div class="mwm-preview-input mwm-preview-input--area"></div>';
            } else if (f.type === 'select') {
                control = `<div class="mwm-preview-input mwm-preview-select">${mwmEscHtml(f.options[0] || '…')}</div>`;
            } else if (f.type === 'radio' || f.type === 'checkbox') {
                control = `<div class="mwm-preview-opts ${layoutClass}">` + f.options.map(o =>
                    `<span class="mwm-preview-opt"><span class="mwm-preview-opt__box" aria-hidden="true"></span>${mwmEscHtml(o)}</span>`
                ).join('') + '</div>';
            }

            return `<div class="mwm-form__group"><span class="mwm-form__label">${mwmEscHtml(f.label || '…')}${req}</span>${control}</div>`;
        }

        // ---- Fields builder events -----------------------------------------
        $addFieldBtn.on('click', function () {
            addFieldRow(null);
            renderOnlineProviderRules();
        });

        $fieldsList.on('click', '.mwm-fl-remove', function () {
            $(this).closest('.mwm-field-row-item').remove();
            renderOnlineProviderRules();
        });

        // Keyboard-accessible reordering (equivalent to drag handle)
        $fieldsList.on('click', '.mwm-field-row-up', function () {
            const $row  = $(this).closest('.mwm-field-row-item');
            const $prev = $row.prev('.mwm-field-row-item');
            if ($prev.length) {
                $row.insertBefore($prev);
                announce(t('moveUp', 'Move question up'));
                $(this).focus();
                renderOnlineProviderRules();
            }
        });

        $fieldsList.on('click', '.mwm-field-row-down', function () {
            const $row  = $(this).closest('.mwm-field-row-item');
            const $next = $row.next('.mwm-field-row-item');
            if ($next.length) {
                $row.insertAfter($next);
                announce(t('moveDown', 'Move question down'));
                $(this).focus();
                renderOnlineProviderRules();
            }
        });

        // Type change → show/hide options or placeholder
        $fieldsList.on('change', '.mwm-fl-type', function () {
            updateFieldRowUI($(this).closest('.mwm-field-row-item'));
            renderOnlineProviderRules();
        });

        // Add option (for select/radio/checkbox)
        $fieldsList.on('click', '.mwm-fl-add-option', function () {
            const $optionsList = $(this).closest('.mwm-field-row-options').find('.mwm-options-list');
            $optionsList.append(makeOptionRow(''));
            renderOnlineProviderRules();
        });

        // Remove an option
        $fieldsList.on('click', '.mwm-option-remove', function () {
            $(this).closest('.mwm-option-row').remove();
            renderOnlineProviderRules();
        });

        // Option reordering (up/down)
        $fieldsList.on('click', '.mwm-option-up', function () {
            const $row  = $(this).closest('.mwm-option-row');
            const $prev = $row.prev('.mwm-option-row');
            if ($prev.length) {
                $row.insertBefore($prev);
                announce(t('optionMoveUp', 'Move option up'));
            }
        });

        $fieldsList.on('click', '.mwm-option-down', function () {
            const $row  = $(this).closest('.mwm-option-row');
            const $next = $row.next('.mwm-option-row');
            if ($next.length) {
                $row.insertAfter($next);
                announce(t('optionMoveDown', 'Move option down'));
            }
        });

        $fieldsList.on('input', '.mwm-fl-label, .mwm-option-value', function () {
            renderOnlineProviderRules();
        });

        // jQuery UI Sortable for drag reordering (dep enqueued in MWM_Admin)
        if ($.fn.sortable) {
            $fieldsList.sortable({
                handle: '.mwm-field-row-handle',
                axis: 'y',
                tolerance: 'pointer',
                placeholder: 'mwm-sort-placeholder',
                update: function () {
                    $etForm.trigger('mwm:changed');
                },
            });
        }
    }

    function addFieldRow(field) {
        const id  = (field && field.id) ? field.id : 'f' + Date.now();
        const html = tpl.replace(/__ID__/g, id);
        const $row = $(html);

        if (field) {
            $row.find('.mwm-fl-label').val(field.label || '');
            $row.find('.mwm-fl-type').val(field.type || 'text');
            $row.find('.mwm-fl-required').prop('checked', !!field.required);
            $row.find('.mwm-fl-placeholder').val(field.placeholder || '');
            $row.find('.mwm-fl-layout').val(field.layout || 'stacked');
            $row.find('.mwm-fl-show-online').prop('checked', field.show_online !== false);
            $row.find('.mwm-fl-show-in-person').prop('checked', field.show_in_person !== false);

            if (field.options && field.options.length) {
                field.options.forEach(opt => {
                    $row.find('.mwm-options-list').append(makeOptionRow(opt));
                });
            }
        } else {
            $row.find('.mwm-fl-show-online').prop('checked', true);
            $row.find('.mwm-fl-show-in-person').prop('checked', true);
        }

        $row.find('.mwm-display-chip').each(function () {
            syncDisplayChipRow($(this));
        });

        $fieldsList.append($row);
        updateFieldRowUI($row);
        initOptionSortable($row.find('.mwm-options-list'));
    }

    // Chip highlight sync usable before the editor block binds events.
    function syncDisplayChipRow($chip) {
        $chip.toggleClass('is-on', $chip.find('input').is(':checked'));
    }

    function initOptionSortable($list) {
        if ($.fn.sortable && $list.length) {
            $list.sortable({
                axis: 'y',
                tolerance: 'pointer',
                placeholder: 'mwm-sort-placeholder',
                update: function () {
                    $('#mwm-event-type-form').trigger('mwm:changed');
                },
            });
        }
    }

    function initOptionsSortables() {
        $('.mwm-options-list').each(function () {
            initOptionSortable($(this));
        });
    }

    function updateFieldRowUI($row) {
        const type         = $row.find('.mwm-fl-type').val();
        const hasOptions   = ['select', 'radio', 'checkbox'].includes(type);
        const hasPlaceholder = ['text', 'textarea'].includes(type);

        $row.find('.mwm-field-row-options').toggle(hasOptions);
        $row.find('.mwm-field-row-placeholder').toggle(hasPlaceholder);

        // The layout select only makes sense for radio/checkbox presentation.
        $row.find('.mwm-fl-layout').closest('label').toggle(type === 'radio' || type === 'checkbox');

        // Ensure at least one option row for options types
        if (hasOptions && $row.find('.mwm-option-row').length === 0) {
            $row.find('.mwm-options-list').append(makeOptionRow(''));
        }
    }

    function makeOptionRow(value) {
        return $('<div class="mwm-option-row">')
            .append($('<span class="mwm-option-handle" aria-hidden="true" title="' + mwmEscHtml(t('dragReorder', 'Drag to reorder')) + '">&#x2807;</span>'))
            .append($('<input type="text" class="mwm-option-value regular-text">')
                .val(value)
                .attr('placeholder', t('optionText', 'Option text'))
                .attr('aria-label', t('optionText', 'Option text')))
            .append($('<button type="button" class="mwm-option-up button-link" aria-label="' + mwmEscHtml(t('optionMoveUp', 'Move option up')) + '">&#9650;</button>'))
            .append($('<button type="button" class="mwm-option-down button-link" aria-label="' + mwmEscHtml(t('optionMoveDown', 'Move option down')) + '">&#9660;</button>'))
            .append($('<button type="button" class="mwm-option-remove button-link-delete">&#x2715;</button>')
                .attr('aria-label', t('removeOption', 'Remove option'))
                .attr('title', t('removeOption', 'Remove option')));
    }

    function serializeFields() {
        const fields = [];
        $fieldsList.find('.mwm-field-row-item').each(function (index) {
            const $row   = $(this);
            const type   = $row.find('.mwm-fl-type').val();
            const hasOpts = ['select', 'radio', 'checkbox'].includes(type);
            const options = [];

            if (hasOpts) {
                $row.find('.mwm-option-value').each(function () {
                    const v = $(this).val().trim();
                    if (v) options.push(v);
                });
            }

            fields.push({
                id:             $row.data('field-id'),
                label:          $row.find('.mwm-fl-label').val().trim(),
                type:           type,
                placeholder:    $row.find('.mwm-fl-placeholder').val().trim(),
                required:       $row.find('.mwm-fl-required').is(':checked'),
                options:        options,
                layout:         $row.find('.mwm-fl-layout').val() || 'stacked',
                show_online:    $row.find('.mwm-fl-show-online').length ? $row.find('.mwm-fl-show-online').is(':checked') : true,
                show_in_person: $row.find('.mwm-fl-show-in-person').length ? $row.find('.mwm-fl-show-in-person').is(':checked') : true,
                order:          index,
            });
        });
        return fields;
    }

    function parseJsonData($el, key, fallback) {
        if (!$el.length) return fallback;
        try {
            const value = $el.attr(key);
            return value ? JSON.parse(value) : fallback;
        } catch (e) {
            return fallback;
        }
    }

    function getProviderChoices() {
        const $rulesWrap = $('#mwm-online-provider-rules');
        return parseJsonData($rulesWrap, 'data-provider-choices', {});
    }

    function getRoutingFields() {
        if ($fieldsList.length) {
            return serializeFields()
                .filter(field => ['select', 'radio'].includes(field.type) && Array.isArray(field.options) && field.options.length)
                .map(field => ({
                    id: field.id,
                    label: field.label,
                    type: field.type,
                    options: field.options,
                }));
        }

        const $rulesWrap = $('#mwm-online-provider-rules');
        return parseJsonData($rulesWrap, 'data-routing-fields', []);
    }

    function renderOnlineProviderRules() {
        const $rulesWrap = $('#mwm-online-provider-rules');
        const $routingSelect = $('#mwm-online-routing-field');
        if (!$rulesWrap.length || !$routingSelect.length) return;

        const providerChoices = getProviderChoices();
        const routingFields = getRoutingFields();
        const previousRules = {};

        $rulesWrap.find('select[name^="online_provider_rules["]').each(function () {
            const match = $(this).attr('name').match(/^online_provider_rules\[(.*)\]$/);
            if (match) {
                previousRules[match[1]] = $(this).val();
            }
        });

        const currentValue = $routingSelect.val();

        const selectHtml = [`<option value="">${mwmEscHtml(t('noRouting', 'No conditional routing'))}</option>`]
            .concat(routingFields.map(field => {
                const selected = field.id === currentValue ? ' selected' : '';
                return `<option value="${mwmEscHtml(field.id)}"${selected}>${mwmEscHtml(field.label)} (${mwmEscHtml(field.type)})</option>`;
            }))
            .join('');
        $routingSelect.html(selectHtml);

        const activeField = routingFields.find(field => field.id === $routingSelect.val());
        if (!activeField) {
            $rulesWrap.html(`<p class="description">${mwmEscHtml(t('selectRouting', 'Select a routing field above to map specific answers to connected providers.'))}</p>`);
            return;
        }

        const rows = activeField.options.map(option => {
            const currentProvider = previousRules[option] || '';
            const providerOptions = [`<option value="">${mwmEscHtml(t('useDefault', 'Use default provider'))}</option>`]
                .concat(Object.entries(providerChoices).map(([providerKey, providerLabel]) => {
                    const selected = providerKey === currentProvider ? ' selected' : '';
                    return `<option value="${mwmEscHtml(providerKey)}"${selected}>${mwmEscHtml(providerLabel)}</option>`;
                }))
                .join('');

            return `<div class="mwm-provider-rule-row">
                <span class="mwm-provider-rule-label">${mwmEscHtml(option)}</span>
                <select name="online_provider_rules[${mwmEscHtml(option)}]">${providerOptions}</select>
            </div>`;
        });

        $rulesWrap.html(rows.join(''));
    }

    // =========================================================================
    // Availability page
    // =========================================================================

    // Weekly schedule: toggle time inputs when day checkbox changes
    $(document).on('change', '.mwm-day-toggle', function () {
        const $row   = $(this).closest('.mwm-schedule-row');
        const $times = $row.find('.mwm-schedule-times');
        const $off   = $row.find('.mwm-schedule-off-label');
        const on     = $(this).is(':checked');

        $times.find('input').prop('disabled', !on);
        $times.toggleClass('mwm-times-disabled', !on);
        $off.toggleClass('mwm-hidden', on);
        $row.toggleClass('is-enabled', on);
    });

    // Override form: show/hide time fields based on availability radio
    $(document).on('change', '.mwm-override-avail-toggle', function () {
        const available = $('input[name="override_available"]:checked').val() === '1';
        $('#override-time-fields').toggle(available);
    });

    // Toggle inline add-forms (Add Override, Add Blocked Date)
    $(document).on('click', '.mwm-toggle-form', function () {
        const target = $(this).data('target');
        const $form  = $('#' + target);

        $form.slideToggle(150, function () {
            // Move focus into the form when it is shown.
            if ($(this).is(':visible')) {
                $(this).find('input, select, textarea, button').filter(':visible').first().trigger('focus');
            } else {
                // Form hidden (e.g. Cancel) — return focus to the opener
                // button outside the form so focus isn't dropped to <body>.
                $('.mwm-toggle-form[data-target="' + target + '"]')
                    .filter(function () { return !$.contains($form[0], this); })
                    .first()
                    .trigger('focus');
            }
        });
    });

    // =========================================================================
    // Google Calendar — Load calendars via AJAX
    // =========================================================================

    function mwmEscHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function renderCalendarOptions(calendars) {
        const $checks = $('#mwm-calendar-checkboxes');
        const $writeBack = $('#write_back_calendar_id');
        const selectedChecks = [];
        const selectedWriteBack = $writeBack.val() || '';

        $checks.find('input[type="checkbox"]').each(function () {
            if ($(this).is(':checked')) selectedChecks.push($(this).val());
        });

        let checksHtml = '';
        let writeBackHtml = `<option value="">${mwmEscHtml(t('noWriteBack', 'Do not create Google Calendar events'))}</option>`;

        calendars.forEach(cal => {
            const checked = selectedChecks.includes(cal.id) || (!selectedChecks.length && cal.primary);
            const primary = cal.primary ? ' mwm-calendar-check--primary' : '';
            const summary = mwmEscHtml(cal.summary || cal.id);
            const id = mwmEscHtml(cal.id);
            const readOnly = cal.writable ? '' : ` <small>${mwmEscHtml(t('readOnly', 'Read only'))}</small>`;

            checksHtml += `<label class="mwm-calendar-check${primary}">
                <input type="checkbox" name="calendar_ids[]" value="${id}" ${checked ? 'checked' : ''}>
                ${summary}${cal.primary ? ' <em>' + mwmEscHtml(t('primarySuffix', '(primary)')) + '</em>' : ''}${readOnly}
            </label>`;

            if (cal.writable) {
                writeBackHtml += `<option value="${id}" ${selectedWriteBack === cal.id ? 'selected' : ''}>${summary}${cal.primary ? ' ' + mwmEscHtml(t('primarySuffix', '(primary)')) : ''}</option>`;
            }
        });

        $checks.html(checksHtml || `<p class="description" id="mwm-no-cals-msg">${mwmEscHtml(t('noCals', 'No calendars were returned from Google.'))}</p>`);

        if ($writeBack.is('select')) {
            $writeBack.html(writeBackHtml);

            if (selectedWriteBack && !calendars.some(cal => cal.writable && cal.id === selectedWriteBack)) {
                $writeBack.append(`<option value="${mwmEscHtml(selectedWriteBack)}" selected>${mwmEscHtml(selectedWriteBack)}</option>`);
            }
        }
    }

    $('#mwm-load-cals-btn').on('click', function () {
        const $btn   = $(this);
        const nonce  = $('#mwm_fetch_cals_nonce').val();

        $btn.addClass('is-loading').text(t('loading', 'Loading…'));

        $.post(window.location.href, {
            mwm_fetch_calendars: 1,
            mwm_fetch_cals_nonce: nonce,
        }, function (res) {
            $btn.removeClass('is-loading').text(t('refreshCalendars', 'Refresh My Calendars'));

            if (!res.success || !res.data.calendars) {
                alert((res.data && res.data.message) || t('calsFailed', 'Could not load calendars.'));
                return;
            }

            renderCalendarOptions(res.data.calendars);
            $('#mwm-no-cals-msg').remove();
        }).fail(function () {
            $btn.removeClass('is-loading').text(t('refreshCalendars', 'Refresh My Calendars'));
            alert(t('requestFailed', 'Request failed. Please try again.'));
        });
    });

    // =========================================================================
    // Zoom — Test connection
    // =========================================================================

    $('#mwm-test-zoom').on('click', function () {
        const $btn = $(this);
        const $out = $('#mwm-test-zoom-result');
        if (!ADMIN.ajaxUrl) return;

        $btn.prop('disabled', true).text(t('testing', 'Testing…'));
        $out.removeClass('is-error is-success').text('');

        $.post(ADMIN.ajaxUrl, { action: 'mwm_test_zoom', nonce: ADMIN.nonce })
            .done(function (res) {
                if (res && res.success) {
                    $out.addClass('is-success').text(res.data.message);
                } else {
                    $out.addClass('is-error').text((res && res.data && res.data.message) || t('requestFailed', 'Request failed. Please try again.'));
                }
            })
            .fail(function () {
                $out.addClass('is-error').text(t('requestFailed', 'Request failed. Please try again.'));
            })
            .always(function () {
                $btn.prop('disabled', false).text(t('testConnection', 'Test Connection'));
            });
    });

    // =========================================================================
    // Style tab — color pickers + live preview
    // =========================================================================

    function updateStylePreview() {
        const el = document.querySelector('.mwm-style-preview');
        if (!el) return;

        const accent   = $('#accent_color').val();
        const contrast = $('#accent_text_color').val();
        const radius   = $('#button_radius').val();
        const density  = $('#density').val();
        const buttonStyle = $('#button_style').val() || 'outline';

        if (accent) {
            el.style.setProperty('--mwm-accent', accent);
            el.style.setProperty('--mwm-accent-contrast', contrast || '#ffffff');
        } else {
            el.style.removeProperty('--mwm-accent');
            el.style.removeProperty('--mwm-accent-contrast');
        }

        if (radius === 'pill') {
            el.style.setProperty('--mwm-radius', '999px');
            el.style.setProperty('--mwm-btn-radius', '999px');
        } else if (radius !== '' && radius != null) {
            el.style.setProperty('--mwm-radius', radius + 'px');
            el.style.setProperty('--mwm-btn-radius', radius + 'px');
        } else {
            el.style.removeProperty('--mwm-radius');
            el.style.removeProperty('--mwm-btn-radius');
        }

        if (density === 'compact') {
            el.style.setProperty('--mwm-space', '0.75');
        } else {
            el.style.removeProperty('--mwm-space');
        }

        el.classList.remove('mwm-style-preview--outline', 'mwm-style-preview--filled', 'mwm-style-preview--link');
        el.classList.add('mwm-style-preview--' + buttonStyle);
    }

    if ($.fn.wpColorPicker) {
        $('.mwm-color-input').wpColorPicker({
            change: updateStylePreview,
            clear: updateStylePreview,
        });
    }

    $('#mwm-style-form').on('change input', 'select, input[type="number"]', updateStylePreview);

}(jQuery));
