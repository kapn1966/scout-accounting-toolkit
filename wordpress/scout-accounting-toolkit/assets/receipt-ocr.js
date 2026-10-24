/* Scout Accounting Toolkit: receipt OCR suggestions for the expense form. */
(function () {
    'use strict';
    var initialized = false;

    function boot() {
        if (initialized || !window.satReceiptOcr) {
            return;
        }
        var c = window.satReceiptOcr;
        var form = document.getElementById('gform_' + c.formId);
        var field = document.getElementById('field_' + c.formId + '_' + c.uploadFieldId);
        if (!form || !field) {
            return;
        }
        initialized = true;

        var box = document.createElement('div');
        box.className = 'sat-ocr-status';
        box.setAttribute('role', 'status');
        box.setAttribute('aria-live', 'polite');
        box.hidden = true;
        field.appendChild(box);

        function input(id) {
            return document.getElementById('input_' + c.formId + '_' + id);
        }

        function isEmpty(el) {
            return el && !String(el.value || '').trim();
        }

        function setIfEmpty(el, value) {
            if (!el || !value || !isEmpty(el)) {
                return false;
            }
            el.value = value;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
            return true;
        }

        function status(text, type) {
            box.hidden = false;
            box.className = 'sat-ocr-status ' + (type || 'info');
            box.textContent = text;
        }

        /* Select a payment radio by value or label, unless one is already selected. */
        function selectPaymentChoice(choice) {
            var radios = Array.from(form.querySelectorAll('input[type="radio"][name="input_' + c.paymentFieldId + '"]'));
            if (!radios.length || radios.some(function (r) { return r.checked; })) {
                return false;
            }
            var wanted = String(choice || '').trim().toLowerCase();
            if (!wanted) {
                return false;
            }
            var radio = radios.find(function (r) {
                var label = form.querySelector('label[for="' + CSS.escape(r.id) + '"]');
                return String(r.value || '').trim().toLowerCase() === wanted
                    || (label && label.textContent.trim().toLowerCase() === wanted);
            });
            if (!radio) {
                return false;
            }
            radio.checked = true;
            radio.dispatchEvent(new Event('input', { bubbles: true }));
            radio.dispatchEvent(new Event('change', { bubbles: true }));
            radio.click();
            return true;
        }

        async function processFiles(fileList) {
            var files = Array.from(fileList || []).slice(0, c.maxFiles);
            if (!files.length) {
                return;
            }
            status(c.messages.reading, 'reading');
            var data = new FormData();
            data.append('action', c.action);
            data.append('nonce', c.nonce);
            files.forEach(function (file) {
                data.append('receipts[]', file, file.name);
            });
            try {
                var response = await fetch(c.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data });
                var payload = await response.json();
                if (!response.ok || !payload.success) {
                    throw new Error(payload && payload.data && payload.data.message ? payload.data.message : c.messages.error);
                }
                var result = payload.data || {};
                if (!result.success) {
                    status(c.messages.none, 'warning');
                    return;
                }
                var changed = [];
                if (setIfEmpty(input(c.payeeFieldId), result.merchant)) {
                    changed.push('payee');
                }
                if (setIfEmpty(input(c.descriptionFieldId), result.description)) {
                    changed.push('description');
                }
                if (setIfEmpty(input(c.dateFieldId), result.date)) {
                    changed.push('date');
                }
                if (setIfEmpty(input(c.amountFieldId), result.total)) {
                    changed.push('total');
                }
                var receipt = result.receipts && result.receipts.length ? result.receipts[0] : null;
                var cardDetected = typeof result.card_detected === 'boolean' ? result.card_detected : !!(receipt && receipt.card_detected);
                var cardMatch = typeof result.card_match === 'boolean' ? result.card_match : !!(receipt && receipt.card_match);
                var cardSuffix = result.card_suffix || (receipt && receipt.card_suffix) || '';
                var cardSelected = cardMatch && selectPaymentChoice(c.creditCardChoice);
                var reimbursementSelected = cardDetected && !cardMatch && selectPaymentChoice(c.reimbursementChoice);

                var message = files.length > 1 ? c.messages.multiple : c.messages.review;
                if (cardSelected) {
                    message += ' ' + c.messages.card;
                }
                if (reimbursementSelected) {
                    message += ' ' + c.messages.reimbursement;
                }
                if ((cardSelected || reimbursementSelected) && cardSuffix) {
                    message += ' Detected card ending in ' + cardSuffix + '.';
                }
                if (!changed.length && !cardSelected && !reimbursementSelected) {
                    message += ' Existing values were not overwritten.';
                }
                status(message, 'success');
            } catch (error) {
                console.error('Scout Accounting receipt OCR', error);
                status(error.message || c.messages.error, 'warning');
            }
        }

        document.addEventListener('change', function (event) {
            var target = event.target;
            if (!target || target.type !== 'file' || !field.contains(target)) {
                return;
            }
            var selected = Array.from(target.files || []);
            if (selected.length) {
                processFiles(selected);
            }
        }, true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
    document.addEventListener('gform/postRender', boot);
})();
