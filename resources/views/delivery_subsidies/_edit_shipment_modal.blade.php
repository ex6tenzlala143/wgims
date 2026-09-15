<div class="modal-overlay" id="shipmentEditModal">
    <div class="modal-shell" style="max-width:1100px;height:auto;max-height:calc(100vh - 32px)" role="dialog" aria-modal="true" aria-labelledby="shipmentEditTitle">
        <div class="modal-header">
            <div style="min-width:0">
                <h2 id="shipmentEditTitle"><i class="fas fa-edit"></i> Edit Shipment <span id="shipment-edit-ref" style="color:var(--text-muted);font-weight:600"></span></h2>
            </div>
            <button type="button" class="modal-close" onclick="closeShipmentEditModal()" aria-label="Close"><i class="fas fa-times"></i></button>
        </div>

        <form id="shipment-edit-form" method="POST" novalidate>
            @csrf
            <input type="hidden" name="_method" value="PUT">
            <div class="modal-body">
                <div id="shipment-edit-top-error" class="cr-form-error" style="display:none"></div>

                <div class="card form-section" style="margin-bottom:16px">
                    <div class="card-header"><h3><i class="fas fa-truck" style="color:var(--primary)"></i> Shipment Details</h3></div>
                    <div class="card-body">
                        <div class="form-row cols-3">
                            <div class="form-group" style="margin-bottom:0">
                                <label class="form-label">Delivery Date <span class="req">*</span></label>
                                <input type="date" name="delivery_date" id="shipment-edit-date" class="form-control" required>
                            </div>
                            <div class="form-group" style="margin-bottom:0">
                                <label class="form-label">Batch Number <span style="font-size:11px;color:var(--text-muted);font-weight:normal">optional</span></label>
                                <input type="text" name="batch_number" id="shipment-edit-batch" class="form-control" placeholder="Optional">
                            </div>
                            <div class="form-group" style="margin-bottom:0">
                                <label class="form-label">Remarks <span style="font-size:11px;color:var(--text-muted);font-weight:normal">optional</span></label>
                                <input type="text" name="remarks" id="shipment-edit-remarks" class="form-control" placeholder="Optional">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card form-section">
                    <div class="card-header">
                        <h3><i class="fas fa-boxes" style="color:var(--primary)"></i> Dispatched Items</h3>
                    </div>
                    <div id="shipment-edit-items"></div>
                    <div style="padding:12px 20px;border-top:1px solid var(--border);font-size:13px;display:flex;justify-content:flex-end;gap:24px">
                        <span>Total Value <strong id="shipment-edit-grand">₱0.00</strong></span>
                        <span>ENGAS Total <strong id="shipment-edit-grand-engas" style="color:var(--primary)">₱0.00</strong></span>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeShipmentEditModal()"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" id="shipment-edit-save" class="btn btn-primary" style="min-width:180px;justify-content:center"><i class="fas fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
