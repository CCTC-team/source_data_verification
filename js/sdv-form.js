/**
 * Source Data Verification - data entry form UI.
 *
 * Renders entirely from the JSON state built by SdvService::buildState(). Every action is a single
 * module.ajax() call that returns the new state, so the page never needs reloading. All user-supplied
 * text is written with textContent.
 */
var CctcSdvForm = (function () {
    'use strict';

    var jsModule = null;
    var state = null;
    var busy = false;
    var historyShown = false;

    // ---- DOM helpers -----------------------------------------------------------------------------

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            var v = attrs[k];
            if (v === null || v === undefined || v === false) return;
            if (k === 'text') node.textContent = v;
            else if (k === 'class') node.className = v;
            else if (k.indexOf('on') === 0) node.addEventListener(k.substring(2), v);
            else node.setAttribute(k, v === true ? '' : v);
        });
        (children || []).forEach(function (c) {
            if (c === null || c === undefined || c === false) return;
            node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        });
        return node;
    }

    function button(text, onClick, extraClass, attrs) {
        var a = Object.assign({ type: 'button', class: 'btn btn-xs ' + (extraClass || 'btn-defaultrc'), text: text, onclick: onClick }, attrs || {});
        return el('button', a);
    }

    function fieldText(field) {
        var f = state.fields[field];
        return state.settings.includeFieldLabel && f && f.label ? field + ' [' + f.label + ']' : field;
    }

    function onReady(fn) {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
        else fn();
    }

    // ---- server calls ----------------------------------------------------------------------------

    function call(action, payload) {
        if (busy) return Promise.resolve(null);
        busy = true;
        setMessage(null);
        if (typeof showProgress === 'function') showProgress(1);
        payload = Object.assign({ ctx: state.ctx }, payload || {});
        return jsModule.ajax(action, payload).then(function (resp) {
            if (!resp || !resp.ok) {
                setMessage((resp && resp.error) || 'The request failed.', true);
                return null;
            }
            if (resp.state) { state = resp.state; render(); }
            if (resp.message) setMessage(resp.message, false);
            return resp;
        }).catch(function (err) {
            setMessage('The request failed: ' + err, true);
            return null;
        }).finally(function () {
            busy = false;
            if (typeof showProgress === 'function') showProgress(0, 0);
        });
    }

    // ---- page adjustments (save buttons / read-only) ---------------------------------------------

    function applyUi(ui) {
        if (ui.hideSave) {
            document.querySelectorAll('#__SUBMITBUTTONS__-tr, #__SUBMITBUTTONS__-div, #formSaveTip, #submit-btn-cancel-tr')
                .forEach(function (n) { n.classList.add('cctc-sdv-hidden'); });
        }
        if (ui.readonly) {
            ui.formFields.forEach(function (field) {
                var row = document.getElementById(field + '-tr');
                if (!row) return;
                row.classList.add('cctc-sdv-readonly');
                row.querySelectorAll('input, textarea, select').forEach(function (input) {
                    if (input.type !== 'hidden') input.setAttribute('tabindex', '-1');
                    if (input.tagName !== 'SELECT' && input.type !== 'checkbox' && input.type !== 'radio') input.readOnly = true;
                });
            });
        }
    }

    // ---- inline field badges ---------------------------------------------------------------------

    function labelCell(field) {
        var row = document.getElementById(field + '-tr');
        if (!row) return null;
        return row.querySelector('td.labelrc') || row.querySelector('td');
    }

    function renderBadges() {
        document.querySelectorAll('.cctc-sdv-badge').forEach(function (n) { n.remove(); });
        if (!state.exists) return;
        var isMonitor = state.role.monitor;
        var formOpen = state.form.open;

        Object.keys(state.fields).forEach(function (field) {
            var f = state.fields[field];
            var cell = labelCell(field);
            if (!cell) return;
            var parts = [];

            if (f.sdv === 'verified') {
                parts.push(el('span', { class: 'cctc-sdv-chip cctc-sdv-ok', title: 'Verified by ' + f.verifiedBy + ' ' + f.verifiedAt, 'data-sdv': 'verified' },
                    [el('i', { class: 'fas fa-check-circle' }), ' SDV verified']));
            } else if (f.sdv === 'changed') {
                parts.push(el('span', { class: 'cctc-sdv-chip cctc-sdv-warn', title: 'Value changed after verification by ' + f.verifiedBy + ' ' + f.verifiedAt, 'data-sdv': 'changed' },
                    [el('i', { class: 'fas fa-exclamation-triangle' }), ' Changed since SDV']));
            }

            if (f.requirement === 'required') {
                parts.push(el('span', { class: 'cctc-sdv-chip cctc-sdv-req', title: f.tags.length ? 'Action tag: ' + f.tags.join(', ') : 'Set on the SDV plan' }, ['SDV required']));
            } else if (f.requirement === 'excluded') {
                parts.push(el('span', { class: 'cctc-sdv-chip cctc-sdv-muted' }, ['Excluded from SDV']));
            }

            if (f.queryId) {
                var qLabel = f.queryStatus === 'responded' ? ' Query responded' : ' Query open';
                parts.push(el('span', { class: 'cctc-sdv-chip cctc-sdv-query' }, [el('i', { class: 'fas fa-flag' }), qLabel]));
                if (state.settings.showInline && f.queryText) {
                    var t = f.queryText.length > 80 ? f.queryText.substring(0, 77) + '...' : f.queryText;
                    parts.push(el('span', { class: 'cctc-sdv-inline-query', title: f.queryText, text: t }));
                }
            }

            if (isMonitor && formOpen && f.selectable && !f.queryId) {
                if (f.sdv === 'verified') {
                    parts.push(button('Unverify', function () { call('unverifyField', { field: field }); }, 'btn-link cctc-sdv-link'));
                } else {
                    parts.push(button(f.sdv === 'changed' ? 'Re-verify' : 'Verify', function () { call('verifyField', { field: field }); },
                        'btn-success cctc-sdv-verify-btn', { 'data-field': field }));
                    if (f.sdv === 'changed') {
                        parts.push(button('Unverify', function () { call('unverifyField', { field: field }); }, 'btn-link cctc-sdv-link'));
                    }
                }
                parts.push(button('Raise query', function () { toggleRaise(field); }, 'btn-link cctc-sdv-link'));
            }

            if (state.role.canEditPlan) {
                parts.push(planSelect(field, f));
            }

            if (parts.length === 0) return;
            var badge = el('div', { class: 'cctc-sdv-badge', 'data-field': field }, parts);
            badge.appendChild(el('div', { class: 'cctc-sdv-raise', id: 'cctc-sdv-raise-' + field, style: 'display:none' }));
            cell.appendChild(badge);
        });
    }

    function planSelect(field, f) {
        var sel = el('select', { class: 'cctc-sdv-plan-select', title: 'SDV requirement for this field on all records', 'aria-label': 'SDV requirement for ' + field });
        [['required', 'SDV: required'], ['optional', 'SDV: optional'], ['excluded', 'SDV: excluded']].forEach(function (o) {
            var label = o[1] + (o[0] === f['default'] ? ' (default)' : '');
            var opt = el('option', { value: o[0], text: label });
            if (o[0] === f.requirement) opt.selected = true;
            sel.appendChild(opt);
        });
        sel.addEventListener('change', function () {
            var value = sel.value;
            if (!confirm('Change the SDV requirement of "' + field + '" to "' + value + '" for this field on ALL records?')) {
                sel.value = f.requirement;
                return;
            }
            call('setPlan', { field: field, requirement: value }).then(function (resp) {
                if (!resp) sel.value = f.requirement;
            });
        });
        return sel;
    }

    function toggleRaise(field) {
        var box = document.getElementById('cctc-sdv-raise-' + field);
        if (!box) return;
        if (box.style.display !== 'none') { box.style.display = 'none'; box.textContent = ''; return; }
        var ta = el('textarea', { class: 'x-form-text x-form-field cctc-sdv-textarea', rows: '2', placeholder: 'Enter monitor query for ' + field, id: 'cctc-sdv-raise-text-' + field });
        box.appendChild(ta);
        box.appendChild(el('div', { class: 'cctc-sdv-row' }, [
            button('Raise query', function () { call('raiseQuery', { field: field, text: ta.value }); }, 'btn-primaryrc', { id: 'cctc-sdv-raise-btn-' + field }),
            button('Cancel', function () { toggleRaise(field); }, 'btn-link cctc-sdv-link'),
        ]));
        box.style.display = 'block';
        ta.focus();
    }

    // ---- panel -----------------------------------------------------------------------------------

    function setMessage(text, isError) {
        var box = document.getElementById('cctc-sdv-message');
        if (!box) return;
        box.textContent = text || '';
        box.className = text ? (isError ? 'red cctc-sdv-message' : 'darkgreen cctc-sdv-message') : 'cctc-sdv-message';
        box.style.display = text ? 'block' : 'none';
    }

    function reasonForm(id, prompt, confirmText, onConfirm) {
        var wrap = el('div', { class: 'cctc-sdv-reason', id: id, style: 'display:none' });
        var ta = el('textarea', { class: 'x-form-text x-form-field cctc-sdv-textarea', rows: '2', placeholder: prompt, id: id + '-text' });
        wrap.appendChild(ta);
        wrap.appendChild(el('div', { class: 'cctc-sdv-row' }, [
            button(confirmText, function () { onConfirm(ta.value); }, 'btn-primaryrc', { id: id + '-confirm' }),
            button('Cancel', function () { wrap.style.display = 'none'; }, 'btn-link cctc-sdv-link'),
        ]));
        return wrap;
    }

    function showOnly(id) {
        document.querySelectorAll('.cctc-sdv-reason').forEach(function (n) { n.style.display = n.id === id ? 'block' : 'none'; });
    }

    function renderPanel() {
        var panel = document.getElementById('cctc-sdv-panel');
        if (!panel) return;
        panel.textContent = '';

        panel.appendChild(el('div', { class: 'cctc-sdv-header' }, [
            el('i', { class: 'fas fa-clipboard-check' }), ' Source Data Verification',
        ]));

        if (!state.exists) {
            panel.appendChild(el('div', { class: 'cctc-sdv-body', text: 'SDV is available once this form has been saved.' }));
            return;
        }

        var body = el('div', { class: 'cctc-sdv-body' });
        panel.appendChild(body);

        var c = state.counts;
        body.appendChild(el('div', { class: 'cctc-sdv-summary' }, [
            el('span', { class: 'cctc-sdv-status cctc-sdv-status-' + state.form.status, id: 'cctc-sdv-form-status', text: state.form.statusLabel }),
            el('span', { id: 'cctc-sdv-required-count', text: 'Required fields verified: ' + c.requiredVerified + ' / ' + c.required }),
            el('span', { text: 'Fields verified: ' + c.verified }),
            el('span', { id: 'cctc-sdv-query-count', text: 'Active queries: ' + c.activeQueries }),
        ]));

        var meta = [];
        if (state.form.completedBy) meta.push('Marked SDV complete by ' + state.form.completedBy + ' ' + state.form.completedAt);
        else if (state.form.updatedBy) meta.push('Last SDV activity by ' + state.form.updatedBy + ' ' + state.form.updatedAt);
        if (state.form.reason) meta.push('Reason: ' + state.form.reason);
        if (meta.length) body.appendChild(el('div', { class: 'cctc-sdv-meta', text: meta.join(' · ') }));

        state.notices.forEach(function (n) {
            body.appendChild(el('div', { class: 'yellow cctc-sdv-notice', text: n }));
        });

        body.appendChild(el('div', { id: 'cctc-sdv-message', class: 'cctc-sdv-message', style: 'display:none' }));

        // monitor actions
        var actions = el('div', { class: 'cctc-sdv-actions' });
        if (state.role.monitor) {
            if (state.form.open) {
                actions.appendChild(button('Verify all required fields', function () { call('verifyAllRequired'); }, 'btn-defaultrc', { id: 'cctc-sdv-verify-all' }));
                actions.appendChild(button('Mark SDV complete', function () { call('completeForm'); }, 'btn-success', { id: 'cctc-sdv-complete' }));
                actions.appendChild(button('Mark SDV not required', function () { showOnly('cctc-sdv-not-required'); }, 'btn-defaultrc', { id: 'cctc-sdv-not-required-btn' }));
            } else {
                actions.appendChild(button('Reopen SDV', function () { showOnly('cctc-sdv-reopen'); }, 'btn-defaultrc', { id: 'cctc-sdv-reopen-btn' }));
            }
        }
        actions.appendChild(button(historyShown ? 'Hide history' : 'Show history', toggleHistory, 'btn-defaultrc', { id: 'cctc-sdv-history-btn' }));
        body.appendChild(actions);

        if (state.role.monitor) {
            body.appendChild(reasonForm('cctc-sdv-not-required', 'Reason SDV is not required for this form (required)', 'Confirm not required',
                function (reason) { call('markNotRequired', { reason: reason }); }));
            body.appendChild(reasonForm('cctc-sdv-reopen', 'Reason for reopening SDV on this form (required)', 'Confirm reopen',
                function (reason) { call('reopenForm', { reason: reason }); }));
        }

        if (state.queries.length) body.appendChild(renderQueries());

        var hist = el('div', { id: 'cctc-sdv-history', class: 'cctc-sdv-history', style: historyShown ? '' : 'display:none' });
        body.appendChild(hist);
        if (historyShown) loadHistory();
    }

    function messageLine(m) {
        var verb = { raise: 'Raised', reraise: 'Re-raised', respond: 'Responded', accept: 'Accepted', close: 'Closed' }[m.action] || m.action;
        var text = verb + ' by ' + m.user + ' ' + m.ts;
        var detail = [];
        if (m.code) detail.push(m.code);
        if (m.text) detail.push(m.text);
        return el('div', { class: 'cctc-sdv-msg cctc-sdv-msg-' + m.action }, [
            el('div', { class: 'cctc-sdv-msg-head', text: text }),
            detail.length ? el('div', { class: 'cctc-sdv-msg-text', text: detail.join(': ') }) : null,
        ]);
    }

    function renderQueries() {
        var table = el('table', { class: 'cctc-sdv-table', id: 'cctc-sdv-queries' });
        table.appendChild(el('tr', {}, [
            el('th', { text: state.settings.includeFieldLabel ? 'Field [label]' : 'Field' }),
            el('th', { text: 'Current value' }),
            el('th', { text: 'Query' }),
            el('th', { text: 'Action' }),
        ]));

        state.queries.forEach(function (q) {
            var actionCell = el('td', { class: 'cctc-sdv-query-action' });
            var monitor = state.role.monitor;

            if (monitor && q.status === 'responded') {
                var verifyBox = el('input', { type: 'checkbox', id: 'cctc-sdv-accept-verify-' + q.id, checked: true });
                var lastQuery = '';
                q.messages.forEach(function (m) { if ((m.action === 'raise' || m.action === 'reraise') && m.text) lastQuery = m.text; });
                var reraiseText = el('textarea', { class: 'x-form-text x-form-field cctc-sdv-textarea', rows: '2', id: 'cctc-sdv-reraise-text-' + q.id });
                reraiseText.value = lastQuery;
                actionCell.appendChild(el('div', { class: 'cctc-sdv-row' }, [
                    button('Accept', function () { call('acceptQuery', { queryId: q.id, verify: verifyBox.checked }); }, 'btn-success', { id: 'cctc-sdv-accept-' + q.id }),
                    el('label', { class: 'cctc-sdv-small', for: 'cctc-sdv-accept-verify-' + q.id }, [verifyBox, ' and verify field']),
                ]));
                actionCell.appendChild(reraiseText);
                actionCell.appendChild(el('div', { class: 'cctc-sdv-row' }, [
                    button('Re-raise', function () { call('reraiseQuery', { queryId: q.id, text: reraiseText.value }); }, 'btn-defaultrc', { id: 'cctc-sdv-reraise-' + q.id }),
                    button('Close query', function () { call('closeQuery', { queryId: q.id }); }, 'btn-link cctc-sdv-link', { id: 'cctc-sdv-close-' + q.id }),
                ]));
            } else if (monitor && q.status === 'open') {
                actionCell.appendChild(el('div', { class: 'cctc-sdv-small', text: 'Awaiting site response' }));
                actionCell.appendChild(button('Close query', function () { call('closeQuery', { queryId: q.id }); }, 'btn-link cctc-sdv-link', { id: 'cctc-sdv-close-' + q.id }));
            } else if (state.role.canRespond && q.status === 'open') {
                var sel = el('select', { class: 'x-form-text x-form-field cctc-sdv-response', id: 'cctc-sdv-response-' + q.id, 'aria-label': 'Response' },
                    [el('option', { value: '', text: '-- choose response --' })].concat(state.responseCodes.map(function (code) {
                        return el('option', { value: code, text: code });
                    })));
                var comment = el('textarea', { class: 'x-form-text x-form-field cctc-sdv-textarea', rows: '2', placeholder: 'Comment (optional)', id: 'cctc-sdv-response-comment-' + q.id });
                actionCell.appendChild(sel);
                actionCell.appendChild(comment);
                actionCell.appendChild(button('Send response', function () {
                    call('respondQuery', { queryId: q.id, code: sel.value, comment: comment.value });
                }, 'btn-primaryrc', { id: 'cctc-sdv-respond-' + q.id }));
            } else {
                actionCell.appendChild(el('div', { class: 'cctc-sdv-small', text: q.statusLabel }));
            }

            table.appendChild(el('tr', { 'data-query-id': String(q.id), 'data-field': q.field }, [
                el('td', { text: fieldText(q.field) }),
                el('td', { class: 'cctc-sdv-value', text: q.value || '(blank)' }),
                el('td', {}, [el('div', { class: 'cctc-sdv-small', text: q.statusLabel })].concat(q.messages.map(messageLine))),
                actionCell,
            ]));
        });
        return table;
    }

    // ---- history (loaded on demand) --------------------------------------------------------------

    var ACTION_LABELS = {
        field_verified: 'Field verified', field_unverified: 'Field verification removed', field_changed: 'Verified value changed',
        form_status: 'Form status changed', form_complete: 'Form marked SDV complete', form_not_required: 'Form marked SDV not required',
        form_reopened: 'Form reopened', form_reverify: 'Re-verification required', query_raised: 'Query raised',
        query_responded: 'Query responded', query_reraised: 'Query re-raised', query_accepted: 'Query accepted', query_closed: 'Query closed',
        query_raise_text: 'Query text', query_reraise_text: 'Re-raised query text', query_respond_text: 'Response',
        query_close_text: 'Close reason', query_accept_text: 'Accept note'
    };

    function toggleHistory() {
        historyShown = !historyShown;
        var btn = document.getElementById('cctc-sdv-history-btn');
        var box = document.getElementById('cctc-sdv-history');
        if (btn) btn.textContent = historyShown ? 'Hide history' : 'Show history';
        if (box) box.style.display = historyShown ? '' : 'none';
        if (historyShown) loadHistory();
    }

    function loadHistory() {
        var box = document.getElementById('cctc-sdv-history');
        if (!box) return;
        box.textContent = 'Loading history...';
        jsModule.ajax('history', { ctx: state.ctx }).then(function (resp) {
            box.textContent = '';
            if (!resp || !resp.ok) { box.textContent = (resp && resp.error) || 'Could not load history.'; return; }
            if (!resp.history.length) { box.textContent = 'No SDV activity yet.'; return; }
            var table = el('table', { class: 'cctc-sdv-table', id: 'cctc-sdv-history-table' }, [
                el('tr', {}, ['When', 'User', 'Action', 'Field', 'Status', 'Detail'].map(function (h) { return el('th', { text: h }); })),
            ]);
            resp.history.forEach(function (h) {
                table.appendChild(el('tr', {}, [
                    el('td', { text: h.ts }), el('td', { text: h.user }), el('td', { text: ACTION_LABELS[h.action] || h.action }),
                    el('td', { text: h.field || '' }), el('td', { text: h.change }), el('td', { text: h.detail }),
                ]));
            });
            box.appendChild(table);
        });
    }

    // ---- entry point -----------------------------------------------------------------------------

    function render() {
        renderBadges();
        renderPanel();
    }

    function init(module, initialState, ui) {
        jsModule = module;
        state = initialState;
        onReady(function () {
            // place the panel directly below the form table
            var panel = document.getElementById('cctc-sdv-panel');
            var formTable = document.getElementById('questiontable');
            if (panel && formTable && formTable.parentNode) formTable.parentNode.insertBefore(panel, formTable.nextSibling);
            applyUi(ui);
            render();
        });
    }

    return { init: init };
})();
