@push('styles')
<style>
    .cr-modal-overlay {
        position: fixed;
        inset: 0;
        z-index: 1400;
        background: rgba(15, 23, 42, 0.55);
        backdrop-filter: blur(3px);
        -webkit-backdrop-filter: blur(3px);
        display: flex;
        align-items: flex-start;
        justify-content: center;
        padding: 20px;
        overflow: hidden;
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.25s ease, visibility 0.25s ease;
    }
    .cr-modal-overlay.open { opacity: 1; visibility: visible; }
    .cr-modal-shell {
        width: 100%;
        max-width: 760px;
        max-height: 90vh;
        max-height: min(90vh, calc(100vh - 40px));
        max-height: min(90dvh, calc(100dvh - 40px));
        background: #ffffff;
        border-radius: 14px;
        box-shadow: 0 24px 70px rgba(2, 6, 23, 0.35);
        display: flex;
        flex-direction: column;
        overflow: hidden;
        transform: translateY(28px) scale(0.985);
        opacity: 0;
        transition: transform 0.28s cubic-bezier(0.2, 0.8, 0.25, 1), opacity 0.2s ease;
    }
    .cr-modal-overlay.open .cr-modal-shell { transform: none; opacity: 1; }
    .cr-modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 16px 24px;
        border-bottom: 1px solid var(--border);
        background: linear-gradient(180deg, #ffffff, #f9fbfd);
        flex-shrink: 0;
    }
    .cr-modal-header h2 {
        font-size: 18px;
        font-weight: 700;
        color: var(--text);
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0;
    }
    .cr-modal-header h2 i { color: var(--primary); }
    .cr-modal-subtitle { font-size: 12.5px; color: var(--text-muted); margin-top: 3px; line-height: 1.5; }
    .cr-modal-close {
        background: none;
        border: none;
        cursor: pointer;
        color: var(--text-muted);
        font-size: 18px;
        width: 36px;
        height: 36px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        transition: all 0.15s;
    }
    .cr-modal-close:hover { background: #fee2e2; color: var(--danger); }
    .cr-modal-shell > form {
        display: flex;
        flex-direction: column;
        flex: 1;
        min-height: 0;
    }
    .cr-modal-body {
        flex: 1;
        min-height: 0;
        overflow-y: auto;
        overflow-x: hidden;
        overscroll-behavior: contain;
        padding: 20px 24px;
        background: #f8fafc;
        -webkit-overflow-scrolling: touch;
    }
    .cr-modal-footer {
        flex-shrink: 0;
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 12px;
        padding: 14px 24px;
        border-top: 1px solid var(--border);
        background: #ffffff;
    }
    body.modal-open { overflow: hidden; }

    .cr-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 14px; }
    .cr-grid .cr-full { grid-column: 1 / -1; }
    .cr-grid .form-group { margin-bottom: 0; min-width: 0; }
    .cr-grid .form-control { min-width: 0; }

    .cr-warning {
        margin-bottom: 16px;
        padding: 12px 14px;
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-radius: 8px;
        font-size: 13px;
        color: #7f1d1d;
        line-height: 1.6;
    }
    .cr-warning label { display: flex; align-items: flex-start; gap: 8px; margin: 10px 0 0; font-weight: 600; cursor: pointer; }
    .cr-warning input[type="checkbox"] { margin-top: 3px; accent-color: var(--danger); width: 16px; height: 16px; }

    .cr-summary {
        margin-top: 16px;
        padding: 12px 14px;
        background: #fffbeb;
        border: 1px solid #fde68a;
        border-radius: 8px;
        font-size: 12.5px;
        color: #92400e;
        line-height: 1.6;
        display: none;
    }
    .cr-summary strong { color: #78350f; }

    .cr-note {
        margin-top: 14px;
        padding: 10px 14px;
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
        border-radius: 8px;
        font-size: 12.5px;
        color: #166534;
        line-height: 1.6;
    }

    .cr-field-error { color: var(--danger); font-size: 11.5px; margin-top: 4px; line-height: 1.4; }
    .cr-form-error {
        color: var(--danger);
        font-size: 13px;
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-radius: 8px;
        padding: 10px 14px;
        margin-bottom: 16px;
    }
    .cr-modal-body .is-invalid {
        border-color: var(--danger) !important;
        background: #fff5f5 !important;
    }

    .cr-item-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 150px 120px 110px;
        gap: 12px;
        align-items: end;
        padding: 14px;
        border: 1px solid var(--border);
        border-radius: 10px;
        background: #ffffff;
        margin-bottom: 12px;
    }
    .cr-item-row > div { min-width: 0; }
    .cr-item-row .form-control { min-width: 0; }
    .cr-item-row .cr-qty { text-align: right; }
    .cr-item-row .cr-num { text-align: right; }
    .cr-locked-tag {
        display: inline-block;
        font-size: 10.5px;
        background: #eef2ff;
        color: #4f46e5;
        padding: 1px 7px;
        border-radius: 999px;
        margin-left: 6px;
        font-weight: 600;
    }
    .cr-static-item {
        font-size: 13.5px;
        font-weight: 600;
        padding: 8px 0 2px;
    }

    @media (max-width: 640px) {
        .cr-modal-overlay { padding: 10px; }
        .cr-modal-shell { width: 100%; max-height: 90vh; max-height: min(90vh, calc(100vh - 20px)); max-height: min(90dvh, calc(100dvh - 20px)); }
        .cr-modal-header { padding: 12px 16px; }
        .cr-modal-body { padding: 14px; }
        .cr-modal-footer { padding: 12px 16px; }
        .cr-grid { grid-template-columns: 1fr; }
        .cr-item-row { grid-template-columns: 1fr; align-items: start; gap: 8px; }
        .cr-item-row .cr-qty,
        .cr-item-row .cr-num { text-align: left; }
    }
