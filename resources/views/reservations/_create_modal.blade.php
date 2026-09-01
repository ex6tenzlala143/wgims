{{--
    Reservation Create Modal
    Included by reservations/index.blade.php.
    Uses the same .modal-overlay / .modal-shell pattern as other WGIMS modals.
--}}
@php $createModalOpen = ($createModalOpen ?? false) || $errors->hasAny(['purpose','notes','expires_at','items','items.*']); @endphp

<div class="modal-overlay{{ $createModalOpen ? ' open' : '' }}" id="createReservationModal">
    <div class="modal-shell" style="max-width:1200px;height:auto;max-height:calc(100vh - 32px)" role="dialog" aria-modal="true" aria-labelledby="resModalTitle">

        <div class="modal-header">
            <div style="min-width:0">
                <h2 id="resModalTitle"><i class="fas fa-clipboard-list"></i> New Reservation</h2>
                <div class="modal-subtitle">Reserve inventory items for an upcoming requisition. Each item keeps its exact stock record, warehouse, and cost identity.</div>
            </div>
            <button type="button" class="modal-close" onclick="closeResModal()" aria-label="Close"><i class="fas fa-times"></i></button>
        </div>

        <form action="{{ route('reservations.store') }}" method="POST" id="res-create-form" class="subsidy-form">
            @csrf
            <div class="modal-body">

                {{-- Header fields --}}
                <section class="card form-section">
                    <div class="card-header">
                        <h3><i class="fas fa-info-circle" style="color:var(--primary)"></i> Reservation Details</h3>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label class="form-label">Purpose</label>
                            <input type="text" name="purpose"
                                   class="form-control {{ $errors->has('purpose') ? 'is-invalid' : '' }}"
                                   value="{{ old('purpose') }}"
                                   placeholder="e.g. For incoming disaster response">
                            @error('purpose')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="form-row cols-2">
                            <div class="form-group" style="margin-bottom:0">
                                <label class="form-label">Reservation Expiry Date
                                    <span style="color:var(--text-muted);font-size:11px">(optional)</span>
                                </label>
                                <input type="date" name="expires_at"
                                       class="form-control {{ $errors->has('expires_at') ? 'is-invalid' : '' }}"
                                       value="{{ old('expires_at') }}"
                                       min="{{ date('Y-m-d', strtotime('+1 day')) }}">
                                @error('expires_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="form-group" style="margin-bottom:0">
                                <label class="form-label">Notes
                                    <span style="color:var(--text-muted);font-size:11px">(optional)</span>
                                </label>
                                <input type="text" name="notes" class="form-control"
                                       value="{{ old('notes') }}" placeholder="Additional notes">
                            </div>
                        </div>
                    </div>
                </section>

                {{-- Reserved items rows --}}
                <section class="card form-section">
                    <div class="card-header" style="justify-content:space-between">
                        <h3><i class="fas fa-boxes" style="color:var(--primary)"></i> Reserved Items</h3>
                        <button type="button" class="btn btn-sm btn-primary" onclick="resModalAddRow()">
                            <i class="fas fa-plus"></i> Add Item
                        </button>
                    </div>

                    @if($errors->has('items'))
                    <div style="padding:10px 20px;background:#fff5f5;border-bottom:1px solid var(--border);color:var(--danger);font-size:13px">
                        <i class="fas fa-exclamation-triangle"></i> {{ $errors->first('items') }}
                    </div>
                    @endif

                    <div id="res-modal-items-container"></div>

                    <div style="padding:12px 20px;border-top:1px solid var(--border);display:flex;align-items:center;gap:10px">
                        <button type="button" class="btn btn-sm btn-outline" onclick="resModalAddRow()">
                            <i class="fas fa-plus"></i> Add Another Item
                        </button>
                        <span id="res-modal-row-count" style="font-size:12px;color:var(--text-muted)"></span>
                    </div>
                </section>

            </div>{{-- /modal-body --}}

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeResModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn btn-primary" style="min-width:180px;justify-content:center">
                    <i class="fas fa-save"></i> Create Reservation
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
(function () {
'use strict';

// ── Constants ────────────────────────────────────────────────────────────────
const RES_MODAL_API     = '{{ route("reservations.items_by_warehouse") }}';
const RES_MODAL_WH      = @json($modalWarehouses->map(fn($w) => ['id' => $w->id, 'name' => $w->name]));
const RES_MODAL_OLD     = @json(old('items', []));
const RES_MODAL_HAS_ERR = {{ $createModalOpen ? 'true' : 'false' }};

// ── State ────────────────────────────────────────────────────────────────────
let resModalRowIdx = 0;
const resModalCache = {};   // warehouseId → items[]

// ── Modal open/close ─────────────────────────────────────────────────────────
function openResModal() {
    const m = document.getElementById('createReservationModal');
    if (!m) return;
    m.classList.add('open');
    document.body.classList.add('modal-open');
    document.addEventListener('keydown', resModalEsc);
    // Focus first focusable field
    setTimeout(function () {
        const first = m.querySelector('input[name="purpose"]');
        if (first) first.focus();
    }, 250);
}

window.openResModal = openResModal;

function closeResModal() {
    const m = document.getElementById('createReservationModal');
    if (!m) return;
    // Warn if the user has entered data
    const hasData = (
        document.querySelector('#res-create-form input[name="purpose"]')?.value.trim() ||
        document.querySelector('#res-modal-items-container select')?.value
    );
    if (hasData && !confirm('Discard this reservation? Any unsaved data will be lost.')) {
        return;
    }
    m.classList.remove('open');
    document.body.classList.remove('modal-open');
    document.removeEventListener('keydown', resModalEsc);
}

window.closeResModal = closeResModal;

function resModalEsc(e) {
    if (e.key === 'Escape') closeResModal();
}

// Click backdrop to close
document.addEventListener('DOMContentLoaded', function () {
    const overlay = document.getElementById('createReservationModal');
    if (!overlay) return;

    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeResModal();
    });

    // Restore old() rows if validation failed (server re-rendered with errors)
    const oldKeys = Object.keys(RES_MODAL_OLD);
    if (oldKeys.length > 0) {
        oldKeys.forEach(function (k) {
            const line = RES_MODAL_OLD[k];
            resModalAddRow(line.warehouse_id, line.item_id, line.reserved_quantity, parseInt(k, 10));
            if (parseInt(k, 10) >= resModalRowIdx) resModalRowIdx = parseInt(k, 10) + 1;
        });
    } else {
        resModalAddRow(); // start with one blank row
    }
    resModalUpdateCount();

    if (RES_MODAL_HAS_ERR) {
        const m = document.getElementById('createReservationModal');
        if (m) { m.classList.add('open'); document.body.classList.add('modal-open'); }
    }

    guardFormSubmit(document.getElementById('res-create-form'));
});

// ── Row management ────────────────────────────────────────────────────────────
function resModalUpdateCount() {
    const n   = document.querySelectorAll('#res-modal-items-container .res-modal-row').length;
    const el  = document.getElementById('res-modal-row-count');
    if (el) el.textContent = n + ' item' + (n === 1 ? '' : 's');
}

window.resModalAddRow = function (oldWh, oldItem, oldQty, oldIdx) {
    const idx       = (oldIdx !== undefined) ? oldIdx : resModalRowIdx++;
    const container = document.getElementById('res-modal-items-container');

    const row       = document.createElement('div');
    row.className   = 'res-modal-row';
    row.id          = 'res-modal-row-' + idx;
    row.style.cssText = 'border-bottom:1px solid var(--border);padding:16px 20px;display:grid;gap:12px';

    const whOptions = RES_MODAL_WH.map(function (w) {
        const sel = (w.id == oldWh) ? ' selected' : '';
        return `<option value="${w.id}"${sel}>${escHtml(w.name)}</option>`;
    }).join('');

    row.innerHTML = `
        <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:start">
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label">Warehouse <span class="req">*</span></label>
                <select name="items[${idx}][warehouse_id]" id="res-modal-wh-${idx}"
                        class="form-control"
                        onchange="resModalLoadItems(${idx})">
                    <option value="">— Select Warehouse —</option>
                    ${whOptions}
                </select>
                <div id="res-modal-wh-err-${idx}" style="color:var(--danger);font-size:12px;margin-top:3px"></div>
            </div>
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label">Item / Stock Record <span class="req">*</span></label>
                <select name="items[${idx}][item_id]" id="res-modal-item-${idx}"
                        class="form-control" disabled
                        onchange="resModalShowInfo(${idx})">
                    <option value="">— Select Warehouse first —</option>
                </select>
                <div id="res-modal-item-err-${idx}" style="color:var(--danger);font-size:12px;margin-top:3px"></div>
            </div>
            <div style="padding-top:24px">
                <button type="button" class="btn btn-sm btn-danger btn-icon"
                        onclick="resModalRemoveRow(${idx})" title="Remove">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>

        <div id="res-modal-info-${idx}" style="display:none;padding:12px;background:var(--surface-soft);border-radius:8px;font-size:13px">
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:8px">
                <div>
                    <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Physical Qty</div>
                    <div style="font-size:18px;font-weight:700" id="res-modal-phys-${idx}">0</div>
                </div>
                <div>
                    <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Reserved</div>
                    <div style="font-size:18px;font-weight:700;color:var(--warning)" id="res-modal-rsrv-${idx}">0</div>
                </div>
                <div>
                    <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Available</div>
                    <div style="font-size:18px;font-weight:700;color:var(--success)" id="res-modal-avail-${idx}">0</div>
                </div>
            </div>
            <div id="res-modal-detail-${idx}" style="font-size:11px;color:var(--text-muted)"></div>
        </div>

        <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Quantity to Reserve <span class="req">*</span></label>
            <input type="number" name="items[${idx}][reserved_quantity]" id="res-modal-qty-${idx}"
                   class="form-control" min="1" step="1" placeholder="Enter quantity"
                   value="${oldQty || ''}" disabled>
            <div id="res-modal-qty-err-${idx}" style="color:var(--danger);font-size:12px;margin-top:3px"></div>
            <small id="res-modal-hint-${idx}" style="color:var(--text-muted);font-size:11px">Select an item first</small>
        </div>`;

    container.appendChild(row);
    resModalUpdateCount();

    if (oldWh) resModalLoadItems(idx, oldItem);
};

window.resModalRemoveRow = function (idx) {
    const rows = document.querySelectorAll('#res-modal-items-container .res-modal-row');
    if (rows.length <= 1) {
        alert('A reservation must have at least one item.');
        return;
    }
    const row = document.getElementById('res-modal-row-' + idx);
    if (row) row.remove();
    resModalUpdateCount();
};

// ── Items AJAX ────────────────────────────────────────────────────────────────
window.resModalLoadItems = function (idx, restoreItemId) {
    const whSel    = document.getElementById('res-modal-wh-' + idx);
    const itemSel  = document.getElementById('res-modal-item-' + idx);
    const info     = document.getElementById('res-modal-info-' + idx);
    const qtyInput = document.getElementById('res-modal-qty-' + idx);

    if (!whSel || !whSel.value) {
        itemSel.innerHTML = '<option value="">— Select Warehouse first —</option>';
        itemSel.disabled  = true;
        if (info)     info.style.display = 'none';
        if (qtyInput) qtyInput.disabled  = true;
        return;
    }

    const whId = whSel.value;

    // Use per-warehouse cache
    if (resModalCache[whId]) {
        resModalPopulate(idx, resModalCache[whId], restoreItemId);
        return;
    }

    itemSel.innerHTML = '<option value="">Loading…</option>';
    itemSel.disabled  = true;

    fetch(RES_MODAL_API + '?warehouse_id=' + encodeURIComponent(whId), {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
    })
    .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status + ' ' + r.statusText);
        return r.json();
    })
    .then(function (data) {
        resModalCache[whId] = data;
        resModalPopulate(idx, data, restoreItemId);
    })
    .catch(function (err) {
        itemSel.innerHTML = '<option value="">Error loading items — ' + escHtml(err.message) + '</option>';
        itemSel.disabled  = false;
        console.error('resModalLoadItems failed:', err);
    });
};

