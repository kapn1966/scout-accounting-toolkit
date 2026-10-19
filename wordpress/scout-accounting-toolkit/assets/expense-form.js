/* Scout Accounting Toolkit: Invoice and Credit Card expense claims carry exactly one receipt.
 * Usability layer only: the server applies the same rule. Uploaded files are never removed here. */
(function () {
    'use strict';
    var c = window.satExpenseForm;
    if (!c || !c.formId || !c.receiptFieldId || !c.paymentFieldId) {
        return;
    }
    var formId = String(c.formId);
    var fieldId = String(c.receiptFieldId);
    var paymentName = 'input_' + c.paymentFieldId;
    var uploaderKey = 'gform_multifile_upload_' + formId + '_' + fieldId;
    var filterRegistered = false;
    var observer = null;
    var observed = null;

    /* Mirrors SAT_Expense_Form::payment_kind(); matches stored values, not labels. */
    function paymentKind(value) {
        value = String(value || '').toLowerCase();
        if (value.indexOf('card') !== -1) {
            return 'card';
        }
        if (value.indexOf('reimburs') !== -1) {
            return 'reimburse';
        }
        if (value.indexOf('invoice') !== -1) {
            return 'invoice';
        }
        return 'other';
    }

    function getForm() {
        return document.getElementById('gform_' + formId);
    }

    function getField() {
        return document.getElementById('field_' + formId + '_' + fieldId);
    }

    function requiresSingle(form) {
        var checked = form.querySelector('input[name="' + paymentName + '"]:checked');
        var select = form.querySelector('select[name="' + paymentName + '"]');
        var kind = paymentKind(checked ? checked.value : (select ? select.value : ''));
        return kind === 'card' || kind === 'invoice';
    }

    /* The count Gravity Forms itself uses for multi-file fields, plus a plain file input if present. */
    function receiptCount() {
        var count = 0;
        var store = document.getElementById('gform_uploaded_files_' + formId);
        if (store && store.value) {
            try {
                var files = JSON.parse(store.value);
                var list = files && files['input_' + fieldId];
                if (Array.isArray(list)) {
                    count = list.length;
                }
            } catch (e) {
                count = 0;
            }
        }
        var input = document.getElementById('input_' + formId + '_' + fieldId);
        if (input && input.type === 'file' && input.files) {
            count += input.files.length;
        }
        return count;
    }

    function blocked(form) {
        return requiresSingle(form) && receiptCount() > 1;
    }

    /* Gravity Forms reads gf_vars.max_files when files are added, so one receipt is enforced at upload. */
    function applyUploadLimit(form) {
        var registry = window.gfMultiFileUploader && window.gfMultiFileUploader.uploaders;
        var uploader = registry ? registry[uploaderKey] : null;
        if (!uploader || !uploader.settings || !uploader.settings.gf_vars) {
            return;
        }
        var vars = uploader.settings.gf_vars;
        if (vars.satOriginalMaxFiles === undefined) {
            vars.satOriginalMaxFiles = vars.max_files;
        }
        vars.max_files = requiresSingle(form) ? 1 : vars.satOriginalMaxFiles;
        var limit = parseInt(vars.max_files, 10);
        if (typeof window.gfMultiFileUploader.toggleDisabled === 'function') {
            window.gfMultiFileUploader.toggleDisabled(uploader.settings, limit > 0 && receiptCount() >= limit);
        }
    }

    function serverMessageShown(field) {
        var nodes = field.querySelectorAll('.validation_message, .gfield_validation_message');
        for (var i = 0; i < nodes.length; i++) {
            if (!nodes[i].classList.contains('sat-receipt-rule') && nodes[i].textContent.trim() === c.message) {
                return true;
            }
        }
        return false;
    }

    function showMessage(show) {
        var field = getField();
        if (!field) {
            return;
        }
        var box = field.querySelector('.sat-receipt-rule');
        show = show && !serverMessageShown(field);
        if (!show) {
            if (box && !box.hidden) {
                box.hidden = true;
            }
            return;
        }
        if (!box) {
            box = document.createElement('div');
            box.className = 'gfield_description validation_message gfield_validation_message sat-receipt-rule';
            box.setAttribute('role', 'alert');
            field.appendChild(box);
        }
        if (box.textContent !== c.message) {
            box.textContent = c.message;
        }
        box.hidden = false;
    }

    function refresh() {
        var form = getForm();
        if (!form) {
            return;
        }
        applyUploadLimit(form);
        showMessage(blocked(form));
        watchUploads();
        registerSubmissionFilter();
    }

    function refreshSoon() {
        window.setTimeout(refresh, 0);
    }

    /* Uploads and deletions change the preview list; recheck whenever it changes. */
    function watchUploads() {
        var list = document.getElementById('gform_preview_' + formId + '_' + fieldId);
        if (!list || list === observed || typeof MutationObserver === 'undefined') {
            return;
        }
        if (observer) {
            observer.disconnect();
        }
        observer = new MutationObserver(refreshSoon);
        observer.observe(list, { childList: true, subtree: true });
        observed = list;
    }

    function stop() {
        window['gf_submitting_' + formId] = false;
        showMessage(true);
        var field = getField();
        if (field && typeof field.scrollIntoView === 'function') {
            field.scrollIntoView({ block: 'center' });
        }
    }

    /* Gravity Forms 2.9+ routes every submission through this filter. */
    function registerSubmissionFilter() {
        var utils = window.gform && window.gform.utils;
        if (filterRegistered || !utils || typeof utils.addFilter !== 'function') {
            return;
        }
        filterRegistered = true;
        utils.addFilter('gform/submission/pre_submission', function (data) {
            if (!data || data.abort || !data.form || data.form.id !== 'gform_' + formId) {
                return data;
            }
            if (data.submissionType === 'previous' || data.submissionType === 'save-continue') {
                return data;
            }
            if (blocked(data.form)) {
                data.abort = true;
                stop();
            }
            return data;
        }, 9);
    }

    /* Older Gravity Forms and plain submits: stop the submit event before Gravity Forms handles it. */
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || form.id !== 'gform_' + formId) {
            return;
        }
        var target = form.querySelector('input[name="gform_target_page_number_' + formId + '"]');
        var source = form.querySelector('input[name="gform_source_page_number_' + formId + '"]');
        if (target && source && target.value !== '0' && parseInt(target.value, 10) < parseInt(source.value, 10)) {
            return;
        }
        if (blocked(form)) {
            event.preventDefault();
            event.stopImmediatePropagation();
            stop();
        }
    }, true);

    document.addEventListener('change', function (event) {
        var target = event.target;
        if (target && (target.name === paymentName || (target.type === 'file' && getField() && getField().contains(target)))) {
            refreshSoon();
        }
    }, true);

    document.addEventListener('gform/postRender', refreshSoon);
    document.addEventListener('gform/post_render', refreshSoon);
    if (window.jQuery) {
        window.jQuery(document).on('gform_post_render gform_post_conditional_logic', refreshSoon);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', refresh, { once: true });
    } else {
        refresh();
    }
})();
