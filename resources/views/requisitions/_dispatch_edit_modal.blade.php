@push('styles')
<style>
    .de-modal-overlay {
        position: fixed;
        inset: 0;
        z-index: 1300;
        background: rgba(15, 23, 42, 0.55);
        backdrop-filter: blur(3px);
        -webkit-backdrop-filter: blur(3px);
        display: flex;
        align-items: flex-start;
        justify-content: center;
        padding: 20px;
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.25s ease, visibility 0.25s ease;
    }
    .de-modal-overlay.open { opacity: 1; visibility: visible; }
    .de-modal-shell {
        width: 100%;
        max-width: 680px;
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
    .de-modal-overlay.open .de-modal-shell { transform: none; opacity: 1; }
    .de-modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 16px 24px;
        border-bottom: 1px solid var(--border);
        background: linear-gradient(180deg, #ffffff, #f9fbfd);
        flex-shrink: 0;
    }
    .de-modal-header h2 {
        font-size: 18px;
        font-weight: 700;
        color: var(--text);
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0;
    }
    .de-modal-header h2 i { color: var(--primary); }
    .de-modal-subtitle { font-size: 12.5px; color: var(--text-muted); margin-top: 3px; line-height: 1.5; }
    .de-modal-close {
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
    .de-modal-close:hover { background: #fee2e2; color: var(--danger); }
    .de-modal-shell > form {
        display: flex;
        flex-direction: column;
        flex: 1;
        min-height: 0;
    }
    .de-modal-body {
        flex: 1;
        min-height: 0;
        overflow-y: auto;
        padding: 20px 24px;
        background: #f8fafc;
        -webkit-overflow-scrolling: touch;
    }
    .de-modal-footer {
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

    .de-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .de-grid .de-full { grid-column: 1 / -1; }
    .de-grid .form-group { margin-bottom: 0; }

    .de-summary {
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
    .de-summary strong { color: #78350f; }

    .de-field-error { color: var(--danger); font-size: 11.5px; margin-top: 4px; line-height: 1.4; }
    .de-form-error {
        color: var(--danger);
        font-size: 13px;
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-radius: 8px;
        padding: 10px 14px;
        margin-bottom: 16px;
    }
    .de-modal-body .is-invalid {
        border-color: var(--danger) !important;
        background: #fff5f5 !important;
    }

    @media (max-width: 640px) {
        .de-modal-overlay { padding: 10px; }
        .de-modal-shell { width: 100%; }
        .de-modal-header { padding: 12px 16px; }
        .de-modal-body { padding: 14px; }
        .de-modal-footer { padding: 12px 16px; }
        .de-grid { grid-template-columns: 1fr; }
    }
</style>
@endpush

<div class="de-modal-overlay" id="dispatchEditModal">
    <div class="de-modal-shell" role="dialog" aria-modal="true" aria-labelledby="dispatchEditTitle">
        <div class="de-modal-header">
            <div style="min-width:0">
                <h2 id="dispatchEditTitle"><i class="fas fa-edit"></i> Edit Issued Item <span id="dispatch-edit-ref" style="color:var(--text-muted);font-weight:600"></span></h2>
                <div class="de-modal-subtitle">
                    Stock is reversed from the old record and re-applied to the exact new record —
                    never double-counted or lost.
                </div>
            </div>
            <button type="button" class="de-modal-close" onclick="closeDispatchEditModal()" aria-label="Close"><i class="fas fa-times"></i></button>
        </div>

        <form id="dispatch-edit-form" method="POST" novalidate>
            @csrf
            <input type="hidden" name="_method" value="PUT">
            <input type="hidden" name="dispatch_id" id="dispatch-edit-id">
            <input type="hidden" name="requisition_item_id" id="dispatch-edit-ri-id">
            <div class="de-modal-body">
                <div id="dispatch-edit-top-error" class="de-form-error" style="display:none"></div>

                <div class="de-grid">
                    <div class="form-group de-full">
                        <label class="form-label">Warehouse <span class="req">*</span></label>
                        <select name="warehouse_id" id="dispatch-wh" class="form-control" onchange="dispatchLoadStock()">
                            <option value="">— Select Warehouse —</option>
                        </select>
                    </div>
                    <div class="form-group de-full">
                        <label class="form-label">Stock Record <span class="req">*</span> <span class="hint">(item + unit cost + stock no.)</span></label>
                        <select name="item_id" id="dispatch-item" class="form-control" onchange="dispatchFillItem()">
                            <option value="">— Select Warehouse first —</option>
                        </select>
                        <small id="dispatch-stock-hint" style="color:var(--text-muted);font-size:11px"></small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Quantity Issued <span class="req">*</span></label>
                        <input type="number" name="quantity_issued" id="dispatch-qty" class="form-control" min="1" step="1" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Unit Cost <span class="req">*</span></label>
                        <input type="number" name="unit_cost" id="dispatch-unit-cost" class="form-control" min="0" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">ENGAS Unit Cost</label>
                        <input type="number" name="engas_unit_cost" id="dispatch-engas" class="form-control" min="0" step="0.01">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Expiration Date</label>
                        <input type="date" name="expiration_date" id="dispatch-expiry" class="form-control">
                    </div>
                    <div class="form-group de-full">
                        <label class="form-label">DR Number <span class="req">*</span> <span class="hint">(Delivery Receipt — this dispatch)</span></label>
                        <input type="text" name="dr_number" id="dispatch-dr" class="form-control" required placeholder="e.g. DR-001">
                    </div>
                </div>

                <div class="de-summary" id="dispatch-edit-summary"></div>
            </div>

            <div class="de-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDispatchEditModal()"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" class="btn btn-primary" style="min-width:170px;justify-content:center"><i class="fas fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
(function () {
    'use strict';

    var STATE       = null;   // original dispatch data from the edit-data endpoint
    var SAVING      = false;
    var LOCK_FIELDS = false;  // true only during the initial open, so the dispatch's
                              // stored costs are not overwritten by the auto-selected
                              // record; every user action afterwards auto-fills
    var ITEMS_API = '{{ route("requisitions.items_by_warehouse") }}';

    var $ = function (id) { return document.getElementById(id); };

    function openModal() {
        var m = $('dispatchEditModal');
        if (!m) return;
        m.classList.add('open');
        document.body.classList.add('modal-open');
        document.addEventListener('keydown', onEsc);
    }

    function closeModal() {
        var m = $('dispatchEditModal');
        if (!m) return;
        m.classList.remove('open');
        document.body.classList.remove('modal-open');
        document.removeEventListener('keydown', onEsc);
    }

    function onEsc(e) {
        if (e.key === 'Escape' && !SAVING) closeModal();
    }

    function clearErrors() {
        document.querySelectorAll('#dispatch-edit-form .de-field-error').forEach(function (el) { el.remove(); });
        document.querySelectorAll('#dispatch-edit-form .is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
        var top = $('dispatch-edit-top-error');
        if (top) { top.style.display = 'none'; top.className = 'de-form-error'; top.innerHTML = ''; }
        var summary = $('dispatch-edit-summary');
        if (summary) { summary.style.display = 'none'; summary.innerHTML = ''; }
    }

    function showErrors(errors) {
        clearErrors();
        var form = $('dispatch-edit-form');
        Object.keys(errors).forEach(function (key) {
            var el = form.querySelector('[name="' + key + '"]');
            if (el) {
                el.classList.add('is-invalid');
                var div = document.createElement('div');
                div.className = 'de-field-error';
                div.textContent = errors[key][0];
                el.parentElement.appendChild(div);
            } else {
                var top = $('dispatch-edit-top-error');
                if (top) {
                    top.style.display = 'block';
                    var msg = document.createElement('div');
                    msg.textContent = errors[key][0];
                    top.appendChild(msg);
                }
            }
        });
    }

    function stockOptionHtml(r) {
        return '<option value="' + r.id + '"' +
            ' data-unit-cost="' + (r.unit_cost || 0) + '"' +
            ' data-engas="' + (r.engas_unit_cost || '') + '"' +
            ' data-expiry="' + (r.expiry_date || '') + '"' +
            ' data-stock="' + r.quantity + '"' +
            ' data-sn="' + (r.stock_number || '') + '"' +
            ' data-unit="' + (r.unit || '') + '"' +
            '>' + r.description +
            (r.stock_number ? ' [' + r.stock_number + ']' : '') +
            ' · ₱' + Number(r.unit_cost || 0).toFixed(2) +
            ' · ' + Number(r.quantity).toLocaleString('en-PH', { maximumFractionDigits: 0 }) + ' ' + (r.unit || '') + '</option>';
    }

    function populateItemSelect(records, selectItemId) {
        var sel = $('dispatch-item');
        var opts = '<option value="">— Select Stock Record —</option>' +
            records.map(stockOptionHtml).join('');
        sel.innerHTML = opts;
        sel.disabled = false;
        if (selectItemId) {
            var found = Array.prototype.find.call(sel.options, function (o) { return o.value === String(selectItemId); });
            if (found) {
                sel.value = String(selectItemId);
                fillItem();
            }
        }
    }

    function loadStock(selectItemId) {
        var sel = $('dispatch-wh');
        var itemSel = $('dispatch-item');
        var hint = $('dispatch-stock-hint');
        if (!sel || !itemSel) return;

        itemSel.innerHTML = '<option value="">— Select Warehouse first —</option>';
        if (hint) hint.textContent = '';

        if (!sel.value) {
            itemSel.disabled = true;
            return;
        }

        // Current warehouse: use the records already returned by edit-data.
        if (STATE && sel.value === String(STATE.warehouse_id)) {
            populateItemSelect(STATE.stock_records, selectItemId);
            return;
        }

        itemSel.innerHTML = '<option value="">— Loading stock records… —</option>';
        itemSel.disabled = true;

        fetch(ITEMS_API + '?warehouse_id=' + encodeURIComponent(sel.value), {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (data) { populateItemSelect(data, selectItemId); })
            .catch(function () {
                itemSel.innerHTML = '<option value="">— Failed to load records —</option>';
                itemSel.disabled = false;
            });
    }

    function fillItem() {
        var sel = $('dispatch-item');
        var hint = $('dispatch-stock-hint');
        if (!sel) return;
        var opt = sel.options[sel.selectedIndex];
        if (!opt || !opt.value) return;

        var cost = $('dispatch-unit-cost');
        var engas = $('dispatch-engas');
        var expiry = $('dispatch-expiry');

        if (!LOCK_FIELDS) {
            if (cost) cost.value = opt.dataset.unitCost || '';
            if (engas) engas.value = opt.dataset.engas || '';
            if (expiry) expiry.value = opt.dataset.expiry || '';
        }
        LOCK_FIELDS = false;

        if (hint) {
            var stock = parseFloat(opt.dataset.stock || 0);
            hint.textContent = 'Available on this record: ' + Number(stock).toLocaleString('en-PH', { maximumFractionDigits: 0 });
            hint.style.color = stock > 0 ? 'var(--success)' : 'var(--danger)';
        }

        $('dispatch-qty').max = opt.dataset.stock || '';
        buildSummary();
    }

    function buildSummary() {
        var summary = $('dispatch-edit-summary');
        if (!summary || !STATE) return;

        var wh = $('dispatch-wh');
        var itemSel = $('dispatch-item');
        var qty = $('dispatch-qty');
        var unit = $('dispatch-unit-cost');
        var dr = $('dispatch-dr');

        var lines = [];
        var newWhId = wh ? wh.value : '';
        var whName = newWhId && wh.options[wh.selectedIndex]
            ? wh.options[wh.selectedIndex].textContent.trim() : '—';

        if (newWhId && newWhId !== String(STATE.warehouse_id)) {
            lines.push('<strong>Warehouse:</strong> ' + whName + ' (stock record moves)');
        }

        if (itemSel && itemSel.value && itemSel.value !== String(STATE.item_id)) {
            var opt = itemSel.options[itemSel.selectedIndex];
            lines.push('<strong>Stock record:</strong> ' + (opt ? opt.textContent.trim() : '—'));
        }

        var newQty = qty ? parseFloat(qty.value) || 0 : 0;
        if (Math.abs(newQty - STATE.quantity_issued) > 0.0001) {
            lines.push('<strong>Quantity:</strong> ' + Number(STATE.quantity_issued).toLocaleString('en-PH', { maximumFractionDigits: 0 })
                + ' → ' + Number(newQty).toLocaleString('en-PH', { maximumFractionDigits: 0 }));
        }

        if (unit && parseFloat(unit.value || 0) !== parseFloat(STATE.unit_cost || 0)) {
            lines.push('<strong>Unit cost:</strong> ₱' + Number(STATE.unit_cost || 0).toFixed(2)
                + ' → ₱' + Number(unit.value || 0).toFixed(2));
        }

        if (dr && dr.value !== (STATE.dr_number || '')) {
            lines.push('<strong>DR No.:</strong> ' + (STATE.dr_number || '—') + ' → ' + (dr.value || '—'));
        }

        if (lines.length > 0) {
            summary.innerHTML = '<i class="fas fa-info-circle"></i> Changes to be applied:<br>' +
                lines.map(function (l) { return '<span style="display:block;margin-top:2px">• ' + l + '</span>'; }).join('');
            summary.style.display = 'block';
        } else {
            summary.style.display = 'none';
        }
    }

    window.dispatchLoadStock = function () {
        LOCK_FIELDS = false;
        loadStock(null);
        buildSummary();
    };

    window.dispatchFillItem = function () { fillItem(); };

    window.openDispatchEditModal = function (id) {
        if (SAVING) return;
        clearErrors();
        fetch('{{ route("requisitions.dispatch_edit_data", ["dispatch" => "__ID__"]) }}'.replace('__ID__', id), {
            headers: { 'Accept': 'application/json' },
        })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function (data) {
                STATE = data;

                $('dispatch-edit-id').value = data.id;
                $('dispatch-edit-ri-id').value = data.requisition_item_id;
                $('dispatch-edit-form').action = '{{ route("requisitions.dispatch_update", ["dispatch" => "__ID__"]) }}'.replace('__ID__', data.id);

                var ref = $('dispatch-edit-ref');
                if (ref) ref.textContent = (data.ris_number ? '#' + data.ris_number : '') + (data.description ? ' — ' + data.description : '');

                var wh = $('dispatch-wh');
                wh.innerHTML = '<option value="">— Select Warehouse —</option>' +
                    data.warehouses.map(function (w) {
                        return '<option value="' + w.id + '"' + (String(w.id) === String(data.warehouse_id) ? ' selected' : '') + '>' +
                            w.name + (w.code ? ' (' + w.code + ')' : '') + '</option>';
                    }).join('');

                $('dispatch-qty').value = data.quantity_issued;
                $('dispatch-unit-cost').value = data.unit_cost || '';
                $('dispatch-engas').value = data.engas_unit_cost || '';
                $('dispatch-expiry').value = data.expiration_date || '';
                $('dispatch-dr').value = data.dr_number || '';

                LOCK_FIELDS = true;   // keep the dispatch's stored costs on auto-select
                loadStock(data.item_id);
                buildSummary();
                openModal();
            })
            .catch(function () {
                alert('Could not load the dispatch data. Please try again.');
            });
    };

    window.closeDispatchEditModal = closeModal;

    var form = $('dispatch-edit-form');
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!STATE || SAVING) return;

            var changes = [];
            var summary = $('dispatch-edit-summary');
            if (summary && summary.style.display !== 'none' && summary.textContent.trim()) {
                changes.push('the dispatch details');
            }
            if (changes.length > 0 || true) {
                var ok = window.confirm('Save changes to this issued item?\n\nStock will be reversed from the old record and re-applied to the exact new record. No double-counting or stock loss.');
                if (!ok) return;
            }

            SAVING = true;
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
                    SAVING = false;
                    btn.disabled = false;
                    btn.innerHTML = orig;
                    if (r.ok && r.d.redirect) {
                        window.location.href = r.d.redirect;
                        return;
                    }
                    if (r.d.errors) showErrors(r.d.errors);
                })
                .catch(function () {
                    SAVING = false;
                    btn.disabled = false;
                    btn.innerHTML = orig;
                    alert('Update failed. Please try again.');
                });
        });

        ['dispatch-qty', 'dispatch-unit-cost', 'dispatch-engas', 'dispatch-expiry', 'dispatch-dr']
            .forEach(function (id) {
                var el = $(id);
                if (el) el.addEventListener('input', function () { buildSummary(); });
            });
    }
})();
</script>
@endpush
