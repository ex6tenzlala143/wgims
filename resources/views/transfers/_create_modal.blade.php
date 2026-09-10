@php
    $createModalOpen = $createModalOpen ?? false;
@endphp

<div class="modal-overlay{{ $createModalOpen ? ' open' : '' }}" id="createTransferModal">
    <div class="modal-shell transfer-modal" role="dialog" aria-modal="true" aria-labelledby="createTransferModalTitle">
        <div class="modal-header">
            <div style="min-width:0">
                <h2 id="createTransferModalTitle"><i class="fas fa-exchange-alt"></i> New Stock Transfer</h2>
            </div>
            <button type="button" class="modal-close" onclick="closeTransferModal()" aria-label="Close"><i class="fas fa-times"></i></button>
        </div>

        <form action="{{ route('transfers.store') }}" method="POST" id="transfer-form">
            @csrf
            <div class="modal-body">
                {{-- Transfer Details --}}
                <section class="card form-section">
                    <div class="card-header">
                        <h3><i class="fas fa-info-circle" style="color:var(--primary)"></i> Transfer Details</h3>
                    </div>
                    <div class="card-body">
                        <div class="form-row cols-2">
                            <div class="form-group">
                                <label class="form-label">Source Warehouse <span class="req">*</span></label>
                                @if($sourceWarehouse)
                                    {{-- Non-admin: fixed to their warehouse --}}
                                    <input type="text" class="form-control" value="{{ $sourceWarehouse->name }}" readonly>
                                    <input type="hidden" name="from_warehouse_id" value="{{ $sourceWarehouse->id }}">
                                @else
                                    <select name="from_warehouse_id" id="from_warehouse_id" class="form-control" required onchange="loadSourceItems(); syncWarehouseOptions()">
                                        <option value="">— Select Source —</option>
                                        @foreach($warehouses as $wh)
                                        <option value="{{ $wh->id }}" {{ old('from_warehouse_id') == $wh->id ? 'selected' : '' }}>{{ $wh->name }}</option>
                                        @endforeach
                                    </select>
                                @endif
                            </div>

                            <div class="form-group">
                                <label class="form-label">Destination Warehouse <span class="req">*</span></label>
                                <select name="to_warehouse_id" id="to_warehouse_id" class="form-control" required onchange="syncWarehouseOptions()">
                                    <option value="">— Select Destination —</option>
                                    @foreach($warehouses as $wh)
                                    <option value="{{ $wh->id }}" {{ old('to_warehouse_id') == $wh->id ? 'selected' : '' }}>{{ $wh->name }}</option>
                                    @endforeach
                                </select>
                                <div id="wh-diff-hint" style="display:none;color:var(--danger);font-size:12px;margin-top:4px">
                                    <i class="fas fa-exclamation-circle"></i> Source and destination must be different
                                </div>
                            </div>
                        </div>

                        <div class="form-row cols-2">
                            <div class="form-group">
                                <label class="form-label">Transfer Date <span class="req">*</span></label>
                                <input type="date" name="transfer_date" class="form-control" value="{{ old('transfer_date', date('Y-m-d')) }}" required>
                            </div>
                            <div class="form-group" style="margin-bottom:0">
                                <label class="form-label">Remarks</label>
                                <textarea name="remarks" class="form-control" rows="2" placeholder="Optional notes...">{{ old('remarks') }}</textarea>
                            </div>
                        </div>
                    </div>
                </section>

                {{-- Line Items --}}
                <section class="card form-section">
                    <div class="card-header">
                        <h3><i class="fas fa-list" style="color:var(--primary)"></i> Items to Transfer</h3>
                        <button type="button" class="btn btn-sm btn-primary" onclick="addTransferRow()"><i class="fas fa-plus"></i> Add Item</button>
                    </div>
                    <div class="card-body" style="padding:0">
                        <div class="table-wrapper">
                            <table class="line-items-table" id="transfer-items-table">
                                <thead>
                                    <tr>
                                        <th style="width:45%">Item <span class="hint">(with stock details)</span></th>
                                        <th style="width:18%">Qty to Transfer</th>
                                        <th style="width:18%">Unit Cost</th>
                                        <th style="width:15%">Total</th>
                                        <th style="width:4%"></th>
                                    </tr>
                                </thead>
                                <tbody id="transfer-items-body">
                                    {{-- Rows injected by JS --}}
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="3" style="text-align:right;font-weight:600;padding:12px 16px;background:#f8fafc">Grand Total:</td>
                                        <td style="font-weight:700;padding:12px 16px;background:#f8fafc" id="transfer-grand-total">₱0.00</td>
                                        <td style="background:#f8fafc"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </section>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeTransferModal()"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" class="btn btn-primary" id="transfer-submit-btn" style="min-width:160px;justify-content:center"><i class="fas fa-save"></i> Save Transfer</button>
            </div>
        </form>
    </div>