function resModalPopulate(idx, items, restoreItemId) {
    const itemSel = document.getElementById('res-modal-item-' + idx);
    if (!itemSel) return;

    if (!items || items.length === 0) {
        itemSel.innerHTML = '<option value="">No available items in this warehouse</option>';
        itemSel.disabled  = true;
        return;
    }

    const opts = items.map(function (i) {
        const expiry  = i.expiry_formatted ? ' · Exp: ' + i.expiry_formatted : '';
        const engas   = i.engas_unit_cost  ? ' · ENGAS ₱' + parseFloat(i.engas_unit_cost).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2}) : '';
        const label   = escHtml(i.description) + ' (' + escHtml(i.stock_number) + ')'
                      + ' · Avail: ' + i.available_qty.toLocaleString()
                      + ' · ₱' + parseFloat(i.unit_cost).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})
                      + engas + expiry;
        return '<option value="' + i.id + '"'
            + ' data-physical="' + i.physical_qty + '"'
            + ' data-reserved="' + i.reserved_qty + '"'
            + ' data-available="' + i.available_qty + '"'
            + ' data-unit-cost="' + i.unit_cost + '"'
            + ' data-engas="' + (i.engas_unit_cost || '') + '"'
            + ' data-expiry="' + (i.expiry_formatted || '') + '"'
            + ' data-subsidy="' + escAttr(i.source_subsidy_code || '') + '"'
            + '>' + label + '</option>';
    }).join('');

    itemSel.innerHTML = '<option value="">— Select Stock Record —</option>' + opts;
    itemSel.disabled  = false;

    if (restoreItemId) {
        const found = Array.from(itemSel.options).find(function (o) { return o.value === String(restoreItemId); });
        if (found) {
            itemSel.value = String(restoreItemId);
            resModalShowInfo(idx);
        }
    }
}

