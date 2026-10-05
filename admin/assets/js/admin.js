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
    // Custom Fields Builder
    // =========================================================================

    const $fieldsList  = $('#mwm-fields-list');
    const $fieldsJson  = $('#mwm-fields-json');
    const $addFieldBtn = $('#mwm-add-field');
    const tpl          = document.getElementById('mwm-field-row-tpl')?.innerHTML || '';

    if ($fieldsList.length && tpl) {

        // Load existing fields from JSON hidden input
        let existingFields = [];
        try {
            existingFields = JSON.parse($fieldsJson.val() || '[]');
        } catch (e) {
            existingFields = [];
        }

        existingFields.forEach(field => addFieldRow(field));
        renderOnlineProviderRules();

        // Add new field
        $addFieldBtn.on('click', function () {
            addFieldRow(null);
            renderOnlineProviderRules();
        });

        // Remove field
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

        $fieldsList.on('input', '.mwm-fl-label, .mwm-option-value', function () {
            renderOnlineProviderRules();
        });

        // Serialize fields before form submit
        $('#mwm-event-type-form').on('submit', function () {
            $fieldsJson.val(JSON.stringify(serializeFields()));
        });

        // jQuery UI Sortable for drag reordering (dep enqueued in MWM_Admin)
        if ($.fn.sortable) {
            $fieldsList.sortable({
                handle: '.mwm-field-row-handle',
                axis: 'y',
                tolerance: 'pointer',
                placeholder: 'mwm-sort-placeholder',
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

            if (field.options && field.options.length) {
                field.options.forEach(opt => {
                    $row.find('.mwm-options-list').append(makeOptionRow(opt));
                });
            }
        }

        $fieldsList.append($row);
        updateFieldRowUI($row);
    }

    function updateFieldRowUI($row) {
        const type         = $row.find('.mwm-fl-type').val();
        const hasOptions   = ['select', 'radio', 'checkbox'].includes(type);
        const hasPlaceholder = ['text', 'textarea'].includes(type);

        $row.find('.mwm-field-row-options').toggle(hasOptions);
        $row.find('.mwm-field-row-placeholder').toggle(hasPlaceholder);

        // Ensure at least one option row for options types
        if (hasOptions && $row.find('.mwm-option-row').length === 0) {
            $row.find('.mwm-options-list').append(makeOptionRow(''));
        }
    }

    function makeOptionRow(value) {
        return $('<div class="mwm-option-row">')
            .append($('<input type="text" class="mwm-option-value regular-text">')
                .val(value)
                .attr('placeholder', t('optionText', 'Option text'))
                .attr('aria-label', t('optionText', 'Option text')))
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
                id:          $row.data('field-id'),
                label:       $row.find('.mwm-fl-label').val().trim(),
                type:        type,
                placeholder: $row.find('.mwm-fl-placeholder').val().trim(),
                required:    $row.find('.mwm-fl-required').is(':checked'),
                options:     options,
                order:       index,
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