</div>




@push('scripts')
<script>
// Transfer modal logic
let transferSourceItems = @json($sourceItems ?? []);
let transferRowIndex = 0;

function openTransferModal() {
    const m = document.getElementById('createTransferModal');
    if (!m) return;
    m.classList.add('open');
    document.body.classList.add('modal-open');
    document.addEventListener('keydown', onTransferModalEsc);

    // Ensure the modal is visible before measuring/enhancing selects
    // (the searchable component needs correct bounding rects)
    requestAnimationFrame(function () {
        // Add first row if empty — use a timeout to ensure SS is ready
        if (document.querySelectorAll('#transfer-items-body tr').length === 0) {
            addTransferRow();
        } else {
            // Re-enhance any existing rows that may have lost their wrapper
            // (e.g., after bfcache restore or previous close without reset)
            document.querySelectorAll('select.transfer-item-select').forEach(function (sel) {
                if (!sel.ss && window.SS && typeof window.SS.refresh === 'function') {
                    window.SS.refresh(sel.parentNode);
                }
                if (sel.ss) sel.ss.sync();
            });
            // Refresh the current source items for existing rows
            const fromWhExisting = document.getElementById('from_warehouse_id') || document.querySelector('input[name="from_warehouse_id"]');
            if (fromWhExisting && fromWhExisting.value) {
                // Re-populate with current transferSourceItems if already loaded
                if (transferSourceItems && transferSourceItems.length) {
                    document.querySelectorAll('select.transfer-item-select').forEach(function (sel) {
                        const cur = sel.value;
                        populateTransferItemSelect(sel);
                        // Restore only if still valid
                        if (cur && transferSourceItems.some(function (it) { return String(it.id) === String(cur); })) {
                            sel.value = cur;
                        } else {
                            sel.value = '';
                        }
                        if (sel.ss) sel.ss.sync();
                    });
                } else {
                    loadSourceItems();
                }
            }
        }

        // Load items if admin and warehouse already selected and not yet loaded
        @if(auth()->user()->hasAdminAccess())
        const fromWh = document.getElementById('from_warehouse_id');
        if (fromWh && fromWh.value && (!transferSourceItems || !transferSourceItems.length)) {
            loadSourceItems();
        }
        @endif

        syncWarehouseOptions();
    });
}

function closeTransferModal() {
    const m = document.getElementById('createTransferModal');
    if (!m) return;
    m.classList.remove('open');
    document.body.classList.remove('modal-open');
    document.removeEventListener('keydown', onTransferModalEsc);
}

function onTransferModalEsc(e) {
    if (e.key === 'Escape') closeTransferModal();
}

