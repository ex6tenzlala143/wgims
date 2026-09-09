@push('styles')
<style>
    .dd-modal-overlay {
        position: fixed;
        inset: 0;
        z-index: 1350;
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
    .dd-modal-overlay.open { opacity: 1; visibility: visible; }
    .dd-modal-shell {
        width: 100%;
        max-width: 480px;
        background: #ffffff;
        border-radius: 14px;
        box-shadow: 0 24px 70px rgba(2, 6, 23, 0.35);
        overflow: hidden;
        transform: translateY(28px) scale(0.985);
        opacity: 0;
        transition: transform 0.28s cubic-bezier(0.2, 0.8, 0.25, 1), opacity 0.2s ease;
    }
    .dd-modal-overlay.open .dd-modal-shell { transform: none; opacity: 1; }
    .dd-modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 16px 24px;
        border-bottom: 1px solid var(--border);
        background: linear-gradient(180deg, #ffffff, #fff5f5);
    }
    .dd-modal-header h2 {
        font-size: 18px;
        font-weight: 700;
        color: var(--danger);
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0;
    }
    .dd-modal-close {
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
        transition: all 0.15s;
    }
    .dd-modal-close:hover { background: #fee2e2; color: var(--danger); }
    .dd-modal-body { padding: 20px 24px; background: #f8fafc; }
    .dd-item-ref {
        font-size: 13px;
        font-weight: 600;
        color: var(--text);
        background: #ffffff;
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 10px 14px;
        margin-bottom: 14px;
        word-break: break-word;
    }
    .dd-warning-list {
        margin: 0;
        padding: 12px 14px 12px 34px;
        background: #fffbeb;
        border: 1px solid #fde68a;
        border-radius: 8px;
        font-size: 13px;
        color: #92400e;
        line-height: 1.7;
    }
    .dd-warning-list strong { color: #78350f; }
    .dd-modal-footer {
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 12px;
        padding: 14px 24px;
        border-top: 1px solid var(--border);
        background: #ffffff;
    }
</style>
@endpush

<div class="dd-modal-overlay" id="dispatch-delete-modal" role="dialog" aria-modal="true" aria-labelledby="dispatch-delete-title">
    <div class="dd-modal-shell">
        <div class="dd-modal-header">
            <h2 id="dispatch-delete-title"><i class="fas fa-triangle-exclamation"></i> Delete Dispatched Item?</h2>
            <button type="button" class="dd-modal-close" onclick="closeDispatchDeleteModal()" aria-label="Close">&times;</button>
        </div>
        <div class="dd-modal-body">
            <div class="dd-item-ref" id="dispatch-delete-ref">—</div>
            <ul class="dd-warning-list">
                <li>The dispatched quantity will be <strong>removed from this RIS</strong>.</li>
                <li>The quantity will be <strong>returned to the originating stock record</strong> (exact item, subsidy, cost and expiry).</li>
                <li>The <strong>Stock Card</strong> entry for this issuance will be removed and running balances recalculated.</li>
                <li><strong>Inventory Balance</strong> will reflect the restored quantity.</li>
                <li>This action may affect inventory records and <strong>cannot be undone</strong>.</li>
            </ul>
        </div>
        <div class="dd-modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeDispatchDeleteModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <form method="POST" action="#" id="dispatch-delete-form">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-trash"></i> Yes, Delete &amp; Restore Stock
                </button>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    var DELETING = false;
    var overlay = document.getElementById('dispatch-delete-modal');
    var form = document.getElementById('dispatch-delete-form');

    function $(id) { return document.getElementById(id); }

    window.openDispatchDeleteModal = function (id) {
        if (DELETING || !overlay) return;
        var btn = document.querySelector('.dd-delete-btn[data-dispatch-id="' + id + '"]');
        var label = btn ? (btn.getAttribute('data-label') || 'Dispatched item') : 'Dispatched item';
        var qty = btn ? (btn.getAttribute('data-qty') || '') : '';
        $('dispatch-delete-ref').textContent = label + (qty ? (' — issued qty: ' + qty) : '');
        form.action = '{{ route("requisitions.dispatch_destroy", ["dispatch" => "__ID__"]) }}'.replace('__ID__', id);
        overlay.classList.add('open');
        document.body.classList.add('modal-open');
    };

    window.closeDispatchDeleteModal = function () {
        if (!overlay) return;
        overlay.classList.remove('open');
        document.body.classList.remove('modal-open');
        DELETING = false;
        var btn = form ? form.querySelector('button[type="submit"]') : null;
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-trash"></i> Yes, Delete &amp; Restore Stock'; }
    };

    if (overlay) {
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeDispatchDeleteModal();
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay && overlay.classList.contains('open')) closeDispatchDeleteModal();
    });

    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (DELETING) return;

            var ok = window.confirm(
                'Delete Dispatched Item?\n\n' +
                'The dispatched quantity will be removed from this RIS,\n' +
                'returned to the originating stock, and the Stock Card /\n' +
                'Inventory Balance will be adjusted.\n\n' +
                'This cannot be undone. Continue?'
            );
            if (!ok) return;

            DELETING = true;
            var btn = form.querySelector('button[type="submit"]');
            var orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Reversing...';

            fetch(form.action, {
                method: 'DELETE',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')
                        ? document.querySelector('meta[name="csrf-token"]').content
                        : ''
                }
            })
                .then(function (res) { return res.json().then(function (d) { return { ok: res.ok, d: d }; }); })
                .then(function (r) {
                    if (r.ok && r.d.redirect) {
                        window.location.href = r.d.redirect;
                        return;
                    }
                    DELETING = false;
                    btn.disabled = false;
                    btn.innerHTML = orig;
                    var msg = (r.d && r.d.errors && r.d.errors.general && r.d.errors.general[0]) || 'Deletion failed. Please try again.';
                    alert(msg);
                })
                .catch(function () {
                    DELETING = false;
                    btn.disabled = false;
                    btn.innerHTML = orig;
                    alert('Deletion failed. Please try again.');
                });
        });
    }
})();
</script>
@endpush
