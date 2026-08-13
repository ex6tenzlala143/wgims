@php
    $unitOptionsHtml = '<option value="">—</option>';
    foreach (App\Models\Item::UNITS as $uKey => $uLabel) {
        $unitOptionsHtml .= '<option value="' . $uKey . '">' . $uLabel . '</option>';
    }

    $catOptionsHtml = '<option value="">—</option>';
    foreach (App\Models\Item::getCategories() as $cKey => $cCat) {
        $catOptionsHtml .= '<option value="' . $cKey . '">' . $cCat['label'] . '</option>';
    }
@endphp

@push('styles')
<style>
    .cs-modal-overlay {
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
    .cs-modal-overlay.open { opacity: 1; visibility: visible; }
    .cs-modal-shell {
        width: 100%;
        max-width: 760px;
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
    .cs-modal-overlay.open .cs-modal-shell { transform: none; opacity: 1; }
    .cs-modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 16px 24px;
        border-bottom: 1px solid var(--border);
        background: linear-gradient(180deg, #ffffff, #f9fbfd);
        flex-shrink: 0;
    }
    .cs-modal-header h2 {
        font-size: 18px;
        font-weight: 700;
        color: var(--text);
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0;
    }
    .cs-modal-header h2 i { color: var(--primary); }
    .cs-modal-subtitle { font-size: 12.5px; color: var(--text-muted); margin-top: 3px; line-height: 1.5; }
    .cs-modal-close {
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
    .cs-modal-close:hover { background: #fee2e2; color: var(--danger); }
    .cs-modal-shell > form {
        display: flex;
        flex-direction: column;
        flex: 1;
        min-height: 0;
    }
    .cs-modal-body {
        flex: 1;
        min-height: 0;
        overflow-y: auto;
        overflow-x: hidden;
        overscroll-behavior: contain;
        padding: 20px 24px;
        background: #f8fafc;
        -webkit-overflow-scrolling: touch;
    }
    .cs-modal-footer {
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

    .cs-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 14px; }
    .cs-grid .cs-full { grid-column: 1 / -1; }
    .cs-grid .form-group { margin-bottom: 0; min-width: 0; }
    .cs-grid .form-control { min-width: 0; }

    .cs-warning {
        margin-bottom: 16px;
        padding: 12px 14px;
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-radius: 8px;
        font-size: 13px;
        color: #7f1d1d;
        line-height: 1.6;
    }
    .cs-warning label { display: flex; align-items: flex-start; gap: 8px; margin: 10px 0 0; font-weight: 600; cursor: pointer; }
    .cs-warning input[type="checkbox"] { margin-top: 3px; accent-color: var(--danger); width: 16px; height: 16px; }

    .cs-summary {
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
    .cs-summary strong { color: #78350f; }

    .cs-note {
        margin-top: 14px;
        padding: 10px 14px;
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
        border-radius: 8px;
        font-size: 12.5px;
        color: #166534;
        line-height: 1.6;
    }

    .cs-field-error { color: var(--danger); font-size: 11.5px; margin-top: 4px; line-height: 1.4; }
    .cs-form-error {
        color: var(--danger);
        font-size: 13px;
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-radius: 8px;
        padding: 10px 14px;
        margin-bottom: 16px;
    }
    .cs-modal-body .is-invalid {
        border-color: var(--danger) !important;
        background: #fff5f5 !important;
    }

    .cs-item-row {
        display: grid;
        grid-template-columns: minmax(0, 1.6fr) 150px 120px 110px;
        gap: 12px;
        align-items: end;
        padding: 14px;
        border: 1px solid var(--border);
        border-radius: 10px;
        background: #ffffff;
        margin-bottom: 12px;
    }
    .cs-item-row > div { min-width: 0; }
    .cs-item-row .form-control { min-width: 0; }
    .cs-item-row .cs-qty { text-align: right; }
    .cs-item-row .cs-num { text-align: right; }
    .cs-locked-tag {
        display: inline-block;
        font-size: 10.5px;
        background: #eef2ff;
        color: #4f46e5;
        padding: 1px 7px;
        border-radius: 999px;
        margin-left: 6px;
        font-weight: 600;
    }
    .cs-static-item {
        font-size: 13.5px;
        font-weight: 600;
        padding: 8px 0 2px;
    }

    @media (max-width: 640px) {
        .cs-modal-overlay { padding: 10px; }
        .cs-modal-shell { width: 100%; max-height: 90dvh; max-height: min(90dvh, calc(100dvh - 20px)); }
        .cs-modal-header { padding: 12px 16px; }
        .cs-modal-body { padding: 14px; }
        .cs-modal-footer { padding: 12px 16px; }
        .cs-grid { grid-template-columns: 1fr; }
        .cs-item-row { grid-template-columns: 1fr; align-items: start; gap: 8px; }
        .cs-item-row .cs-qty,
        .cs-item-row .cs-num { text-align: left; }
    }
</style>
@endpush

<div class="cs-modal-overlay" id="correctSubsidyModal">
    <div class="cs-modal-shell" role="dialog" aria-modal="true" aria-labelledby="correctSubsidyTitle">
        <div class="cs-modal-header">
            <div style="min-width:0">
                <h2 id="correctSubsidyTitle"><i class="fas fa-sync-alt"></i> Correct Subsidy <span id="cs-ris-ref" style="color:var(--text-muted);font-weight:600"></span></h2>
                <div class="cs-modal-subtitle">
                    Fix the request itself — header and requested quantities. Existing
                    shipments, DR numbers and stock on hand stay untouched.
                </div>
            </div>
            <button type="button" class="cs-modal-close" onclick="closeCorrectSubsidyModal()" aria-label="Close"><i class="fas fa-times"></i></button>
        </div>

        <form id="correct-subsidy-form" method="POST" novalidate>
            @csrf
            <input type="hidden" name="_method" value="PUT">
            <div class="cs-modal-body">
                <div id="cs-top-error" class="cs-form-error" style="display:none"></div>

                <div class="cs-warning" id="cs-completed-warning" style="display:none">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>This subsidy has already been fully delivered.</strong>
                    Changing the requested quantity may change the delivery status
                    (e.g. a fully delivered subsidy can become "Partial Delivery") and
                    reopen the line for further shipments. Already-delivered stock and
                    shipment records are <strong>never</strong> automatically altered.
                    <label>
                        <input type="checkbox" id="cs-acknowledge">
                        <span>I understand — I only want to correct the request details, not change delivered stock.</span>
                    </label>
                </div>

                <h3 style="font-size:13px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin:0 0 12px">Subsidy Header</h3>
                <div class="cs-grid">
                    <div class="form-group">
                        <label class="form-label">Date <span style="color:red">*</span></label>
                        <input type="date" name="date" id="cs-date" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Place of Delivery</label>
                        <input type="text" name="place_of_delivery" id="cs-place" class="form-control">
                    </div>
                    <div class="form-group cs-full">
                        <label class="form-label">Remarks</label>
                        <textarea name="remarks" id="cs-remarks" class="form-control" rows="2"></textarea>
                    </div>
                </div>

                <h3 style="font-size:13px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin:20px 0 12px">Ordered Items</h3>
                <div id="cs-items"></div>

                <div class="cs-note">
                    <i class="fas fa-shield-alt"></i>
                    <strong>Inventory protection:</strong> this correction only changes the request.
                    Delivered stock, shipments (warehouse, quantity, unit cost, ENGAS, DR, expiry)
                    and stock cards are left exactly as they are. To correct a shipment, use
                    the <em>Edit Delivery</em> action in the Shipment Records.
                </div>

                <div class="cs-summary" id="cs-summary"></div>
            </div>

            <div class="cs-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeCorrectSubsidyModal()"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" id="cs-save-btn" class="btn btn-primary" style="min-width:180px;justify-content:center"><i class="fas fa-save"></i> Save Correction</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
(function () {
    'use strict';

    var CS_STATE   = null;
    var CS_SAVING  = false;
    var CS_ORIG    = null;   // { totals, rows: [{ desc, qty, cat }] }

    var csUnitOpts = {!! json_encode($unitOptionsHtml) !!};
    var csCatOpts  = {!! json_encode($catOptionsHtml) !!};

    function $cs(id) { return document.getElementById(id); }

    function openModal() {
        var m = $cs('correctSubsidyModal');
        if (!m) return;
        m.classList.add('open');
        document.body.classList.add('modal-open');
        document.addEventListener('keydown', onEsc);
    }

    function closeModal() {
        var m = $cs('correctSubsidyModal');
        if (!m) return;
        m.classList.remove('open');
        document.body.classList.remove('modal-open');
        document.removeEventListener('keydown', onEsc);
    }

    function onEsc(e) {
        if (e.key === 'Escape' && !CS_SAVING) closeModal();
    }

    function clearErrors() {
        document.querySelectorAll('#correct-subsidy-form .cs-field-error').forEach(function (el) { el.remove(); });
        document.querySelectorAll('#correct-subsidy-form .is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
        var top = $cs('cs-top-error');
        if (top) { top.style.display = 'none'; top.className = 'cs-form-error'; top.innerHTML = ''; }
    }

    function showErrors(errors) {
        clearErrors();
        var form = $cs('correct-subsidy-form');
        Object.keys(errors).forEach(function (key) {
            var el = form.querySelector('[name="' + key + '"]');
            if (el) {
                el.classList.add('is-invalid');
                var div = document.createElement('div');
                div.className = 'cs-field-error';
                div.textContent = errors[key][0];
                el.parentElement.appendChild(div);
            } else {
                var top = $cs('cs-top-error');
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
        return Number(n).toLocaleString('en-PH', { maximumFractionDigits: 2 });
    }

    function csCat(selId) {
        return (CS_STATE.catalog_items || []).find(function (c) { return String(c.id) === String(selId); });
    }

    function itemOptionsHtml(selectedCatId, currentDesc) {
        var opts = '<option value="">— Select Item —</option>';
        if (currentDesc && !CS_STATE.catalog_items.some(function (c) { return String(c.id) === String(selectedCatId); })) {
            opts += '<option value="' + selectedCatId + '" selected>' + currentDesc + '</option>';
        }
        opts += (CS_STATE.catalog_items || []).map(function (c) {
            return '<option value="' + c.id + '"' +
                (String(c.id) === String(selectedCatId) ? ' selected' : '') +
                ' data-unit="' + (c.unit || '') + '"' +
                ' data-account-code="' + (c.account_code || '') + '"' +
                ' data-category="' + (c.category || '') + '"' +
                '>' + c.name +
                (c.account_code ? ' · ' + c.account_code : '') +
                (c.total_stock > 0 ? ' · ' + fmt(c.total_stock) + ' ' + (c.unit || '') + ' available' : '') +
                '</option>';
        }).join('');
        return opts;
    }

    function buildItems() {
        var wrap = $cs('cs-items');
        var html = '';

        CS_STATE.items.forEach(function (ri, i) {
            html += '<div class="cs-item-row" id="cs-row-' + i + '">';

            html += '<div>';
            html += '<label class="form-label">Item Description</label>';
            html += '<input type="hidden" name="items[' + i + '][dsi_id]" value="' + ri.dsi_id + '">';
            html += '<input type="hidden" name="items[' + i + '][item_id]" id="cs-item-id-' + i + '" value="' + (ri.item_id || '') + '">';
            html += '<input type="hidden" name="items[' + i + '][catalog_item_id]" id="cs-cat-id-' + i + '" value="' + (ri.catalog_item_id || '') + '">';
            html += '<input type="hidden" name="items[' + i + '][account_code]" id="cs-acct-' + i + '" value="' + (ri.account_code || '') + '">';

            if (ri.locked) {
                html += '<div class="cs-static-item">' + (ri.description || '—')
                    + '<span class="cs-locked-tag" title="This line has already been delivered — its item cannot be changed"><i class="fas fa-lock"></i> locked</span></div>';
                html += '<small style="color:var(--text-muted);font-size:11px">' + (ri.unit || '') + ' · ' + (ri.account_code || '') + '</small>';
            } else {
                html += '<select name="items[' + i + '][description]" id="cs-desc-' + i + '" class="form-control"'
                    + ' onchange="csItemChanged(' + i + ')" required>'
                    + itemOptionsHtml(ri.catalog_item_id, ri.description) + '</select>';
                html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px">';
                html += '<select name="items[' + i + '][unit]" id="cs-unit-' + i + '" class="form-control" required>' + csUnitOpts + '</select>';
                html += '<select name="items[' + i + '][category]" id="cs-category-' + i + '" class="form-control" required>' + csCatOpts + '</select>';
                html += '</div>';
                html += '<input type="hidden" name="items[' + i + '][expiration_date]" id="cs-expiry-' + i + '" value="' + (ri.expiration_date || '') + '">';
            }
            html += '</div>';

            html += '<div>';
            html += '<label class="form-label" style="white-space:nowrap">Requested Qty</label>';
            html += '<input type="number" name="items[' + i + '][quantity]" id="cs-qty-' + i + '"'
                + ' class="form-control cs-qty" min="' + (ri.locked ? ri.qty_delivered : 0.01) + '" step="0.01"'
                + ' value="' + ri.quantity + '" required oninput="csItemChanged(' + i + ')">';
            html += '<small style="color:var(--text-muted);font-size:11px">' + fmt(ri.qty_delivered) + ' already delivered'
                + (ri.locked ? ' · cannot go below' : '') + '</small>';
            html += '</div>';

            html += '<div class="cs-num">';
            html += '<label class="form-label">Delivered</label>';
            html += '<div style="font-size:15px;font-weight:700;color:var(--success);padding:8px 2px">' + fmt(ri.qty_delivered) + '</div>';
            html += '</div>';

            html += '<div class="cs-num">';
            html += '<label class="form-label">Outstanding</label>';
            html += '<div id="cs-out-' + i + '" style="font-size:15px;font-weight:700;padding:8px 2px"></div>';
            html += '</div>';

            html += '</div>';
        });

        wrap.innerHTML = html;

        // Set select values for unlocked lines (description/unit/category)
        CS_STATE.items.forEach(function (ri, i) {
            if (ri.locked) return;
            var unitSel = $cs('cs-unit-' + i);
            var catSel  = $cs('cs-category-' + i);
            if (unitSel) unitSel.value = ri.unit || '';
            if (catSel) catSel.value = ri.category || '';
        });
    }

    function currentRows() {
        return CS_STATE.items.map(function (ri, i) {
            var qtyInput = $cs('cs-qty-' + i);
            var catSel   = $cs('cs-cat-id-' + i);
            var descSel  = $cs('cs-desc-' + i);
            return {
                qty: qtyInput ? parseFloat(qtyInput.value) || 0 : ri.quantity,
                cat: catSel ? catSel.value : ri.catalog_item_id,
                desc: descSel ? (descSel.options[descSel.selectedIndex] ? descSel.options[descSel.selectedIndex].text : ri.description) : ri.description,
            };
        });
    }

    function buildSummary() {
        var summary = $cs('cs-summary');
        if (!summary || !CS_STATE || !CS_ORIG) return;

        var rows = currentRows();
        var newTotal = rows.reduce(function (s, r) { return s + r.qty; }, 0);

        var lines = [];

        if (Math.abs(newTotal - CS_ORIG.totals.requested) > 0.0001) {
            lines.push('<strong>Total requested:</strong> ' + fmt(CS_ORIG.totals.requested)
                + ' → ' + fmt(newTotal));
        }

        CS_STATE.items.forEach(function (ri, i) {
            var orig = CS_ORIG.rows[i];
            if (!orig) return;
            if (Math.abs(rows[i].qty - orig.qty) > 0.0001) {
                lines.push('<strong>Qty for "'
                    + (rows[i].desc || orig.desc)
                    + '":</strong> ' + fmt(orig.qty) + ' → ' + fmt(rows[i].qty)
                    + (rows[i].qty > orig.qty ? ' <span style="color:var(--success)">(reopens ' + fmt(rows[i].qty - orig.qty) + ' outstanding)</span>' : ''));
            }
            if (String(rows[i].cat) !== String(orig.cat)) {
                lines.push('<strong>Item change:</strong> ' + (orig.desc || '—') + ' → '
                    + (rows[i].desc || '—'));
            }
        });

        if (lines.length > 0) {
            summary.innerHTML = '<i class="fas fa-info-circle"></i> Changes to be applied:<br>' +
                lines.map(function (l) { return '<span style="display:block;margin-top:2px">• ' + l + '</span>'; }).join('')
                + '<br><span style="display:block;margin-top:6px"><i class="fas fa-shield-alt"></i> Shipments, DR numbers and stock on hand are <strong>not</strong> changed.</span>';
            summary.style.display = 'block';
        } else {
            summary.style.display = 'none';
        }
    }

    window.csItemChanged = function (i) {
        // Description select -> sync unit/category/account_code hidden fields
        var descSel = $cs('cs-desc-' + i);
        var catHidden = $cs('cs-cat-id-' + i);
        var itemHidden = $cs('cs-item-id-' + i);
        var acct = $cs('cs-acct-' + i);
        var unitSel = $cs('cs-unit-' + i);
        var catSel = $cs('cs-category-' + i);

        if (descSel) {
            var opt = descSel.options[descSel.selectedIndex];
            var cat = csCat(opt ? opt.value : '');
            if (catHidden) catHidden.value = cat ? cat.id : '';
            if (itemHidden) itemHidden.value = '';  // subsidies resolve via catalog when one is chosen
            if (acct && cat) acct.value = cat.account_code || '';
            if (unitSel && cat && cat.unit) unitSel.value = cat.unit;
            if (catSel && cat && cat.category) catSel.value = cat.category;
        }

        // Recompute outstanding per row
        CS_STATE.items.forEach(function (ri, idx) {
            var out = $cs('cs-out-' + idx);
            if (!out) return;
            var qty = $cs('cs-qty-' + idx) ? parseFloat($cs('cs-qty-' + idx).value) || 0 : ri.quantity;
            var o = Math.max(0, qty - ri.qty_delivered);
            out.innerHTML = o > 0 ? fmt(o) + ' <span style="font-size:11px;color:var(--warning)">units</span>' : '✓ Delivered';
            out.style.color = o > 0 ? 'var(--warning)' : 'var(--success)';
        });

        buildSummary();
    };

    window.openCorrectSubsidyModal = function () {
        if (CS_SAVING) return;
        clearErrors();
        $cs('cs-summary').style.display = 'none';

        fetch('{{ route("delivery_subsidies.correction_data", ["deliverySubsidy" => $deliverySubsidy->id]) }}', {
            headers: { 'Accept': 'application/json' },
        })
            .then(function (res) { if (!res.ok) throw new Error('HTTP ' + res.status); return res.json(); })
            .then(function (data) {
                CS_STATE = data;

                $cs('cs-ris-ref').textContent = '#' + (data.dr_number || data.ris_number);
                $cs('correct-subsidy-form').action = '{{ route("delivery_subsidies.correct", ["deliverySubsidy" => $deliverySubsidy->id]) }}';

                $cs('cs-date').value = data.date || '';
                $cs('cs-place').value = data.place_of_delivery || '';
                $cs('cs-remarks').value = data.remarks || '';

                var ack = $cs('cs-acknowledge');
                ack.checked = false;
                $cs('cs-completed-warning').style.display = data.is_completed ? 'block' : 'none';
                $cs('cs-save-btn').disabled = data.is_completed;

                CS_ORIG = {
                    totals: data.totals,
                    rows: data.items.map(function (ri) {
                        return { qty: ri.quantity, desc: ri.description, cat: ri.catalog_item_id };
                    }),
                };

                buildItems();
                csItemChanged();
                openModal();
            })
            .catch(function () {
                alert('Could not load the subsidy data. Please try again.');
            });
    };

    window.closeCorrectSubsidyModal = closeModal;

    $cs('cs-acknowledge').addEventListener('change', function () {
        $cs('cs-save-btn').disabled = !this.checked;
    });

    var form = $cs('correct-subsidy-form');
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!CS_STATE || CS_SAVING) return;

            if (CS_STATE.is_completed && !$cs('cs-acknowledge').checked) {
                $cs('cs-acknowledge').scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }

            var msg = 'Save this correction?\n\n'
                + 'The request will be corrected and the subsidy status recalculated.\n'
                + 'Shipments, DR numbers and inventory are NOT changed.\n\n'
                + 'Continue?';
            if (!window.confirm(msg)) return;

            CS_SAVING = true;
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
                    CS_SAVING = false;
                    btn.disabled = false;
                    btn.innerHTML = orig;
                    if (CS_STATE.is_completed && !$cs('cs-acknowledge').checked) btn.disabled = true;
                    if (r.ok && r.d.redirect) {
                        window.location.href = r.d.redirect;
                        return;
                    }
                    if (r.d.errors) showErrors(r.d.errors);
                })
                .catch(function () {
                    CS_SAVING = false;
                    btn.disabled = false;
                    btn.innerHTML = orig;
                    if (CS_STATE.is_completed && !$cs('cs-acknowledge').checked) btn.disabled = true;
                    alert('Update failed. Please try again.');
                });
        });
    }
})();
</script>
@endpush