(function () {
    'use strict';

    var BASE = '{{ url("/delivery-subsidies") }}';
    var SAVING = false;
    var FOCUS_DI = 0;

    function $(id) { return document.getElementById(id); }

    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function fmt(n) {
        return Number(n || 0).toLocaleString('en-PH', { maximumFractionDigits: 0 });
    }

    function fmtPhp(n) {
        return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function openModal() {
        const m = $('shipmentEditModal');
        if (!m) return;
        m.classList.add('open');
        document.body.classList.add('modal-open');
        document.addEventListener('keydown', onEsc);
    }

    function closeModal() {
        const m = $('shipmentEditModal');
        if (!m) return;
        m.classList.remove('open');
        document.body.classList.remove('modal-open');
        document.removeEventListener('keydown', onEsc);
    }

    function onEsc(e) {
        if (e.key === 'Escape' && !SAVING) closeModal();
    }

    function clearErrors() {
        document.querySelectorAll('#shipment-edit-form .se-field-error').forEach(function (el) { el.remove(); });
        document.querySelectorAll('#shipment-edit-form .is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
        const top = $('shipment-edit-top-error');
        if (top) { top.style.display = 'none'; top.innerHTML = ''; }
    }

    function showErrors(errors) {
        clearErrors();
        const form = $('shipment-edit-form');
        Object.keys(errors || {}).forEach(function (key) {
            const msg = errors[key][0];
            let el = null;
            const m = key.match(/^items\.(\d+)\.(.+)$/);
            if (m) {
                el = document.getElementById('se-' + m[2].replace(/_/g, '-') + '-' + m[1]);
                if (!el) el = form.querySelector('[name="' + key + '"]');
            } else {
                el = form.querySelector('[name="' + key + '"]');
            }
            if (el) {
                el.classList.add('is-invalid');
                const div = document.createElement('div');
                div.className = 'se-field-error';
                div.style.cssText = 'color:var(--danger);font-size:11px;margin-top:2px';
                div.textContent = msg;
                el.parentElement.appendChild(div);
            } else {
                const top = $('shipment-edit-top-error');
                if (top) {
                    top.style.display = 'block';
                    const d = document.createElement('div');
                    d.textContent = msg;
                    top.appendChild(d);
                }
            }
        });
        const top = $('shipment-edit-top-error');
        if (top && top.style.display === 'block') {
            top.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    }

    function recalc() {
        let qtySum = 0, grand = 0, grandEngas = 0;
        document.querySelectorAll('#shipment-edit-items .se-row').forEach(function (row) {
            const qty = parseFloat(row.querySelector('.se-qty').value) || 0;
            const cost = parseFloat(row.querySelector('.se-cost').value) || 0;
            const engas = parseFloat(row.querySelector('.se-engas').value) || 0;
            qtySum += qty;
            grand += qty * cost;
            const hasEngas = row.querySelector('.se-engas').value !== '';
            if (hasEngas) grandEngas += qty * engas;
            const t = row.querySelector('.se-total');
            if (t) t.textContent = fmtPhp(qty * cost);
            const et = row.querySelector('.se-etotal');
            if (et) et.textContent = hasEngas ? fmtPhp(qty * engas) : '—';
        });
        var qtyEl = $('shipment-edit-qty');
        if (qtyEl) qtyEl.textContent = fmt(qtySum);
        $('shipment-edit-grand').textContent = fmtPhp(grand);
        $('shipment-edit-grand-engas').textContent = fmtPhp(grandEngas);
    }

    function buildItems(data) {
        const wrap = $('shipment-edit-items');
        let html = '';
        (data.items || []).forEach(function (it, i) {
            const whOpts = (data.warehouses || []).map(function (w) {
                return '<option value="' + w.id + '"' +
                    (String(w.id) === String(it.warehouse_id) ? ' selected' : '') + '>' +
                    esc(w.name) + '</option>';
            }).join('');
            // Frozen when this stock already has downstream transactions —
            // readOnly (not disabled) so the value still submits.
            const frozen = it.transactions && it.transactions.length;
            const frozenNote = frozen
                ? '<small style="color:var(--warning);font-size:11px"><i class="fas fa-lock"></i> Quantity frozen — ' + esc(it.transactions.join('; ')) + '.</small>'
                : '<small style="color:var(--text-muted);font-size:11px">Max ' + fmt(it.max_qty) + '</small>';
            html += '<div class="se-row" id="se-row-' + it.di_id + '" style="border-bottom:1px solid var(--border);padding:16px 20px;display:grid;gap:12px">';
            html += '<input type="hidden" name="items[' + i + '][di_id]" value="' + it.di_id + '">';
            html += '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">'
                + '<strong style="font-size:13px">' + esc(it.description) + '</strong>'
                + (it.stock_number ? ' <code style="font-size:11px">' + esc(it.stock_number) + '</code>' : '')
                + '<span style="font-size:11px;color:var(--text-muted)">Stock: ' + fmt(it.current_stock) + '</span>'
                + '</div>';
            html += '<div class="form-row cols-3">'
                + '<div class="form-group" style="margin-bottom:0"><label class="form-label">Warehouse <span class="req">*</span></label>'
                + '<select name="items[' + i + '][warehouse_id]" id="se-warehouse-' + i + '" class="form-control" required>'
                + '<option value="">— Select —</option>' + whOpts + '</select></div>'
                + '<div class="form-group" style="margin-bottom:0"><label class="form-label">Quantity <span class="req">*</span></label>'
                + '<input type="number" name="items[' + i + '][quantity_delivered]" id="se-quantity-' + i + '" class="form-control se-qty" min="0" step="1" max="' + it.max_qty + '" value="' + it.quantity_delivered + '" required'
                + (frozen ? ' readonly title="Quantity is frozen: ' + esc(it.transactions.join('; ')) + '"' : '') + '>'
                + frozenNote + '</div>'
                + '<div class="form-group" style="margin-bottom:0"><label class="form-label">Unit Cost (₱) <span class="req">*</span></label>'
                + '<input type="number" name="items[' + i + '][unit_cost]" id="se-unit-cost-' + i + '" class="form-control se-cost" min="0" step="0.01" value="' + (it.unit_cost ?? '') + '" required></div>'
                + '</div>';
            html += '<div class="form-row cols-3">'
                + '<div class="form-group" style="margin-bottom:0"><label class="form-label">ENGAS Unit Cost (₱) <span class="req">*</span></label>'
                + '<input type="number" name="items[' + i + '][engas_unit_cost]" id="se-engas-unit-cost-' + i + '" class="form-control se-engas" min="0" step="0.01" value="' + (it.engas_unit_cost ?? '') + '" required></div>'
                + '<div class="form-group" style="margin-bottom:0"><label class="form-label">DR No. <span class="req">*</span></label>'
                + '<input type="text" name="items[' + i + '][dr_number]" id="se-dr-number-' + i + '" class="form-control" maxlength="100" value="' + esc(it.dr_number || '') + '" required></div>'
                + '<div class="form-group" style="margin-bottom:0"><label class="form-label">Expiration</label>'
                + '<input type="date" name="items[' + i + '][expiration_date]" id="se-expiration-date-' + i + '" class="form-control" value="' + (it.expiration_date || '') + '"></div>'
                + '</div>';
            html += '<div class="form-row cols-2">'
                + '<div class="form-group" style="margin-bottom:0"><label class="form-label">Condition <span class="req">*</span></label>'
                + '<select name="items[' + i + '][condition]" id="se-condition-' + i + '" class="form-control" data-ss="false" required>'
                + '<option value="good"' + (it.condition === 'good' ? ' selected' : '') + '>Good</option>'
                + '<option value="damaged"' + (it.condition === 'damaged' ? ' selected' : '') + '>Damaged</option>'
                + '</select></div>'
                + '<div style="display:flex;align-items:flex-end;gap:16px;font-size:12px;color:var(--text-muted)">'
                + '<span>Total <strong class="se-total" style="color:var(--text)"></strong></span>'
                + '<span>ENGAS <strong class="se-etotal" style="color:var(--primary)"></strong></span>'
                + '</div>'
                + '</div>';
            html += '</div>';
        });
        wrap.innerHTML = html;
        wrap.querySelectorAll('input, select').forEach(function (el) {
            el.addEventListener('input', recalc);
            el.addEventListener('change', recalc);
        });
        recalc();
    }

    window.openShipmentEditModal = function (dsId, deliveryId, focusDiId) {
        if (SAVING) return;
        FOCUS_DI = focusDiId || 0;
        clearErrors();
        fetch(BASE + '/' + dsId + '/deliveries/' + deliveryId + '/edit-data', {
            headers: { 'Accept': 'application/json' },
        })
            .then(function (res) { if (!res.ok) throw new Error('HTTP ' + res.status); return res.json(); })
            .then(function (data) {
                $('shipment-edit-ref').textContent = (data.ris_number ? '#' + data.ris_number : '') + ' — ' + (data.supplier_name || '');
                $('shipment-edit-form').action = BASE + '/' + dsId + '/deliveries/' + data.id;
                $('shipment-edit-date').value = data.delivery_date || '';
                $('shipment-edit-batch').value = data.batch_number || '';
                $('shipment-edit-remarks').value = data.remarks || '';
                buildItems(data);
                openModal();
                if (FOCUS_DI) {
                    const row = document.getElementById('se-row-' + FOCUS_DI);
                    if (row) {
                        setTimeout(function () {
                            try { row.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) {}
                            row.style.transition = 'background-color .6s';
                            row.style.backgroundColor = '#ebf8ff';
                            setTimeout(function () { row.style.backgroundColor = ''; }, 1600);
                        }, 150);
                    }
                }
            })
            .catch(function () {
                alert('Could not load the shipment data. Please try again.');
            });
    };

    window.closeShipmentEditModal = closeModal;

    const overlay = document.getElementById('shipmentEditModal');
    if (overlay) {
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeModal();
        });
    }

    const form = document.getElementById('shipment-edit-form');
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (SAVING) return;

            // ENGAS is required for any line keeping a positive quantity.
            const missing = [];
            document.querySelectorAll('#shipment-edit-items .se-row').forEach(function (row) {
                const qty = parseFloat(row.querySelector('.se-qty').value) || 0;
                const engas = row.querySelector('.se-engas').value;
                if (qty > 0 && (engas === '' || engas === null)) {
                    missing.push(row.querySelector('strong').textContent.trim());
                }
            });
            if (missing.length > 0) {
                alert('ENGAS Unit Cost is required for the following dispatched item(s):\n\n' + missing.join('\n'));
                return;
            }

            if (!window.confirm('Save changes to this shipment?\n\nStock and stock cards will be adjusted accordingly.')) return;

            SAVING = true;
            const btn = form.querySelector('button[type="submit"]');
            const orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            clearErrors();

            const fd = new FormData(form);

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
                    else alert('Update failed. Please try again.');
                })
                .catch(function () {
                    SAVING = false;
                    btn.disabled = false;
                    btn.innerHTML = orig;
                    alert('Update failed. Please try again.');
                });
        });
    }
})();
</script>
@endpush