</style>
@endpush

<div class="cr-modal-overlay" id="correctRisModal">
    <div class="cr-modal-shell" role="dialog" aria-modal="true" aria-labelledby="correctRisTitle">
        <div class="cr-modal-header">
            <div style="min-width:0">
                <h2 id="correctRisTitle"><i class="fas fa-sync-alt"></i> Correct RIS <span id="cr-ris-ref" style="color:var(--text-muted);font-weight:600"></span></h2>
                <div class="cr-modal-subtitle">
                    Fix the request itself — header and requested quantities. Existing
                    dispatches, DR numbers and stock deductions stay untouched.
                </div>
            </div>
            <button type="button" class="cr-modal-close" onclick="closeCorrectRisModal()" aria-label="Close"><i class="fas fa-times"></i></button>
        </div>

        <form id="correct-ris-form" method="POST" novalidate>
            @csrf
            <input type="hidden" name="_method" value="PUT">
            <div class="cr-modal-body">
                <div id="cr-top-error" class="cr-form-error" style="display:none"></div>

                <div class="cr-warning" id="cr-completed-warning" style="display:none">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>This RIS has already been issued.</strong>
                    Changing the requested quantity may change the issuance status
                    (e.g. a completed RIS can become "Partially Fulfilled") and reopen
                    the line for further issuing. Already-issued stock and dispatch
                    records are <strong>never</strong> automatically altered.
                    <label>
                        <input type="checkbox" id="cr-acknowledge">
                        <span>I understand — I only want to correct the request details, not change issued stock.</span>
                    </label>
                </div>

                <h3 style="font-size:13px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin:0 0 12px">RIS Header</h3>
                <div class="cr-grid">
                    <div class="form-group">
                        <label class="form-label">RIS ID <span style="font-size:10px;color:var(--text-muted)">system, not editable</span></label>
                        <input type="text" id="cr-ris-code" class="form-control" readonly style="background:var(--surface-soft);font-family:monospace;color:var(--primary);font-weight:700">
                    </div>
                    <div class="form-group">
                        <label class="form-label">RIS No. <span class="req">*</span></label>
                        <input type="text" name="ris_number" id="cr-ris-number" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Date Requested <span class="req">*</span></label>
                        <input type="date" name="date_requested" id="cr-date-requested" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Entity Name</label>
                        <input type="text" name="entity_name" id="cr-entity-name" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Fund Cluster</label>
                        <input type="text" name="fund_cluster" id="cr-fund-cluster" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Office</label>
                        <input type="text" name="office" id="cr-office" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Division</label>
                        <input type="text" name="division" id="cr-division" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Province</label>
                        <input type="text" name="province" id="cr-province" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Municipality</label>
                        <input type="text" name="municipality" id="cr-municipality" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Resp. Center Code</label>
                        <input type="text" name="responsibility_center_code" id="cr-rc-code" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Requested By</label>
                        <input type="text" name="requested_by_name" id="cr-req-by" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Requested By Designation</label>
                        <input type="text" name="requested_by_designation" id="cr-req-desig" class="form-control">
                    </div>
                    <div class="form-group cr-full">
                        <label class="form-label">Purpose <span class="req">*</span></label>
                        <textarea name="purpose" id="cr-purpose" class="form-control" rows="2" required></textarea>
                    </div>
                </div>

                <h3 style="font-size:13px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin:20px 0 12px">Requested Items</h3>
                <div id="cr-items"></div>

                <div class="cr-note">
                    <i class="fas fa-shield-alt"></i>
                    <strong>Inventory protection:</strong> this correction only changes the request.
                    Issued stock, dispatch records (warehouse, quantity, unit cost, ENGAS, DR, expiry)
                    and stock cards are left exactly as they are. To correct an issued shipment, use
                    the <em>Edit Dispatch</em> action in the Partial Delivery Breakdown.
                </div>

                <div class="cr-summary" id="cr-summary"></div>
            </div>

            <div class="cr-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeCorrectRisModal()"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" id="cr-save-btn" class="btn btn-primary" style="min-width:180px;justify-content:center"><i class="fas fa-save"></i> Save Correction</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
