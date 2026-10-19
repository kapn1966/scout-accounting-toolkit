/* Scout Accounting Toolkit: Expense Claims correction dialog (loads, saves and resets without a page reload). */
(function () {
    'use strict';

    var FIELDS = ['transaction_date', 'payee', 'description', 'amount'];

    function boot() {
        var config = window.satClaimCorrections;
        var modal = document.getElementById('sat-correction-modal');
        var table = document.querySelector('table.claims');
        if (!config || !modal || !table || !window.fetch || !window.FormData) {
            return;
        }
        var form = modal.querySelector('form');
        var panel = modal.querySelector('[role="dialog"]');
        var body = modal.querySelector('[data-correction-body]');
        var loading = modal.querySelector('[data-correction-loading]');
        var errorBox = modal.querySelector('[data-correction-error]');
        var state = { id: 0, data: null, trigger: null, busy: false, seq: 0 };

        function show(el, visible) {
            if (el) {
                el.hidden = !visible;
            }
        }

        function setText(el, text) {
            if (el) {
                el.textContent = text === null || text === undefined ? '' : String(text);
            }
        }

        function box(field) {
            return modal.querySelector('[data-correction-field="' + field + '"]');
        }

        function input(field) {
            return form.elements['values[' + field + ']'];
        }

        function fieldData(claim, field) {
            var found = null;
            (claim ? claim.fields : []).forEach(function (f) {
                if (f.field === field) {
                    found = f;
                }
            });
            return found;
        }

        /* Make everything except the dialog unreachable while it is open. */
        function setInert(on) {
            var node = modal;
            while (node && node.parentElement && node !== document.body) {
                Array.prototype.forEach.call(node.parentElement.children, function (sibling) {
                    if (sibling === node || sibling.tagName === 'SCRIPT') {
                        return;
                    }
                    if (on && !sibling.hasAttribute('inert')) {
                        sibling.setAttribute('inert', '');
                        sibling.setAttribute('data-sat-inert', '');
                    } else if (!on && sibling.hasAttribute('data-sat-inert')) {
                        sibling.removeAttribute('inert');
                        sibling.removeAttribute('data-sat-inert');
                    }
                });
                node = node.parentElement;
            }
        }

        function focusable() {
            return Array.prototype.filter.call(
                panel.querySelectorAll('button, input, select, textarea, summary, [href], [tabindex]:not([tabindex="-1"])'),
                function (el) {
                    return !el.disabled && el.offsetParent !== null;
                }
            );
        }

        function setBusy(busy) {
            state.busy = busy;
            Array.prototype.forEach.call(form.querySelectorAll('button, input, select, textarea'), function (el) {
                el.disabled = busy;
            });
            var save = form.querySelector('[data-correction-save]');
            if (save) {
                save.setAttribute('aria-busy', busy ? 'true' : 'false');
            }
        }

        function clearErrors() {
            show(errorBox, false);
            setText(errorBox, '');
            Array.prototype.forEach.call(modal.querySelectorAll('[data-correction-field-error]'), function (el) {
                show(el, false);
                setText(el, '');
            });
            Array.prototype.forEach.call(form.querySelectorAll('[aria-invalid]'), function (el) {
                el.removeAttribute('aria-invalid');
            });
        }

        function showError(message, errors) {
            setText(errorBox, message);
            show(errorBox, true);
            var first = null;
            Object.keys(errors || {}).forEach(function (field) {
                var control = field === 'reason' ? form.elements.reason : input(field);
                var target = field === 'reason' ? document.getElementById('sat-correction-reason-error') : (box(field) && box(field).querySelector('[data-correction-field-error]'));
                if (!control || !target) {
                    return;
                }
                setText(target, errors[field]);
                show(target, true);
                control.setAttribute('aria-invalid', 'true');
                first = first || control;
            });
            if (first) {
                first.focus({ preventScroll: true });
            }
        }

        function announce(text) {
            var root = document.querySelector('.sat-inbox');
            if (!root) {
                return;
            }
            var old = root.querySelector('.ajax-message');
            if (old) {
                old.remove();
            }
            var message = document.createElement('div');
            message.className = 'msg ajax-message success';
            message.setAttribute('role', 'status');
            message.textContent = text;
            var heading = root.querySelector('h2');
            if (heading) {
                heading.insertAdjacentElement('afterend', message);
            }
        }

        function monthWarning() {
            var month = table.getAttribute('data-claim-month') || '';
            var date = input('transaction_date').value;
            var current = state.data ? (fieldData(state.data, 'transaction_date') || {}).effective : '';
            if (!month || !date || date === current || date.slice(0, 7) === month) {
                return '';
            }
            return 'Saving moves this claim out of ' + (table.getAttribute('data-claim-month-label') || month) + '. It will leave this view and its totals.';
        }

        function updateWarnings() {
            var warning = modal.querySelector('[data-correction-month-warning]');
            var text = monthWarning();
            setText(warning, text);
            show(warning, text !== '');
            var amount = fieldData(state.data, 'amount');
            show(modal.querySelector('[data-correction-amount-warning]'), !!amount && input('amount').value.trim() !== amount.effective);
        }

        function renderHistory(events) {
            var holder = modal.querySelector('[data-correction-history]');
            holder.textContent = '';
            if (!events || !events.length) {
                var none = document.createElement('p');
                none.textContent = 'No corrections have been recorded for this claim.';
                holder.appendChild(none);
                return;
            }
            var list = document.createElement('table');
            var head = list.createTHead().insertRow();
            ['When', 'Who', 'Field', 'Change', 'From', 'To', 'Imported value', 'Reason'].forEach(function (label) {
                var th = document.createElement('th');
                th.scope = 'col';
                th.textContent = label;
                head.appendChild(th);
            });
            var rows = list.createTBody();
            events.forEach(function (e) {
                var row = rows.insertRow();
                [e.when, e.actor, e.field, e.type, e.old, e.new, e.source, e.reason].forEach(function (value) {
                    row.insertCell().textContent = value === null || value === undefined ? '' : String(value);
                });
            });
            holder.appendChild(list);
        }

        /* Fill the dialog from server data. preserve: a field just reset/kept; other fields keep unsaved edits. */
        function fill(claim, preserve) {
            var previous = state.data;
            state.data = claim;
            setText(modal.querySelector('[data-correction-entry]'), '#' + claim.entry_id);
            claim.fields.forEach(function (f) {
                var holder = box(f.field);
                var control = input(f.field);
                var old = fieldData(previous, f.field);
                var dirty = preserve && preserve !== f.field && old && control.value !== old.effective;
                if (!dirty) {
                    control.value = f.effective;
                }
                setText(holder.querySelector('[data-correction-source]'), f.source === '' ? '(blank)' : f.source);
                var badge = holder.querySelector('[data-correction-badge]');
                if (f.state === 'conflict') {
                    badge.className = 'pill correction-review';
                    badge.textContent = 'Correction review needed';
                } else {
                    badge.className = 'pill corrected';
                    badge.textContent = f.state === 'agrees' ? 'Corrected (imported value now agrees)' : 'Corrected';
                }
                show(badge, f.active);
                setText(holder.querySelector('[data-correction-audit]'), f.active
                    ? 'Corrected by ' + f.updated_by + (f.updated_at ? ' on ' + f.updated_at : '') + (f.reason ? ' · ' + f.reason : '')
                    : 'Not corrected');
                var conflict = holder.querySelector('[data-correction-conflict]');
                show(conflict, f.state === 'conflict');
                setText(holder.querySelector('[data-correction-captured]'), f.captured_source);
                setText(holder.querySelector('[data-correction-current]'), f.source);
                setText(holder.querySelector('[data-correction-corrected]'), f.effective);
                show(holder.querySelector('[data-correction-use-source]'), f.active && f.state !== 'conflict');
            });
            form.elements.reason.value = claim.reason || 'other';
            show(modal.querySelector('[data-correction-review]'), !!claim.review);
            show(modal.querySelector('[data-correction-mismatch]'), !!claim.amount_mismatch);
            renderHistory(claim.events);
            updateWarnings();
        }

        async function post(action, nonce, data) {
            data.set('action', action);
            data.set('nonce', nonce);
            var response = await fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data });
            var result = null;
            try {
                result = await response.json();
            } catch (ignored) {
                result = null;
            }
            if (!response.ok || !result || !result.success) {
                var info = result && result.data ? result.data : {};
                var error = new Error(info.message || 'The request failed. Nothing was changed.');
                error.status = response.status;
                error.kind = info.kind || '';
                error.errors = info.errors || {};
                throw error;
            }
            return result.data;
        }

        async function load() {
            var seq = ++state.seq;
            var data = new FormData();
            data.set('observation_id', String(state.id));
            try {
                var result = await post(config.loadAction, config.readNonce, data);
                if (seq !== state.seq || modal.hidden) {
                    return;
                }
                fill(result.claim, null);
                show(loading, false);
                show(body, true);
                input('transaction_date').focus({ preventScroll: true });
            } catch (error) {
                if (seq === state.seq) {
                    show(loading, false);
                    showError(error.message, {});
                }
            }
        }

        function editButton() {
            return table.querySelector('tr[data-claim-id="' + state.id + '"] .claim-edit');
        }

        function open(trigger) {
            state.id = parseInt(trigger.getAttribute('data-claim-id'), 10) || 0;
            state.trigger = trigger;
            state.data = null;
            clearErrors();
            show(body, false);
            show(loading, true);
            setText(modal.querySelector('[data-correction-entry]'), '');
            modal.hidden = false;
            setInert(true);
            panel.focus({ preventScroll: true });
            load();
        }

        function close() {
            if (state.busy || modal.hidden) {
                return;
            }
            state.seq++;
            modal.hidden = true;
            setInert(false);
            state.data = null;
            var target = state.trigger && document.body.contains(state.trigger) ? state.trigger : editButton();
            if (!target) {
                target = table;
                table.setAttribute('tabindex', '-1');
            }
            target.focus({ preventScroll: true });
        }

        function ensureEmptyRow() {
            var tbody = table.tBodies[0];
            if (!tbody.querySelector('tr[data-claim-id]') && !tbody.querySelector('tr.claims-empty')) {
                var row = tbody.insertRow();
                row.className = 'claims-empty';
                var cell = row.insertCell();
                cell.colSpan = table.tHead ? table.tHead.rows[0].cells.length : 12;
                cell.textContent = 'No expense claims match these filters.';
            }
        }

        /* Replace, move or remove the claim's row and refresh the summary, all from the server response. */
        function applyRow(result) {
            var tbody = table.tBodies[0];
            var row = table.querySelector('tr[data-claim-id="' + state.id + '"]');
            if (!result.row.in_view) {
                if (row) {
                    row.remove();
                }
                state.trigger = null;
                ensureEmptyRow();
            } else {
                var template = document.createElement('template');
                template.innerHTML = String(result.row.html).trim();
                var fresh = template.content.firstElementChild;
                var next = result.row.next_id ? table.querySelector('tr[data-claim-id="' + result.row.next_id + '"]') : null;
                if (row) {
                    // Responses carry no receipt URLs; corrections never change receipts.
                    var oldReceipts = row.querySelector('[data-claim-receipts]');
                    var newReceipts = fresh.querySelector('[data-claim-receipts]');
                    if (oldReceipts && newReceipts) {
                        newReceipts.innerHTML = oldReceipts.innerHTML;
                    }
                    row.remove();
                }
                if (next) {
                    tbody.insertBefore(fresh, next);
                } else {
                    tbody.appendChild(fresh);
                }
                state.trigger = fresh.querySelector('.claim-edit');
            }
            var summary = result.summary || {};
            ['claims', 'total', 'receipts'].forEach(function (key) {
                setText(document.querySelector('[data-claim-summary="' + key + '"] dd'), summary[key]);
            });
            var warning = document.querySelector('[data-claim-receipt-warning]');
            if (warning) {
                setText(warning, summary.receipt_warning || '');
                show(warning, !!summary.receipt_warning);
            }
        }

        async function mutate(action, data, closeAfter, field) {
            data.set('observation_id', String(state.id));
            data.set('token', state.data.token);
            data.set('view', window.location.search);
            clearErrors();
            setBusy(true);
            try {
                var result = await post(action, config.writeNonce, data);
                applyRow(result);
                setBusy(false);
                if (closeAfter) {
                    close();
                    announce(result.message);
                } else {
                    fill(result.claim, field);
                    announce(result.message);
                    var control = input(field);
                    if (control) {
                        control.focus({ preventScroll: true });
                    }
                }
            } catch (error) {
                setBusy(false);
                var stale = error.status === 409 && error.kind === 'stale';
                if (stale) {
                    await load();
                }
                showError(error.message, stale ? {} : error.errors);
            }
        }

        document.addEventListener('click', function (e) {
            var trigger = e.target.closest('.claim-edit');
            if (trigger && table.contains(trigger) && !trigger.disabled) {
                e.preventDefault();
                open(trigger);
                return;
            }
            if (modal.hidden || !modal.contains(e.target)) {
                return;
            }
            if (e.target.closest('[data-correction-cancel]')) {
                e.preventDefault();
                close();
                return;
            }
            var op = e.target.closest('[data-correction-op]');
            if (op && state.data && !state.busy) {
                var holder = op.closest('[data-correction-field]');
                var data = new FormData();
                data.set('field', holder.getAttribute('data-correction-field'));
                data.set('operation', op.getAttribute('data-correction-op'));
                mutate(config.fieldAction, data, false, holder.getAttribute('data-correction-field'));
            }
        });

        modal.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                close();
                return;
            }
            if (e.key !== 'Tab') {
                return;
            }
            var items = focusable();
            if (!items.length) {
                e.preventDefault();
                panel.focus({ preventScroll: true });
                return;
            }
            var first = items[0];
            var last = items[items.length - 1];
            if (e.shiftKey && (document.activeElement === first || document.activeElement === panel)) {
                e.preventDefault();
                last.focus({ preventScroll: true });
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus({ preventScroll: true });
            }
        });

        form.addEventListener('input', updateWarnings);
        form.addEventListener('change', updateWarnings);

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (state.busy || !state.data) {
                return;
            }
            var warning = monthWarning();
            if (warning && !window.confirm(warning + ' Save the correction?')) {
                return;
            }
            var data = new FormData();
            data.set('reason', form.elements.reason.value);
            FIELDS.forEach(function (field) {
                data.set('values[' + field + ']', input(field).value);
            });
            mutate(config.saveAction, data, true, null);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
})();
