/* Scout Accounting Toolkit: inbox interactions (Ajax saves, splits, evidence acceptance). */
(function () {
    'use strict';

    function boot() {
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
            var response = await fetch(satInbox.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data });
            var result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result && result.data && result.data.message ? result.data.message : 'The change could not be saved.');
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
                if (!e.defaultPrevented) {
                    var sendAll = form.querySelector('.sat-wave-export-all');
                    if (sendAll) {
                        sendAll.disabled = true;
                        sendAll.setAttribute('aria-busy', 'true');
                        sendAll.textContent = 'Sending to Wave…';
                    }
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
                    showMessage(error.message, 'error');
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
                    showMessage(error.message, 'error');
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
                    showMessage(error.message, 'error');
                } finally {
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
                    showMessage(error.message, 'error');
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