(function () {
    'use strict';

    var STATE      = null;   // correction data from the correction-data endpoint
    var CR_SAVING  = false;
    var CR_ORIG    = null;   // { totals, rows: [{ description, qty, catalog_item_id }] }

    var $ = function (id) { return document.getElementById(id); };

    function openModal() {
        var m = $('correctRisModal');
        if (!m) return;
        m.classList.add('open');
        document.body.classList.add('modal-open');
        document.addEventListener('keydown', onEsc);
    }

    function closeModal() {
        var m = $('correctRisModal');
        if (!m) return;
        m.classList.remove('open');
        document.body.classList.remove('modal-open');
        document.removeEventListener('keydown', onEsc);
    }

    function onEsc(e) {
        if (e.key === 'Escape' && !CR_SAVING) closeModal();
    }

    function clearErrors() {
        document.querySelectorAll('#correct-ris-form .cr-field-error').forEach(function (el) { el.remove(); });
        document.querySelectorAll('#correct-ris-form .is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
        var top = $('cr-top-error');
        if (top) { top.style.display = 'none'; top.className = 'cr-form-error'; top.innerHTML = ''; }
    }

    function showErrors(errors) {
        clearErrors();
        var form = $('correct-ris-form');
        Object.keys(errors).forEach(function (key) {
            var el = form.querySelector('[name="' + key + '"]');
            if (el) {
                el.classList.add('is-invalid');
                var div = document.createElement('div');
                div.className = 'cr-field-error';
                div.textContent = errors[key][0];
                el.parentElement.appendChild(div);
            } else {
                var top = $('cr-top-error');
                if (top) {
                    top.style.display = 'block';
                    var msg = document.createElement('div');
                    msg.textContent = errors[key][0];
                    top.appendChild(msg);
                }
            }
        });
    }

    function fmt(n) {
        return Number(n).toLocaleString('en-PH', { maximumFractionDigits: 0 });
    }

    function itemOptionsHtml(selectedId) {
        return '<option value="">— Select Item —</option>' +
            (STATE.catalog_items || []).map(function (c) {
                return '<option value="' + c.id + '"' +
                    (String(c.id) === String(selectedId) ? ' selected' : '') +
                    ' data-unit="' + (c.unit || '') + '"' +
                    ' data-account-code="' + (c.account_code || '') + '"' +
                    '>' + c.name +
                    (c.account_code ? ' · ' + c.account_code : '') +
                    (c.total_stock > 0 ? ' · ' + fmt(c.total_stock) + ' ' + (c.unit || '') + ' available' : '') +
                    '</option>';
            }).join('');
    }

    function buildItems() {
        var wrap = $('cr-items');
        var html = '';

        STATE.items.forEach(function (ri, i) {
            html += '<div class="cr-item-row" id="cr-row-' + i + '">';
            html += '<div>';
            html += '<label class="form-label">Item Description</label>';
            if (ri.locked) {
                html += '<input type="hidden" name="items[' + i + '][id]" value="' + ri.id + '">';
                html += '<input type="hidden" name="items[' + i + '][catalog_item_id]" value="' + (ri.catalog_item_id || '') + '">';
                html += '<div class="cr-static-item">' + (ri.description || '—')
                    + '<span class="cr-locked-tag" title="This line has already been issued — its item cannot be changed"><i class="fas fa-lock"></i> locked</span></div>';
                html += '<small style="color:var(--text-muted);font-size:11px">' + (ri.unit || '') + ' · ' + (ri.account_code || '') + '</small>';
            } else {
                html += '<input type="hidden" name="items[' + i + '][id]" value="' + ri.id + '">';
                html += '<select name="items[' + i + '][catalog_item_id]" id="cr-cat-' + i + '" class="form-control" onchange="crItemChanged(' + i + ')">'
                    + itemOptionsHtml(ri.catalog_item_id) + '</select>';
            }
            html += '</div>';

            html += '<div>';
            html += '<label class="form-label" style="white-space:nowrap">Requested Qty</label>';
            html += '<input type="number" name="items[' + i + '][quantity_requested]" id="cr-qty-' + i + '"'
                + ' class="form-control cr-qty" min="' + (ri.locked ? ri.quantity_issued : 1) + '" step="1"'
                + ' value="' + ri.quantity_requested + '" required oninput="crItemChanged(' + i + ')">';
            if (ri.locked) {
                html += '<small style="color:var(--text-muted);font-size:11px">Cannot go below ' + fmt(ri.quantity_issued) + ' (already issued)</small>';
            }
            html += '</div>';

            html += '<div class="cr-num">';
            html += '<label class="form-label">Issued</label>';
            html += '<div style="font-size:15px;font-weight:700;color:var(--success);padding:8px 2px">' + fmt(ri.quantity_issued) + '</div>';
            html += '</div>';

            html += '<div class="cr-num">';
            html += '<label class="form-label">Outstanding</label>';
            html += '<div id="cr-out-' + i + '" style="font-size:15px;font-weight:700;padding:8px 2px"></div>';
            html += '</div>';

            html += '</div>';
        });

        wrap.innerHTML = html;
    }

    function currentRows() {
        return STATE.items.map(function (ri, i) {
            var qtyInput = $('cr-qty-' + i);
            var catSel   = $('cr-cat-' + i);
            return {
                qty: qtyInput ? parseFloat(qtyInput.value) || 0 : ri.quantity_requested,
                cat: catSel ? catSel.value : ri.catalog_item_id,
            };
        });
    }

    function buildSummary() {
        var summary = $('cr-summary');
        if (!summary || !STATE || !CR_ORIG) return;

        var rows = currentRows();
        var newTotal = rows.reduce(function (s, r) { return s + r.qty; }, 0);

        var lines = [];

        if (Math.abs(newTotal - CR_ORIG.totals.requested) > 0.0001) {
            lines.push('<strong>Total requested:</strong> ' + fmt(CR_ORIG.totals.requested)
                + ' → ' + fmt(newTotal));
        }

        STATE.items.forEach(function (ri, i) {
            var orig = CR_ORIG.rows[i];
            if (!orig) return;
            if (Math.abs(rows[i].qty - orig.qty) > 0.0001) {
                lines.push('<strong>Qty for "'
                    + (STATE.catalog_items.find(function (c) { return String(c.id) === String(rows[i].cat); })?.name || orig.desc)
                    + '":</strong> ' + fmt(orig.qty) + ' → ' + fmt(rows[i].qty)
                    + (rows[i].qty > orig.qty ? ' <span style="color:var(--success)">(reopens ' + fmt(rows[i].qty - orig.qty) + ' outstanding)</span>' : ''));
            }
            if (String(rows[i].cat) !== String(orig.cat)) {
                lines.push('<strong>Item change:</strong> ' + (orig.desc || '—') + ' → '
                    + (STATE.catalog_items.find(function (c) { return String(c.id) === String(rows[i].cat); })?.name || '—'));
            }
        });

        if (lines.length > 0) {
            summary.innerHTML = '<i class="fas fa-info-circle"></i> Changes to be applied:<br>' +
                lines.map(function (l) { return '<span style="display:block;margin-top:2px">• ' + l + '</span>'; }).join('')
                + '<br><span style="display:block;margin-top:6px"><i class="fas fa-shield-alt"></i> Dispatches, DR numbers and stock deductions are <strong>not</strong> changed.</span>';
            summary.style.display = 'block';
        } else {
            summary.style.display = 'none';
        }
    }

    window.crItemChanged = function () {
        STATE.items.forEach(function (ri, i) {
            var out = $('cr-out-' + i);
            if (!out) return;
            var qty = $('cr-qty-' + i) ? parseFloat($('cr-qty-' + i).value) || 0 : ri.quantity_requested;
            var o = Math.max(0, qty - ri.quantity_issued);
            out.innerHTML = o > 0 ? fmt(o) + ' <span style="font-size:11px;color:var(--warning)">units</span>' : '✓ Fulfilled';
            out.style.color = o > 0 ? 'var(--warning)' : 'var(--success)';
        });
        buildSummary();
    };

    window.openCorrectRisModal = function () {
        if (CR_SAVING) return;
        clearErrors();
        $('cr-summary').style.display = 'none';

        fetch('{{ route("requisitions.correction_data", ["requisition" => $requisition->id]) }}', {
            headers: { 'Accept': 'application/json' },
        })
            .then(function (res) { if (!res.ok) throw new Error('HTTP ' + res.status); return res.json(); })
            .then(function (data) {
                STATE = data;

                $('cr-ris-ref').textContent = (data.ris_code || data.ris_id || '') + ' / ' + (data.ris_number || '');
                $('correct-ris-form').action = '{{ route("requisitions.correct", ["requisition" => $requisition->id]) }}';

                $('cr-ris-code').value = data.ris_code || data.ris_id || '';
                $('cr-ris-number').value = data.ris_number || '';
                $('cr-date-requested').value = data.date_requested || '';
                $('cr-entity-name').value = data.entity_name || '';
                $('cr-fund-cluster').value = data.fund_cluster || '';
                $('cr-office').value = data.office || '';
                $('cr-division').value = data.division || '';
                $('cr-province').value = data.province || '';
                $('cr-municipality').value = data.municipality || '';
                $('cr-rc-code').value = data.responsibility_center_code || '';
                $('cr-req-by').value = data.requested_by_name || '';
                $('cr-req-desig').value = data.requested_by_designation || '';
                $('cr-purpose').value = data.purpose || '';

                var ack = $('cr-acknowledge');
                ack.checked = false;
                $('cr-completed-warning').style.display = data.is_completed ? 'block' : 'none';
                $('cr-save-btn').disabled = data.is_completed;

                CR_ORIG = {
                    totals: data.totals,
                    rows: data.items.map(function (ri) {
                        return { qty: ri.quantity_requested, desc: ri.description, cat: ri.catalog_item_id };
                    }),
                };

                buildItems();
                crItemChanged();
                openModal();
            })
            .catch(function () {
                alert('Could not load the RIS data. Please try again.');
            });
    };

    window.closeCorrectRisModal = closeModal;

    $('cr-acknowledge').addEventListener('change', function () {
        $('cr-save-btn').disabled = !this.checked;
    });

    var form = $('correct-ris-form');
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!STATE || CR_SAVING) return;

            if (STATE.is_completed && !$('cr-acknowledge').checked) {
                $('cr-acknowledge').scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }

            var msg = 'Save this correction?\n\n'
                + 'The request will be corrected and the RIS status recalculated.\n'
                + 'Dispatches, DR numbers and inventory are NOT changed.\n\n'
                + 'Continue?';
            if (!window.confirm(msg)) return;

            CR_SAVING = true;
            var btn = form.querySelector('button[type="submit"]');
            var orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            clearErrors();

            var fd = new FormData(form);

            fetch(form.action, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')
                        ? document.querySelector('meta[name="csrf-token"]').content
                        : ''
                },
                body: fd
            })
                .then(function (res) { return res.json().then(function (d) { return { ok: res.ok, d: d }; }); })
                .then(function (r) {
                    CR_SAVING = false;
                    btn.disabled = false;
                    btn.innerHTML = orig;
                    if (STATE.is_completed && !$('cr-acknowledge').checked) btn.disabled = true;
                    if (r.ok && r.d.redirect) {
                        window.location.href = r.d.redirect;
                        return;
                    }
                    if (r.d.errors) showErrors(r.d.errors);
                })
                .catch(function () {
                    CR_SAVING = false;
                    btn.disabled = false;
                    btn.innerHTML = orig;
                    if (STATE.is_completed && !$('cr-acknowledge').checked) btn.disabled = true;
                    alert('Update failed. Please try again.');
                });
        });
    }
})();
</script>
@endpush