window.resModalShowInfo = function (idx) {
    const itemSel  = document.getElementById('res-modal-item-' + idx);
    const info     = document.getElementById('res-modal-info-' + idx);
    const qtyInput = document.getElementById('res-modal-qty-' + idx);
    const hint     = document.getElementById('res-modal-hint-' + idx);

    const opt = itemSel && itemSel.options[itemSel.selectedIndex];

    if (!opt || !opt.value) {
        if (info)     info.style.display = 'none';
        if (qtyInput) qtyInput.disabled  = true;
        return;
    }

    const phys  = parseFloat(opt.dataset.physical)  || 0;
    const rsrv  = parseFloat(opt.dataset.reserved)  || 0;
    const avail = parseFloat(opt.dataset.available) || 0;

    const physEl  = document.getElementById('res-modal-phys-' + idx);
    const rsrvEl  = document.getElementById('res-modal-rsrv-' + idx);
    const availEl = document.getElementById('res-modal-avail-' + idx);
    const detEl   = document.getElementById('res-modal-detail-' + idx);

    if (physEl)  physEl.textContent  = phys.toLocaleString();
    if (rsrvEl)  rsrvEl.textContent  = rsrv.toLocaleString();
    if (availEl) availEl.textContent = avail.toLocaleString();

    const parts = [];
    if (opt.dataset.unitCost) parts.push('₱' + parseFloat(opt.dataset.unitCost).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2}));
    if (opt.dataset.engas)    parts.push('ENGAS ₱' + parseFloat(opt.dataset.engas).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2}));
    if (opt.dataset.expiry)   parts.push('Exp: ' + opt.dataset.expiry);
    if (opt.dataset.subsidy)  parts.push('Source: ' + opt.dataset.subsidy);
    if (detEl) detEl.textContent = parts.join(' · ');

    if (info)     info.style.display = 'block';
    if (qtyInput) {
        qtyInput.disabled = avail <= 0;
        qtyInput.max      = avail;
        if (hint) hint.textContent = avail > 0
            ? 'Maximum available: ' + avail.toLocaleString()
            : 'No quantity available for reservation';
    }
};

// ── Helpers ───────────────────────────────────────────────────────────────────
function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
function escAttr(str) { return escHtml(str); }

})(); // end IIFE
</script>
@endpush
