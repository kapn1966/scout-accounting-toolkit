/* Scout Accounting Toolkit: inbox interactions (Ajax saves, splits, evidence acceptance). */
(function () {
    'use strict';

    function showSessionExpired(target) {
        var box = target;
        if (!box) {
            var root = document.querySelector('.sat-inbox');
            if (!root) {
                return;
            }
            box = root.querySelector('.ajax-message');
            if (box) {
                box.remove();
            }
            box = document.createElement('div');
            box.className = 'msg ajax-message error';
            box.setAttribute('role', 'alert');
            var heading = root.querySelector('h2');
            if (heading) {
                heading.insertAdjacentElement('afterend', box);
            }
        }
        box.textContent = 'Your WordPress session has expired. Please log in again to continue.';
        var loginUrl = window.satInbox && window.satInbox.loginUrl;
        if (loginUrl) {
            box.appendChild(document.createTextNode(' '));
            var login = document.createElement('a');
            login.href = loginUrl;
            login.textContent = 'Log in again';
            box.appendChild(login);
        }
    }

    window.satShowSessionExpired = showSessionExpired;
    window.satIsSessionExpired = isSessionExpired;

    function isSessionExpired(response, body) {
        if (response.status === 401 || (response.redirected && /\/wp-login\.php(?:[?#]|$)/i.test(response.url))) {
            return true;
        }
        var text = body.trim();
        return text === '-1' || text === '0'
            || /(?:id=["']loginform["']|name=["']log["']|<title[^>]*>\s*(?:log in|login))/i.test(body);
    }

    function boot() {
        var waveSendState = 'idle';

        function protectWaveSend(event) {
            event.preventDefault();
            event.returnValue = '';
        }

        document.addEventListener('submit', function (event) {
            var form = event.target;
            var action = form.querySelector('[name="action"]');
            if (waveSendState !== 'idle' && form.closest('.sat-inbox')
                && (waveSendState === 'processing' || (action && /^(sat_wave_export_all|sat_wave_export_one)$/.test(action.value)))) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        }, true);

        async function sendAllToWave(form) {
            var data = new FormData(form);
            var button = form.querySelector('.sat-wave-export-all');
            var originalText = button.textContent;
            var controls = Array.from(document.querySelectorAll('.sat-wave-export-one, .sat-wave-export-all'));
            var modal = document.createElement('dialog');
            modal.className = 'sat-wave-processing';
            modal.setAttribute('aria-labelledby', 'sat-wave-processing-title');
            modal.setAttribute('aria-describedby', 'sat-wave-processing-warning');
            modal.innerHTML = '<h3 id="sat-wave-processing-title">Sending to Wave</h3>'
                + '<p>Submitting <strong data-wave-total></strong> transactions from this preview.</p>'
                + '<p id="sat-wave-processing-warning">This operation may take several minutes. '
                + 'Do not reload, close the tab, navigate Back, or resubmit while processing.</p>'
                + '<p role="status"><progress aria-label="Processing transactions"></progress> Processing. Please wait.</p>';
            modal.querySelector('[data-wave-total]').textContent = form.dataset.total;
            modal.addEventListener('cancel', function (event) { event.preventDefault(); });
            document.body.appendChild(modal);
            modal.showModal();
            waveSendState = 'processing';
            controls.forEach(function (control) { control.disabled = true; });
            button.setAttribute('aria-busy', 'true');
            button.textContent = 'Sending to Wave...';
            window.addEventListener('beforeunload', protectWaveSend);
            var resultUrl = null;
            try {
                var response = await fetch(form.action, {
                    method: 'POST', credentials: 'same-origin', body: data
                });
                // Consume the response before declaring the browser request complete.
                var body = await response.text();
                var url = new URL(response.url);
                if (!response.ok || !response.redirected || isSessionExpired(response, body)
                    || url.origin !== window.location.origin
                    || url.searchParams.get('sat_batch') !== data.get('batch_id')
                    || !/^(success|error)$/.test(url.searchParams.get('sat_notice'))
                    || !url.searchParams.has('sat_message')) {
                    throw new Error('No confirmed Send all result was received.');
                }
                resultUrl = url.href;
            } catch (error) {
                waveSendState = 'uncertain';
            } finally {
                modal.close();
                modal.remove();
                button.setAttribute('aria-busy', 'false');
                button.textContent = originalText;
                window.removeEventListener('beforeunload', protectWaveSend);
            }
            if (resultUrl) {
                waveSendState = 'finished';
                window.location.assign(resultUrl);
            } else {
                showMessage('DO NOT RESUBMIT this batch. The browser did not receive a confirmed result. '
                    + 'Processing may have partially completed and may still be running. '
                    + 'Reopen Wave Export and review the recorded item and attempt state. '
                    + 'Check Wave before any authorized retry; a lost response does not mean nothing was exported.', 'error');
                var message = document.querySelector('.sat-inbox .ajax-message');
                message.classList.remove('ajax-message');
                message.classList.add('sat-wave-uncertain');
                message.setAttribute('role', 'alert');
                message.setAttribute('tabindex', '-1');
                var reopen = document.createElement('a');
                var reviewUrl = new URL(data.get('return_url'), window.location.href);
                reviewUrl.searchParams.set('sat_view', 'wave');
                reviewUrl.searchParams.set('sat_batch', data.get('batch_id'));
                reviewUrl.searchParams.delete('sat_notice');
                reviewUrl.searchParams.delete('sat_message');
                reopen.href = reviewUrl.href;
                reopen.textContent = 'Reopen Wave Export';
                message.appendChild(document.createTextNode(' '));
                message.appendChild(reopen);
                message.focus();
            }
        }

        function moneyToCents(value) {
            var text = String(value || '').trim().replace(/[$\s]/g, '').replace(',', '.');
            if (!/^\d+(?:\.\d{1,2})?$/.test(text)) {
                return null;
            }
            var cents = Math.round(Number(text) * 100);
            return Number.isFinite(cents) && cents > 0 ? cents : null;
        }

        function updateSplit(form) {
            if (!form) {
                return;
            }
            var total = moneyToCents(form.dataset.total);
            var allocated = 0;
            var valid = true;
            var used = 0;
            form.querySelectorAll('.line').forEach(function (row) {
                var account = row.querySelector('select').value;
                var amountText = row.querySelector('[name="split_amount[]"]').value.trim();
                var note = row.querySelector('[name="split_note[]"]').value.trim();
                if (!account && !amountText && !note) {
                    return;
                }
                used++;
                var cents = moneyToCents(amountText);
                if (!account || cents === null) {
                    valid = false;
                    return;
                }
                allocated += cents;
            });
            var remaining = total === null ? 0 : total - allocated;
            var allocatedEl = form.querySelector('.allocated');
            var remainingEl = form.querySelector('.remaining');
            if (!allocatedEl || !remainingEl) {
                return;
            }
            allocatedEl.textContent = (allocated / 100).toFixed(2);
            remainingEl.textContent = (remaining / 100).toFixed(2);
            var ready = total !== null && used > 0 && valid && remaining === 0;
            var state = form.querySelector('.split-state');
            if (state) {
                state.textContent = ready ? 'Balanced and ready to save'
                    : (!valid ? 'Complete every used line' : (used === 0 ? 'Add at least one split line' : 'Split must balance'));
                state.className = 'split-state ' + (ready ? 'valid' : 'invalid');
            }
            var save = form.querySelector('button.save');
            if (save) {
                save.disabled = !ready;
            }
        }

        function showMessage(text, type) {
            var root = document.querySelector('.sat-inbox');
            if (!root) {
                return;
            }
            var old = root.querySelector('.ajax-message');
            if (old) {
                old.remove();
            }
            var box = document.createElement('div');
            box.className = 'msg ajax-message ' + (type || 'success');
            box.textContent = text;
            var heading = root.querySelector('h2');
            if (heading) {
                heading.insertAdjacentElement('afterend', box);
            }
        }

        function setBusy(form, busy) {
            var button = form.querySelector('button[type="submit"]');
            if (button) {
                button.disabled = busy;
                button.setAttribute('aria-busy', busy ? 'true' : 'false');
            }
        }

        function reportError(error) {
            if (error.sessionExpired) {
                showSessionExpired();
            } else {
                showMessage(error.message, 'error');
            }
        }

        function bankRow(id) {
            return document.querySelector('tr.bank[data-bank-id="' + id + '"]');
        }

        /* Update a row from server state: {bank_id, pill, ready, category, split}. */
        function applyState(s) {
            if (!s || !s.bank_id) {
                return;
            }
            var row = bankRow(s.bank_id);
            if (!row) {
                return;
            }
            row.classList.remove('ready', 'needs-category');
            row.classList.add(s.ready ? 'ready' : 'needs-category');
            var pill = row.querySelector('.pill');
            if (pill && s.pill) {
                pill.textContent = s.pill;
            }
            var select = row.querySelector('form.category select[name="category"]');
            if (select && s.category) {
                select.value = s.category;
            }
            var splitButton = row.querySelector('.split-open');
            if (splitButton) {
                splitButton.textContent = s.split ? 'Edit split' : 'Split';
            }
        }

        /* Remove suggestion rows for a transaction, keeping one (the accepted one, or the best). */
        function removeCandidateRows(id, keep) {
            if (!keep) {
                keep = document.querySelector('tr.cand-' + id + '.best');
            }
            document.querySelectorAll('tr.cand-' + id + ', tr.alternative-' + id).forEach(function (row) {
                if (row !== keep) {
                    row.remove();
                }
            });
            var toggle = document.querySelector('.candidates-toggle[data-target="alternative-' + id + '"]');
            if (toggle) {
                toggle.remove();
            }
        }

        async function postForm(form, action) {
            var data = new FormData(form);
            data.set('action', action);
            data.set('nonce', data.get('_wpnonce') || '');
            var response;
            try {
                response = await fetch(satInbox.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data });
            } catch (ignored) {
                throw new Error('The request could not be completed. Check your connection and try again.');
            }
            var body;
            try {
                body = await response.text();
            } catch (ignored) {
                throw new Error('The request could not be completed. Check your connection and try again.');
            }
            var result = null;
            try {
                result = JSON.parse(body);
            } catch (ignored) {
                result = null;
            }
            if (isSessionExpired(response, body)) {
                var expired = new Error('Your WordPress session has expired. Please log in again to continue.');
                expired.sessionExpired = true;
                throw expired;
            }
            if (response.status >= 500) {
                throw new Error('The server could not complete the request. Please try again.');
            }
            if (!response.ok || !result || !result.success) {
                throw new Error(result && result.data && result.data.message ? result.data.message : 'The request could not be completed. Please try again.');
            }
            return result.data;
        }

        document.querySelectorAll('form.split').forEach(updateSplit);
        document.querySelectorAll('.candidates-toggle').forEach(function (button) {
            button.dataset.closed = button.textContent;
            button.dataset.open = 'Hide alternatives';
        });
        var wavePreview = document.getElementById('sat-wave-preview-edit');
        if (wavePreview) {
            wavePreview.addEventListener('input', function () {
                document.querySelectorAll('.sat-wave-export-one, .sat-wave-export-all').forEach(function (button) {
                    button.disabled = true;
                    button.title = 'Save descriptions and notes before exporting.';
                });
            });
        }

        document.addEventListener('click', function (e) {
            var receiptsToggle = e.target.closest('.sat-receipts-toggle');
            if (receiptsToggle) {
                var group = receiptsToggle.closest('.sat-receipt-group');
                var list = group && group.querySelector('.sat-receipts-list');
                if (list) {
                    var expanded = receiptsToggle.getAttribute('aria-expanded') === 'true';
                    var count = group.querySelector('.sat-receipts-count').textContent;
                    receiptsToggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                    receiptsToggle.setAttribute('aria-label', (expanded ? 'Show ' : 'Hide ') + count + ' receipts');
                    list.hidden = expanded;
                }
                return;
            }
            if (e.target.matches('.sat-inbox .all')) {
                document.querySelectorAll('.check:not(:disabled)').forEach(function (box) {
                    box.checked = e.target.checked;
                });
            }
            var open = e.target.closest('.split-open');
            if (open) {
                var modal = document.getElementById(open.dataset.id);
                if (modal) {
                    modal.hidden = false;
                    updateSplit(modal.querySelector('.split'));
                }
                return;
            }
            var close = e.target.closest('[data-close]');
            if (close) {
                close.closest('.modal').hidden = true;
                return;
            }
            if (e.target.matches('.sat-inbox .add')) {
                var form = e.target.closest('.split');
                var lines = form.querySelector('.lines');
                var row = lines.querySelector('.line').cloneNode(true);
                row.querySelectorAll('input, select').forEach(function (field) {
                    field.value = '';
                });
                lines.appendChild(row);
                updateSplit(form);
            }
            if (e.target.matches('.sat-inbox .remove')) {
                var splitForm = e.target.closest('.split');
                if (splitForm.querySelector('.lines').children.length > 1) {
                    e.target.closest('.line').remove();
                    updateSplit(splitForm);
                }
            }
            var toggle = e.target.closest('.candidates-toggle');
            if (toggle) {
                var expanded = toggle.getAttribute('aria-expanded') === 'true';
                toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                document.querySelectorAll('.' + toggle.dataset.target).forEach(function (r) {
                    r.hidden = expanded;
                });
                toggle.textContent = expanded ? toggle.dataset.closed : toggle.dataset.open;
            }
        });

        ['input', 'change'].forEach(function (type) {
            document.addEventListener(type, function (e) {
                var form = e.target.closest('form.split');
                if (form) {
                    updateSplit(form);
                }
            });
        });

        document.addEventListener('submit', async function (e) {
            var form = e.target;
            if (!window.satInbox || !window.fetch || !form.closest('.sat-inbox')) {
                return;
            }

            if (form.matches('.sat-wave-send-all-form')) {
                if (!e.defaultPrevented && waveSendState === 'idle') {
                    e.preventDefault();
                    await sendAllToWave(form);
                }
                return;
            }

            if (form.matches('#sat-bulk-form')) {
                var action = form.querySelector('input[name="action"]');
                if (action && action.value !== 'sat_inbox_action') {
                    if (!document.querySelector('.check:checked')) {
                        e.preventDefault();
                        showMessage('Select at least one transaction.', 'error');
                    }
                    return;
                }
                e.preventDefault();
                form.querySelectorAll('input.bulk-category-value').forEach(function (x) {
                    x.remove();
                });
                var selected = 0;
                document.querySelectorAll('.check:checked').forEach(function (check) {
                    selected++;
                    var row = check.closest('tr.bank');
                    var select = row ? row.querySelector('form.category select[name="category"]') : null;
                    if (select) {
                        var hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.className = 'bulk-category-value';
                        hidden.name = 'displayed_category[' + check.value + ']';
                        hidden.value = select.value;
                        form.appendChild(hidden);
                    }
                });
                if (!selected) {
                    showMessage('Select at least one transaction.', 'error');
                    return;
                }
                setBusy(form, true);
                try {
                    var bulk = await postForm(form, satInbox.actionAction);
                    (bulk.processed || []).forEach(function (item) {
                        if (item.pill) {
                            applyState(item);
                        }
                        removeCandidateRows(item.bank_id);
                        var check = document.querySelector('.check[value="' + item.bank_id + '"]');
                        if (check) {
                            check.checked = false;
                        }
                    });
                    showMessage(bulk.message + ' Refresh to see matched evidence and transfers.', 'success');
                } catch (error) {
                    reportError(error);
                } finally {
                    setBusy(form, false);
                }
                return;
            }

            if (form.matches('form.category')) {
                e.preventDefault();
                setBusy(form, true);
                try {
                    var saved = await postForm(form, satInbox.actionAction);
                    applyState(saved);
                    showMessage(saved.message, 'success');
                } catch (error) {
                    reportError(error);
                } finally {
                    setBusy(form, false);
                }
                return;
            }

            if (form.matches('form.split')) {
                e.preventDefault();
                updateSplit(form);
                if (form.querySelector('button.save').disabled) {
                    return;
                }
                setBusy(form, true);
                try {
                    var split = await postForm(form, satInbox.actionAction);
                    applyState(split);
                    form.closest('.modal').hidden = true;
                    showMessage(split.message, 'success');
                } catch (error) {
                    reportError(error);
                } finally {
                    setBusy(form, false);
                    updateSplit(form);
                }
                return;
            }

            if (form.matches('form.ajax-accept')) {
                e.preventDefault();
                setBusy(form, true);
                try {
                    var result = await postForm(form, satInbox.acceptAction);
                    var evidenceRow = form.closest('tr.evidence');
                    removeCandidateRows(result.bank_id, evidenceRow);
                    if (evidenceRow) {
                        evidenceRow.hidden = false;
                        evidenceRow.className = 'evidence matched';
                        var cell = evidenceRow.querySelector('td[colspan]');
                        if (cell) {
                            cell.textContent = '└─ MATCHED · ' + (result.label || 'Evidence');
                        }
                    }
                    applyState(result);
                    if (result.pair_id) {
                        removeCandidateRows(result.pair_id, null);
                        var best = document.querySelector('tr.cand-' + result.pair_id + '.best');
                        if (best) {
                            best.remove();
                        }
                        applyState({ bank_id: result.pair_id, pill: result.pair_pill, ready: result.pair_ready, category: result.pair_category, split: false });
                    }
                    showMessage(result.message || 'Match accepted.', 'success');
                } catch (error) {
                    setBusy(form, false);
                    reportError(error);
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
})();