// Load items from source warehouse — refreshes every Item dropdown so it
// only shows stock from the selected source warehouse and remains a
// fully searchable/clickable combobox. Handles both admin (select) and
// non-admin (hidden input) source warehouse fields.
function loadSourceItems() {
    const fromSelect = document.getElementById('from_warehouse_id');
    const fromInput  = document.querySelector('input[name="from_warehouse_id"]');
    const warehouseId = (fromSelect && fromSelect.value) || (fromInput && fromInput.value) || '';
    const allItemSelects = document.querySelectorAll('select.transfer-item-select');

    if (!warehouseId) {
        transferSourceItems = [];
        // Clear every Item dropdown and reset dependent fields so no stale
        // stock from a previous warehouse remains visible.
        // Use `select.` prefix — the searchable wrapper also carries the class.
        allItemSelects.forEach(function (sel) {
            const idx = sel.name ? sel.name.match(/\[(\d+)\]/)?.[1] : null;
            populateTransferItemSelect(sel);
            sel.value = '';
            sel.disabled = false;
            // Ensure the searchable wrapper is (re)initialized and synced
            if (window.SS && typeof window.SS.refresh === 'function') {
                // Force re-enhance if the component was destroyed
                if (!sel.ss) window.SS.refresh(sel.parentNode);
            }
            if (sel.ss) sel.ss.sync();
            if (idx !== null) {
                const u = document.getElementById('transfer-unit-' + idx);
                const a = document.getElementById('transfer-avail-' + idx);
                const c = document.getElementById('transfer-cost-' + idx);
                const t = document.getElementById('transfer-total-' + idx);
                if (u) u.value = '';
                if (a) a.value = '';
                if (c) c.value = '';
                if (t) t.value = '';
            }
        });
        recalcTransferGrandTotal();
        return;
    }

    // Show a temporary loading placeholder and disable the selects so the
    // user cannot open an empty/stale dropdown while the fetch is in flight.
    allItemSelects.forEach(function (sel) {
        // Use DOM API to avoid breaking the searchable wrapper
        sel.innerHTML = '';
        const loadingOpt = document.createElement('option');
        loadingOpt.value = '';
        loadingOpt.textContent = '— Loading items… —';
        sel.appendChild(loadingOpt);
        sel.disabled = true;
        if (sel.ss) sel.ss.sync();
        else if (window.SS && typeof window.SS.refresh === 'function') window.SS.refresh(sel.parentNode);
    });

    fetch(`{{ route('transfers.items_for_warehouse') }}?warehouse_id=${encodeURIComponent(warehouseId)}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        credentials: 'same-origin'
    })
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function (data) {
            transferSourceItems = Array.isArray(data) ? data : [];
            document.querySelectorAll('select.transfer-item-select').forEach(function (sel) {
                // Preserve the selection only if that item still belongs to the
                // newly selected warehouse; otherwise reset to placeholder.
                const prevVal = sel.value;
                const stillExists = prevVal && transferSourceItems.some(function (it) { return String(it.id) === String(prevVal); });
                sel.disabled = false;
                populateTransferItemSelect(sel);
                // Ensure wrapper exists (re-enhance if needed) before sync
                if (!sel.ss && window.SS && typeof window.SS.refresh === 'function') {
                    window.SS.refresh(sel.parentNode);
                }
                if (stillExists) {
                    sel.value = prevVal;
                    if (sel.ss) sel.ss.sync();
                    const idx = sel.name.match(/\[(\d+)\]/)?.[1];
                    if (idx !== undefined) onTransferItemChange(sel, idx);
                } else {
                    sel.value = '';
                    if (sel.ss) sel.ss.sync();
                    const idx2 = sel.name.match(/\[(\d+)\]/)?.[1];
                    if (idx2 !== undefined) {
                        const u2 = document.getElementById('transfer-unit-' + idx2);
                        const a2 = document.getElementById('transfer-avail-' + idx2);
                        const c2 = document.getElementById('transfer-cost-' + idx2);
                        const t2 = document.getElementById('transfer-total-' + idx2);
                        if (u2) u2.value = '';
                        if (a2) a2.value = '';
                        if (c2) c2.value = '';
                        if (t2) t2.value = '';
                    }
                }
            });
            recalcTransferGrandTotal();
        })
        .catch(function () {
            // On error, re-enable with an empty list and a retry hint.
            transferSourceItems = [];
            document.querySelectorAll('select.transfer-item-select').forEach(function (sel) {
                sel.disabled = false;
                populateTransferItemSelect(sel);
                // Replace placeholder with an error hint
                if (sel.options.length) sel.options[0].textContent = '— Failed to load items —';
                if (!sel.ss && window.SS && typeof window.SS.refresh === 'function') window.SS.refresh(sel.parentNode);
                if (sel.ss) sel.ss.sync();
            });
        });
}

// Sync warehouse options (disable same warehouse selection)
function syncWarehouseOptions() {
    const fromEl = document.getElementById('from_warehouse_id');
    const toEl   = document.getElementById('to_warehouse_id');
    if (!toEl) return;

    const fromId = fromEl
        ? String(fromEl.value || '')
        : String(document.querySelector('input[name="from_warehouse_id"]')?.value || '');

    // Disable matching warehouse
    toEl.querySelectorAll('option').forEach(opt => {
        opt.disabled = !!(opt.value && opt.value === fromId);
    });

    if (fromEl) {
        fromEl.querySelectorAll('option').forEach(opt => {
            opt.disabled = !!(opt.value && opt.value === String(toEl.value || ''));
        });
    }

    // Show hint if same
    const hint = document.getElementById('wh-diff-hint');
    if (String(toEl.value || '') === fromId && fromId) {
        toEl.value = '';
        if (hint) hint.style.display = 'block';
    } else if (hint) {
        hint.style.display = 'none';
    }
}

function populateTransferItemSelect(selectEl) {
    if (!selectEl) return;
    const currentVal = selectEl.value;
    const isDisabled = selectEl.disabled;
    // Clear and rebuild with proper <option> elements
    selectEl.innerHTML = '';
    const ph = document.createElement('option');
    ph.value = '';
    ph.textContent = '— Select Item —';
    selectEl.appendChild(ph);
    (transferSourceItems || []).forEach(item => {
        const opt = document.createElement('option');
        opt.value = item.id;
        // Use enhanced display text from API
        opt.textContent = item.display_text || item.description;
        opt.dataset.unit = item.unit;
        opt.dataset.unitCost = item.unit_cost;
        opt.dataset.engasUnitCost = item.engas_unit_cost || 'null';
        opt.dataset.available = item.quantity;
        selectEl.appendChild(opt);
    });
    // Restore value only if it still exists in the new list
    if (currentVal) {
        const exists = Array.from(selectEl.options).some(function (o) { return o.value === String(currentVal); });
        if (exists) selectEl.value = String(currentVal);
        else selectEl.value = '';
    }
    // Ensure the searchable wrapper exists and sync
    if (!selectEl.ss && window.SS && typeof window.SS.refresh === 'function') {
        window.SS.refresh(selectEl.parentNode);
    }
    if (window.SS && typeof window.SS.sync === 'function') window.SS.sync(selectEl);
    if (selectEl.ss && typeof selectEl.ss.sync === 'function') selectEl.ss.sync();
    if (selectEl.ss && typeof selectEl.ss.renderOptions === 'function' && selectEl.ss.open) {
        selectEl.ss.renderOptions();
    }
    selectEl.disabled = isDisabled;
    if (window.SS && typeof window.SS.sync === 'function') window.SS.sync(selectEl);
}

function addTransferRow() {
    const tbody = document.getElementById('transfer-items-body');
    const idx = transferRowIndex++;
    const tr = document.createElement('tr');
    tr.id = `transfer-row-${idx}`;
    tr.innerHTML = `
        <td data-label="Item">
            <select name="items[${idx}][item_id]" class="transfer-item-select form-control" required onchange="onTransferItemChange(this, ${idx})" data-placeholder="— Select Item —">
                <option value="">— Select Item —</option>
            </select>
        </td>
        <td data-label="Qty to Transfer">
            <input type="number" name="items[${idx}][quantity]" id="transfer-qty-${idx}"
                   step="1" min="1" placeholder="0" required oninput="recalcTransferRow(${idx})">
        </td>
        <td data-label="Unit Cost">
            <input type="number" name="items[${idx}][unit_cost]" id="transfer-cost-${idx}"
                   step="0.01" min="0" placeholder="0.00" required oninput="recalcTransferRow(${idx})">
        </td>
        <td data-label="Total"><input type="text" id="transfer-total-${idx}" readonly placeholder="0.00"></td>
        <td>
            <button type="button" class="remove-row" onclick="removeTransferRow(${idx})" title="Remove">
                <i class="fas fa-times"></i>
            </button>
        </td>
    `;
    // Hidden fields to store unit and available quantity for validation
    const hiddenUnit = document.createElement('input');
    hiddenUnit.type = 'hidden';
    hiddenUnit.id = `transfer-unit-${idx}`;
    tr.appendChild(hiddenUnit);
    
    const hiddenAvail = document.createElement('input');
    hiddenAvail.type = 'hidden';
    hiddenAvail.id = `transfer-avail-${idx}`;
    tr.appendChild(hiddenAvail);
    
    tbody.appendChild(tr);
    const newSel = tr.querySelector('.transfer-item-select');
    // Ensure the global searchable select is initialized for this dynamic row
    // before we populate it, so the visible button is created.
    if (window.SS && typeof window.SS.refresh === 'function') {
        window.SS.refresh(tr);
    }
    populateTransferItemSelect(newSel);
    // Force a sync after the wrapper is created (MutationObserver is async)
    // so the placeholder is visible immediately even before the observer fires.
    if (newSel && !newSel.ss) {
        // Fallback: if SS hasn't enhanced yet, do it on next tick
        setTimeout(function () {
            if (window.SS && typeof window.SS.refresh === 'function') window.SS.refresh(tr);
            if (newSel.ss) newSel.ss.sync();
            else populateTransferItemSelect(newSel);
        }, 0);
    }
}

function onTransferItemChange(sel, idx) {
    const opt = sel.options[sel.selectedIndex];
    // Store in hidden fields for validation
    document.getElementById(`transfer-unit-${idx}`).value  = opt.dataset.unit || '';
    document.getElementById(`transfer-avail-${idx}`).value = opt.dataset.available || '';
    // Auto-fill unit cost
    document.getElementById(`transfer-cost-${idx}`).value = opt.dataset.unitCost || '';
    recalcTransferRow(idx);
}

function recalcTransferRow(idx) {
    const qty  = parseFloat(document.getElementById(`transfer-qty-${idx}`)?.value) || 0;
    const cost = parseFloat(document.getElementById(`transfer-cost-${idx}`)?.value) || 0;
    const total = qty * cost;
    const totalEl = document.getElementById(`transfer-total-${idx}`);
    if (totalEl) totalEl.value = '₱' + total.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    recalcTransferGrandTotal();
}

function recalcTransferGrandTotal() {
    let grand = 0;
    document.querySelectorAll('[id^="transfer-total-"]').forEach(el => {
        grand += parseFloat(el.value.replace(/[^0-9.]/g, '')) || 0;
    });
    document.getElementById('transfer-grand-total').textContent = '₱' + grand.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
}

function removeTransferRow(idx) {
    const row = document.getElementById(`transfer-row-${idx}`);
    if (row) row.remove();
    recalcTransferGrandTotal();
}

// Validate qty vs available before submit
let transferSubmitting = false;
document.getElementById('transfer-form').addEventListener('submit', function(e) {
    let valid = true;
    
    if (transferSubmitting) {
        e.preventDefault();
        return;
    }
    
    document.querySelectorAll('[id^="transfer-qty-"]').forEach(qtyEl => {
        const idx = qtyEl.id.replace('transfer-qty-', '');
        const avail = parseFloat(document.getElementById(`transfer-avail-${idx}`)?.value) || 0;
        const qty   = parseFloat(qtyEl.value) || 0;
        if (qty > avail) {
            alert(`Quantity exceeds available stock (${avail}) for one of the items.`);
            valid = false;
        }
    });
    
    if (!valid) {
        e.preventDefault();
        return;
    }
    
    // Disable submit button and show loading
    transferSubmitting = true;
    const btn = document.getElementById('transfer-submit-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
});

// Click overlay to close
document.addEventListener('DOMContentLoaded', function() {
    const overlay = document.getElementById('createTransferModal');
    if (!overlay) return;
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) closeTransferModal();
    });
});

@if($errors->any())
// Open modal if validation errors
document.addEventListener('DOMContentLoaded', openTransferModal);
@endif
</script>
@endpush
