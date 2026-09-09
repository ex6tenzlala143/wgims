{{-- Shared "source subsidy" details modal. Rendered once (via the layout) and
     opened by any badge with the .subsidy-source-badge class through a delegated
     click handler — no per-page wiring needed. The <style> block is intentionally
     inline (not @push'd): this include runs after the head's @stack('styles'). --}}

<style>
    .subsidy-modal-overlay {
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
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.25s ease, visibility 0.25s ease;
    }
    .subsidy-modal-overlay.open { opacity: 1; visibility: visible; }
    .subsidy-modal-shell {
        width: 100%;
        max-width: 460px;
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
    .subsidy-modal-overlay.open .subsidy-modal-shell { transform: none; opacity: 1; }
    .subsidy-modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 16px 24px;
        border-bottom: 1px solid var(--border);
        background: linear-gradient(180deg, #ffffff, #f9fbfd);
        flex-shrink: 0;
    }
    .subsidy-modal-header h2 {
        font-size: 18px;
        font-weight: 700;
        color: var(--text);
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0;
    }
    .subsidy-modal-header h2 i { color: var(--danger); }
    .subsidy-modal-subtitle { font-size: 12.5px; color: var(--text-muted); margin-top: 3px; line-height: 1.5; }
    .subsidy-modal-close {
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
    .subsidy-modal-close:hover { background: #fee2e2; color: var(--danger); }
    .subsidy-modal-body {
        padding: 20px 24px;
        background: #f8fafc;
    }
    .subsidy-detail-row {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 16px;
        padding: 10px 0;
        border-bottom: 1px solid var(--border);
    }
    .subsidy-detail-row:last-of-type { border-bottom: none; }
    .subsidy-detail-label {
        font-size: 13px;
        font-weight: 600;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.4px;
        flex-shrink: 0;
    }
    .subsidy-detail-value {
        font-size: 13px;
        font-weight: 700;
        color: var(--text);
        text-align: right;
        word-break: break-word;
    }
    .subsidy-detail-note {
        margin-top: 16px;
        padding: 12px 14px;
        background: var(--warning-bg);
        border: 1px solid #fde68a;
        border-radius: 8px;
        font-size: 13px;
        color: var(--warning-text);
        line-height: 1.6;
    }
    .subsidy-detail-note i { margin-right: 6px; }
    .subsidy-modal-footer {
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

    @media (max-width: 640px) {
        .subsidy-modal-overlay { padding: 10px; }
        .subsidy-modal-shell { width: 100%; }
        .subsidy-modal-header { padding: 12px 16px; }
        .subsidy-modal-body { padding: 14px 16px; }
        .subsidy-modal-footer { padding: 12px 16px; }
    }
</style>

<div class="subsidy-modal-overlay" id="subsidyDetailsModal" aria-hidden="true">
    <div class="subsidy-modal-shell" role="dialog" aria-modal="true" aria-labelledby="subsidyDetailsTitle">
        <div class="subsidy-modal-header">
            <div style="min-width:0">
                <h2 id="subsidyDetailsTitle"><i class="fas fa-exclamation-triangle"></i> Deleted Subsidy</h2>
            </div>
            <button type="button" class="subsidy-modal-close" onclick="closeSubsidyDetailsModal()" aria-label="Close"><i class="fas fa-times"></i></button>
        </div>
        <div class="subsidy-modal-body">
            <div class="subsidy-detail-row">
                <span class="subsidy-detail-label">Source Status</span>
                <span id="subsidy-detail-status" class="subsidy-detail-value"></span>
            </div>
            <div class="subsidy-detail-row">
                <span class="subsidy-detail-label">Subsidy ID</span>
                <span id="subsidy-detail-code" class="subsidy-detail-value"></span>
            </div>
            <div class="subsidy-detail-row">
                <span class="subsidy-detail-label">Subsidy / RIS Reference</span>
                <span id="subsidy-detail-ris" class="subsidy-detail-value"></span>
            </div>
            <div class="subsidy-detail-row">
                <span class="subsidy-detail-label">Subsidy DR Number</span>
                <span id="subsidy-detail-dr" class="subsidy-detail-value"></span>
            </div>
            <div class="subsidy-detail-note">
                <i class="fas fa-info-circle"></i>
                The inventory movement created by this record has been preserved. Review the source
                Subsidy before making decisions about this record.
            </div>
        </div>
        <div class="subsidy-modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeSubsidyDetailsModal()"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    'use strict';
    var modal = document.getElementById('subsidyDetailsModal');
    if (!modal) return;

    function open(status, ris, dr, code) {
        var label = 'Deleted';

        var title = document.getElementById('subsidyDetailsTitle');
        if (title) title.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Deleted Subsidy';

        var stat = document.getElementById('subsidy-detail-status');
        if (stat) stat.innerHTML = '<span class="badge badge-danger">Deleted</span>';

        var codeEl = document.getElementById('subsidy-detail-code');
        if (codeEl) codeEl.textContent = code || '—';

        var risEl = document.getElementById('subsidy-detail-ris');
        if (risEl) risEl.textContent = ris || '—';

        var drEl = document.getElementById('subsidy-detail-dr');
        if (drEl) drEl.textContent = dr || '—';

        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
        document.addEventListener('keydown', onEsc);
    }

    function close() {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
        document.removeEventListener('keydown', onEsc);
    }

    function onEsc(e) {
        if (e.key === 'Escape') close();
    }

    // Delegated: any .subsidy-source-badge anywhere on the page opens the modal.
    document.addEventListener('click', function (e) {
        var badge = e.target.closest('.subsidy-source-badge');
        if (!badge) return;
        e.preventDefault();
        open(
            badge.getAttribute('data-sub-status') || 'deleted',
            badge.getAttribute('data-sub-ris') || '',
            badge.getAttribute('data-sub-dr') || '',
            badge.getAttribute('data-sub-code') || ''
        );
    });

    // Backdrop click closes the modal (only when the shell itself is untouched).
    modal.addEventListener('click', function (e) {
        if (e.target === modal) close();
    });

    window.closeSubsidyDetailsModal = close;
})();
</script>
@endpush
