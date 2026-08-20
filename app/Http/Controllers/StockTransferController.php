<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesWarehouse;
use App\Models\DeliverySubsidy;
use App\Models\Item;
use App\Models\StockCardEntry;
use App\Models\StockTransfer;
use App\Models\StockTransferAuditLog;
use App\Models\StockTransferItem;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class StockTransferController extends Controller
{
    use ScopesWarehouse;

    /** Temporary property to pass the new transfer id out of the transaction closure. */
    private int $createdTransferId;

    /**
     * Paginated list of transfers, scoped by user role.
     */
    public function index(Request $request)
    {
        $user  = Auth::user();
        $query = StockTransfer::with(['fromWarehouse', 'toWarehouse', 'transferredBy', 'deliverySubsidy'])
            ->withCount('items')
            ->withSum('items as total_requested', 'quantity_requested')
            ->withSum('items as total_transferred', 'quantity')
            ->orderByDesc('transfer_date')
            ->orderByDesc('id');

        // Non-admin users only see transfers involving their assigned warehouses
        $this->applyTransferWarehouseScope($query, $user);

        // Optional filters (admin only for warehouse filters)
        if ($request->filled('from_warehouse_id') && $user->hasAdminAccess()) {
            $query->where('from_warehouse_id', $request->from_warehouse_id);
        }
        if ($request->filled('to_warehouse_id') && $user->hasAdminAccess()) {
            $query->where('to_warehouse_id', $request->to_warehouse_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('transfer_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('transfer_date', '<=', $request->date_to);
        }

        // Free-text search: transfer number, original Subsidy/RIS/DR reference,
        // Subsidy ID, or source/destination warehouse name (case-insensitive
        // partial match via LIKE, always server-side).
        if ($request->filled('q')) {
            $q = trim((string) $request->q);
            $query->where(function ($qq) use ($q) {
                $qq->where('transfer_number', 'like', "%{$q}%")
                    ->orWhere('source_ris_number', 'like', "%{$q}%")
                    ->orWhere('source_dr_number', 'like', "%{$q}%")
                    ->orWhere('source_subsidy_code', 'like', "%{$q}%")
                    ->orWhereHas('fromWarehouse', fn ($w) => $w->where('name', 'like', "%{$q}%"))
                    ->orWhereHas('toWarehouse', fn ($w) => $w->where('name', 'like', "%{$q}%"));
            });
        }

        // Dedicated filter: transfers whose source Subsidy was deleted/archived
        if ($request->filled('related_to_deleted_subsidy')) {
            if ($request->related_to_deleted_subsidy === 'yes') {
                $query->whereIn('source_subsidy_status', ['deleted', 'archived']);
            } elseif ($request->related_to_deleted_subsidy === 'no') {
                $query->whereNotIn('source_subsidy_status', ['deleted', 'archived']);
            }
        }

        $transfers  = $query->paginate(20)->withQueryString();
        $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get();

        // Data for the create modal
        if ($user->hasAdminAccess()) {
            $sourceWarehouse = null;
            $sourceItems = collect();
        } else {
            $warehouseIds = $this->getUserWarehouseIds($user);
            $primaryId = $user->warehouse_id ?? ($warehouseIds[0] ?? null);
            $sourceWarehouse = $primaryId ? Warehouse::find($primaryId) : null;

            $sourceItems = $sourceWarehouse
                ? Item::where('warehouse_id', $sourceWarehouse->id)
                    ->where('quantity', '>', 0)
                    ->where('is_active', true)
                    ->orderBy('description')
                    ->get()
                : collect();
        }

        return view('transfers.index', compact('transfers', 'warehouses', 'sourceWarehouse', 'sourceItems'));
    }

    /**
     * Show the create transfer form.
     * NOTE: Removed - Stock Transfer creation now uses modal in index page.
     */

    /**
     * Store a new transfer — validate, execute atomically, redirect.
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        if ($user->role === User::ROLE_STAFF) {
            abort(403);
        }

        if ($user->isCenterUser() && ! $this->userCanAccessWarehouse($user, (int) $request->from_warehouse_id)) {
            abort(403);
        }

        $request->validate([
            'from_warehouse_id' => 'required|exists:warehouses,id',
            'to_warehouse_id'   => 'required|exists:warehouses,id|different:from_warehouse_id',
            'transfer_date'     => 'required|date',
            'remarks'           => 'nullable|string|max:1000',
            'items'             => 'required|array|min:1',
            'items.*.item_id'   => 'required|exists:items,id',
            'items.*.quantity'  => 'required|numeric|min:0.0001',
            'items.*.unit_cost' => 'required|numeric|min:0.01',
        ], [
            'to_warehouse_id.different' => 'Source warehouse and destination warehouse must be different.',
        ]);

        $fromWarehouse = Warehouse::findOrFail($request->from_warehouse_id);
        $toWarehouse   = Warehouse::findOrFail($request->to_warehouse_id);

        // Validate items belong to source warehouse
        foreach ($request->items as $line) {
            $sourceItem = Item::find($line['item_id']);
            if (! $sourceItem || (int) $sourceItem->warehouse_id !== (int) $request->from_warehouse_id) {
                return back()->withInput()->with(
                    'error',
                    "Item \"{$sourceItem?->description}\" does not belong to the selected source warehouse."
                );
            }
            if ((float) $line['quantity'] > $sourceItem->quantity) {
                return back()->withInput()->with(
                    'error',
                    "Insufficient stock for \"{$sourceItem->description}\": requested {$line['quantity']}, available {$sourceItem->quantity}."
                );
            }
        }

        try {
            DB::transaction(function () use ($request, $fromWarehouse, $toWarehouse, $user) {
                $transferNumber = StockTransfer::generateTransferNumber();

                // Trace this transfer back to the subsidy that delivered the source
                // stock (the source item carries the subsidy's RIS number). The
                // reference is snapshotted so it survives a later subsidy deletion.
                $linkedSubsidy = null;
                $linkedRis    = null;
                foreach ($request->items as $line) {
                    $sourceItem = Item::find($line['item_id'] ?? null);
                    if (! $sourceItem) {
                        continue;
                    }
                    // Prefer the exact originating Subsidy — never guess. Only
                    // legacy records without an attribution fall back to a
                    // RIS-number lookup.
                    $linkedSubsidy = $sourceItem->source_subsidy_id
                        ? DeliverySubsidy::find($sourceItem->source_subsidy_id)
                        : ($sourceItem->ris_number
                            ? DeliverySubsidy::where('ris_number', $sourceItem->ris_number)
                                ->orderByDesc('id')
                                ->first()
                            : null);
                    if ($linkedSubsidy) {
                        $linkedRis = $sourceItem->ris_number;
                        break;
                    }
                }

                $transfer = StockTransfer::create([
                    'transfer_number'      => $transferNumber,
                    'delivery_subsidy_id'  => $linkedSubsidy?->id,
                    'source_ris_number'    => $linkedRis,
                    'source_dr_number'     => $linkedSubsidy?->dr_number,
                    'source_subsidy_code'  => $linkedSubsidy?->subsidy_code,
                    'source_subsidy_status'=> null,
                    'from_warehouse_id'    => $fromWarehouse->id,
                    'to_warehouse_id'      => $toWarehouse->id,
                    'transfer_date'        => $request->transfer_date,
                    'transferred_by'       => $user->id,
                    'status'               => 'pending',   // starts as pending — dispatch fills it
                    'remarks'              => $request->remarks,
                ]);

                foreach ($request->items as $line) {
                    $unitCost = round((float) $line['unit_cost'], 2);

                    // Resolve/create the destination item slot now so it exists for dispatching later
                    // (carries the source Subsidy so the origin travels with the stock).
                    $sourceItem = Item::find($line['item_id']);
                    $destItem   = Item::findOrCreateByUnitCost(
                        $toWarehouse->id,
                        $sourceItem->description,
                        $sourceItem->unit,
                        $sourceItem->category,
                        $unitCost,
                        $sourceItem->ris_number,
                        $sourceItem->expiration_date?->format('Y-m-d'),
                        $sourceItem->engas_unit_cost,
                        $sourceItem->account_code,
                        $sourceItem->source_subsidy_id
                    );

                    // Carry the source-subsidy snapshot onto the destination slot
                    // immediately (id, RIS/DR references, code, status) so the
                    // origin is visible even before the stock is dispatched.
                    $destItem->applySubsidySnapshot(
                        $sourceItem->source_subsidy_id,
                        $sourceItem->source_subsidy_ris,
                        $sourceItem->source_subsidy_dr,
                        $sourceItem->source_subsidy_status,
                        $sourceItem->source_subsidy_code
                    );

                    // quantity_requested = planned; quantity = 0 (nothing dispatched yet)
                    StockTransferItem::create([
                        'stock_transfer_id'   => $transfer->id,
                        'item_id'             => $sourceItem->id,
                        'destination_item_id' => $destItem->id,
                        'quantity_requested'  => (float) $line['quantity'],
                        'quantity'            => 0,
                        'unit_cost'           => $unitCost,
                    ]);
                }

                // Notifications
                $adminIds = User::where('role', User::ROLE_ADMIN)->pluck('id');
                foreach ($adminIds as $adminId) {
                    SystemNotification::create([
                        'user_id' => $adminId,
                        'title'   => "Stock Transfer {$transfer->transfer_number}",
                        'message' => "Transfer from {$fromWarehouse->name} to {$toWarehouse->name} created — pending dispatch.",
                        'type'    => 'transfer',
                        'link'    => route('transfers.show', $transfer->id),
                        'is_read' => false,
                    ]);
                }

                $this->createdTransferId = $transfer->id;
            });
        } catch (\Throwable $e) {
            Log::error('Stock transfer creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->withInput()->with('error', 'The transaction could not be completed. No changes were made. Please try again.');
        }

        return redirect()
            ->route('transfers.show', $this->createdTransferId)
            ->with('success', 'Transfer request created. Use "Dispatch Items" to send stock in one or more shipments.');
    }

    /**
     * Show the dispatch form — record actual stock movement against a pending/partial transfer.
     */
    public function dispatch(StockTransfer $transfer)
    {
        $user = Auth::user();

        if ($user->role === User::ROLE_STAFF) {
            abort(403);
        }

        if (! $user->hasAdminAccess() && ! $this->userCanAccessWarehouse($user, $transfer->from_warehouse_id)) {
            abort(403);
        }

        if ($transfer->status === 'completed') {
            return redirect()->route('transfers.show', $transfer)
                ->with('error', 'This transfer is already fully completed.');
        }

        $transfer->load(['fromWarehouse', 'toWarehouse', 'items.sourceItem', 'items.destinationItem']);

        return view('transfers.dispatch', compact('transfer'));
    }

    /**
     * Process a dispatch — move stock for the quantities specified, update status.
     */
    public function processDispatch(Request $request, StockTransfer $transfer)
    {
        $user = Auth::user();

        if ($user->role === User::ROLE_STAFF) {
            abort(403);
        }

        if (! $user->hasAdminAccess() && ! $this->userCanAccessWarehouse($user, $transfer->from_warehouse_id)) {
            abort(403);
        }

        if ($transfer->status === 'completed') {
            return redirect()->route('transfers.show', $transfer)
                ->with('error', 'This transfer is already fully completed.');
        }

        $request->validate([
            'dispatch_date' => 'required|date',
            'items'         => 'required|array|min:1',
            'items.*.sti_id'   => 'required|exists:stock_transfer_items,id',
            'items.*.quantity' => 'required|numeric|min:0',
        ]);

        try {
            DB::transaction(function () use ($request, $transfer) {
                $transfer->load(['fromWarehouse', 'toWarehouse', 'items.sourceItem', 'items.destinationItem']);

                // Re-check the status INSIDE the transaction: two concurrent
                // dispatches that both passed the pre-check above are serialized
                // here by the row locks below, and the second one aborts instead
                // of double-dispatching stock.
                if (StockTransfer::whereKey($transfer->id)->value('status') === 'completed') {
                    throw ValidationException::withMessages([
                        'items' => 'This transfer is already fully completed.',
                    ]);
                }

                foreach ($request->items as $line) {
                    $sti = StockTransferItem::with(['sourceItem', 'destinationItem'])
                        ->where('id', $line['sti_id'])
                        ->where('stock_transfer_id', $transfer->id)
                        ->firstOrFail();

                    $remaining   = max(0, $sti->quantity_requested - $sti->quantity);
                    $dispatchQty = min((float) $line['quantity'], $remaining);

                    if ($dispatchQty <= 0) {
                        continue;
                    }

                    // Lock the exact stock rows before the read-modify-write so
                    // two concurrent dispatches can never lose a quantity update.
                    $sourceItem = Item::whereKey($sti->item_id)->lockForUpdate()->first();
                    $destItem   = Item::whereKey($sti->destination_item_id)->lockForUpdate()->first();

                    if (! $sourceItem || ! $destItem) {
                        continue;
                    }

                // Move stock
                $newSourceQty = max(0, $sourceItem->quantity - $dispatchQty);
                $newDestQty   = $destItem->quantity + $dispatchQty;

                $sourceItem->update(['quantity' => $newSourceQty]);
                $destItem->update(['quantity' => $newDestQty]);

                // Carry the source-subsidy trail onto the destination stock:
                // the destination item keeps the "FROM SUBSIDY" reference so a
                // later subsidy deletion can flag it across warehouses. The
                // subsidy id may be null if the subsidy was already deleted
                // (nullOnDelete) — the RIS/DR/status snapshot still propagates.
                if ($sourceItem->source_subsidy_status) {
                    $destItem->applySubsidySnapshot(
                        $sourceItem->source_subsidy_id,
                        $sourceItem->source_subsidy_ris,
                        $sourceItem->source_subsidy_dr,
                        $sourceItem->source_subsidy_status,
                        $sourceItem->source_subsidy_code
                    );
                }

                // Accumulate dispatched quantity
                $sti->increment('quantity', $dispatchQty);

                // Stock card: transfer_out at source
                StockCardEntry::create([
                    'item_id'            => $sourceItem->id,
                    'entry_date'         => $request->dispatch_date,
                    'reference'          => $transfer->transfer_number,
                    'reference_type'     => 'transfer_out',
                    'reference_id'       => $transfer->id,
                    'receipt_qty'        => 0,
                    'receipt_unit_cost'  => 0,
                    'receipt_total_cost' => 0,
                    'issue_qty'          => $dispatchQty,
                    'balance_qty'        => $newSourceQty,
                    'balance_unit_cost'  => $sourceItem->unit_cost,
                    'balance_total_cost' => $newSourceQty * $sourceItem->unit_cost,
                    'from_to'            => $transfer->toWarehouse->name,
                ]);

                // Stock card: transfer_in at destination
                StockCardEntry::create([
                    'item_id'            => $destItem->id,
                    'entry_date'         => $request->dispatch_date,
                    'reference'          => $transfer->transfer_number,
                    'reference_type'     => 'transfer_in',
                    'reference_id'       => $transfer->id,
                    'receipt_qty'        => $dispatchQty,
                    'receipt_unit_cost'  => $sti->unit_cost,
                    'receipt_total_cost' => $dispatchQty * $sti->unit_cost,
                    'issue_qty'          => 0,
                    'balance_qty'        => $newDestQty,
                    'balance_unit_cost'  => $sti->unit_cost,
                    'balance_total_cost' => $newDestQty * $sti->unit_cost,
                    'from_to'            => $transfer->fromWarehouse->name,
                ]);
            }

            // Refresh items and recalculate status
            $transfer->load('items');
            $transfer->updateTransferStatus();
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Stock transfer dispatch failed', [
                'transfer_id' => $transfer->id,
                'error'       => $e->getMessage(),
                'trace'       => $e->getTraceAsString(),
            ]);

            return back()->withInput()->with('error', 'The transaction could not be completed. No changes were made. Please try again.');
        }

        return redirect()->route('transfers.show', $transfer)
            ->with('success', 'Dispatch recorded and stock updated.');
    }

    /**
     * Show transfer detail.
     */
    public function show(StockTransfer $transfer)
    {
        $user = Auth::user();

        if (! $user->hasAdminAccess()) {
            $ids = $this->getUserWarehouseIds($user);
            $involved = in_array($transfer->from_warehouse_id, $ids ?? [], true)
                     || in_array($transfer->to_warehouse_id, $ids ?? [], true);
            if (! $involved) {
                abort(403);
            }
        }

        $transfer->load(['fromWarehouse', 'toWarehouse', 'transferredBy', 'items.sourceItem', 'items.destinationItem']);

        return view('transfers.show', compact('transfer'));
    }

    /**
     * Printable transfer slip.
     */
    public function print(StockTransfer $transfer)
    {
        $user = Auth::user();

        if (! $user->hasAdminAccess()) {
            $ids = $this->getUserWarehouseIds($user);
            $involved = in_array($transfer->from_warehouse_id, $ids ?? [], true)
                     || in_array($transfer->to_warehouse_id, $ids ?? [], true);
            if (! $involved) {
                abort(403);
            }
        }

        $transfer->load(['fromWarehouse', 'toWarehouse', 'transferredBy', 'items.sourceItem', 'items.destinationItem']);

        return view('transfers.print', compact('transfer'));
    }

    /**
     * API: return items with stock for a given warehouse (used by the create form).
     */
    public function itemsForWarehouse(Request $request)
    {
        $request->validate(['warehouse_id' => 'required|exists:warehouses,id']);

        $items = Item::where('warehouse_id', $request->warehouse_id)
            ->where('quantity', '>', 0)
            ->where('is_active', true)
            ->orderBy('description')
            ->get(['id', 'description', 'unit', 'category', 'unit_cost', 'engas_unit_cost', 'quantity', 'stock_number']);

        return response()->json($items);
    }

    /**
     * Admin: show the edit form for a completed transfer.
     */
    public function edit(StockTransfer $transfer)
    {
        abort_unless(Auth::user()->canWrite(), 403);

        $transfer->load(['fromWarehouse', 'toWarehouse', 'transferredBy', 'items.sourceItem', 'items.destinationItem']);
        $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get();

        return view('transfers.edit', compact('transfer', 'warehouses'));
    }

    /**
     * Admin: apply edits to a transfer and correct stock/stock-card accordingly.
     *
     * Strategy per line:
     *   old_qty = quantity stored in StockTransferItem
     *   new_qty = quantity submitted by admin
     *   delta   = new_qty - old_qty
     *
     *   source item.quantity  -= delta   (transferred more → less at source)
     *   dest   item.quantity  += delta   (transferred more → more at dest)
     *   stock card entries updated to reflect new quantities
     */
    public function update(Request $request, StockTransfer $transfer)
    {
        abort_unless(Auth::user()->canWrite(), 403);

        $request->validate([
            'transfer_date' => 'required|date',
            'remarks'       => 'nullable|string|max:1000',
            'items'         => 'required|array|min:1',
            'items.*.sti_id'   => 'required|exists:stock_transfer_items,id',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.unit_cost' => 'required|numeric|min:0.01',
        ]);

        try {
            DB::transaction(function () use ($request, $transfer) {
                $transfer->update([
                    'transfer_date' => $request->transfer_date,
                    'remarks'       => $request->remarks,
                ]);

                foreach ($request->items as $line) {
                    /** @var StockTransferItem $sti */
                    $sti = StockTransferItem::with(['sourceItem', 'destinationItem'])
                        ->where('id', $line['sti_id'])
                        ->where('stock_transfer_id', $transfer->id)
                        ->firstOrFail();

                    $oldQty  = $sti->quantity;
                    $newQty  = (float) $line['quantity'];
                    $newCost = round((float) $line['unit_cost'], 2);
                    $delta   = $newQty - $oldQty;

                    // Lock the exact stock rows before the read-modify-write so
                    // two concurrent edits can never lose a quantity update.
                    $sourceItem = Item::whereKey($sti->item_id)->lockForUpdate()->first();
                    $destItem   = Item::whereKey($sti->destination_item_id)->lockForUpdate()->first();

                    if (! $sourceItem || ! $destItem) {
                        continue;
                    }

                // ── Adjust source item stock ───────────────────────────────
                // More transferred out → source loses more stock
                $newSourceQty = max(0, $sourceItem->quantity - $delta);
                $sourceItem->update(['quantity' => $newSourceQty]);

                // ── Adjust destination item stock ──────────────────────────
                $newDestQty = max(0, $destItem->quantity + $delta);
                $destItem->update(['quantity' => $newDestQty, 'unit_cost' => $newCost]);

                // ── Update the transfer item row ───────────────────────────
                $sti->update(['quantity' => $newQty, 'unit_cost' => $newCost]);

                // ── Update transfer_out stock card entry at source ─────────
                $outEntry = StockCardEntry::where('reference_type', 'transfer_out')
                    ->where('reference_id', $transfer->id)
                    ->where('item_id', $sourceItem->id)
                    ->first();

                if ($outEntry) {
                    $outEntry->update([
                        'entry_date'         => $request->transfer_date,
                        'issue_qty'          => $newQty,
                        'balance_qty'        => $newSourceQty,
                        'balance_unit_cost'  => $sourceItem->unit_cost,
                        'balance_total_cost' => $newSourceQty * $sourceItem->unit_cost,
                    ]);
                }

                // ── Update transfer_in stock card entry at destination ─────
                $inEntry = StockCardEntry::where('reference_type', 'transfer_in')
                    ->where('reference_id', $transfer->id)
                    ->where('item_id', $destItem->id)
                    ->first();

if ($inEntry) {
                        $inEntry->update([
                            'entry_date'          => $request->transfer_date,
                            'receipt_qty'         => $newQty,
                            'receipt_unit_cost'   => $newCost,
                            'receipt_total_cost'  => $newQty * $newCost,
                            'balance_qty'         => $newDestQty,
                            'balance_unit_cost'   => $newCost,
                            'balance_total_cost'  => $newDestQty * $newCost,
                        ]);
                    }
                }
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Stock transfer update failed', [
                'transfer_id' => $transfer->id,
                'error'       => $e->getMessage(),
                'trace'       => $e->getTraceAsString(),
            ]);

            return back()->withInput()->with('error', 'The transaction could not be completed. No changes were made. Please try again.');
        }

        return redirect()
            ->route('transfers.show', $transfer)
            ->with('success', 'Transfer updated and stock adjusted.');
    }

    /**
     * Admin: delete a transfer and reverse its inventory movement exactly once.
     *
     * Every dispatched line moved stock out of the source item and into the
     * destination item. Deleting reverses that:
     *   source item.quantity  += dispatched qty  (stock returns to the source)
     *   dest   item.quantity  -= dispatched qty  (stock leaves the destination,
     *                                              clamped so it never goes negative)
     * and removes the matching transfer_out / transfer_in stock-card entries so
     * the card never shows a movement for a deleted transfer.
     *
     * Before reversing, the destination stock is checked for LATER movements
     * (requisition issues, onward transfers…). If the transferred stock has
     * already been used again, the deletion is refused so the ledger can never
     * be left inconsistent — the admin is told exactly what to resolve first.
     *
     * The reversal itself is written to the transfer audit log BEFORE the
     * transfer row is deleted, so the audit trail outlives the record.
     */
    public function destroy(StockTransfer $transfer)
    {
        abort_unless(Auth::user()->canWrite(), 403);

        $transfer->load(['fromWarehouse', 'toWarehouse', 'items.sourceItem', 'items.destinationItem']);

        // ── Dependency check (before any write / transaction) ────────────────
        // The dispatched stock arrived at the destination warehouse. If it was
        // used again after the transfer_in entry (issued against a requisition,
        // or sent on to another warehouse), deleting this transfer would leave
        // those later movements dangling — refuse and explain what to resolve.
        $blockers = $this->destroyBlockers($transfer);
        if (! empty($blockers)) {
            return back()->with('error', 'This transfer cannot be deleted yet because the transferred stock has since been used by: '.implode('; ', $blockers).'. Resolve those transactions first, then delete this transfer.');
        }

        try {
            DB::transaction(function () use ($transfer) {
                $reversal = [];
                $affectedItemIds = [];

                foreach ($transfer->items as $sti) {
                    // Remove this line's stock-card movements (harmless when none).
                    StockCardEntry::where('reference_type', 'transfer_out')
                        ->where('reference_id', $transfer->id)
                        ->where('item_id', $sti->item_id)
                        ->delete();

                    StockCardEntry::where('reference_type', 'transfer_in')
                        ->where('reference_id', $transfer->id)
                        ->where('item_id', $sti->destination_item_id)
                        ->delete();

                    $dispatched = (float) $sti->quantity;
                    if ($dispatched <= 0) {
                        continue;
                    }

                    $reversal[] = [
                        'description'          => $sti->sourceItem?->description ?? "Item #{$sti->item_id}",
                        'quantity'             => $dispatched,
                        'unit_cost'            => (float) $sti->unit_cost,
                        'source_item_id'       => $sti->item_id,
                        'destination_item_id'  => $sti->destination_item_id,
                    ];

                    $affectedItemIds[$sti->item_id] = true;
                    $affectedItemIds[$sti->destination_item_id] = true;

                    // Lock the exact stock rows before reversing the movement.
                    $sourceItem = Item::whereKey($sti->item_id)->lockForUpdate()->first();
                    $destItem   = Item::whereKey($sti->destination_item_id)->lockForUpdate()->first();

                    // Dispatch deducted stock at the source → add it back on delete
                    if ($sourceItem) {
                        $sourceItem->increment('quantity', $dispatched);
                    }

                    // Dispatch added stock at the destination → remove it on delete
                    if ($destItem) {
                        $newQty = max(0, $destItem->quantity - $dispatched);
                        $destItem->update(['quantity' => $newQty]);
                    }
                }

            // Audit trail BEFORE the row disappears — the entry survives the delete.
            StockTransferAuditLog::create([
                'stock_transfer_id' => $transfer->id,
                'transfer_number'   => $transfer->transfer_number,
                'user_id'           => Auth::user()->id,
                'action'            => 'reversed_deleted',
                'changed_fields'    => [
                    'transfer_number'        => $transfer->transfer_number,
                    'source_warehouse'       => $transfer->fromWarehouse?->name,
                    'destination_warehouse'  => $transfer->toWarehouse?->name,
                    'transfer_date'          => $transfer->transfer_date?->toDateString(),
                    'source_subsidy'         => $transfer->sourceSubsidyReference(),
                    'reversed_lines'         => $reversal,
                ],
            ]);

            $transfer->items()->delete();
            $transfer->delete();

            // Rebuild running stock-card balances for the affected items
                foreach (array_keys($affectedItemIds) as $itemId) {
                    StockCardEntry::recalculateBalancesForItem($itemId);
                }
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Stock transfer deletion failed', [
                'transfer_id' => $transfer->id,
                'error'       => $e->getMessage(),
                'trace'       => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'The transaction could not be completed. No changes were made. Please try again.');
        }

        return redirect()->route('transfers.index')
            ->with('success', "Transfer {$transfer->transfer_number} deleted and stock reversed.");
    }

    /**
     * Collect every reason a transfer cannot be deleted yet: later stock-card
     * movements on the destination item after its transfer_in entry.
     *
     * @return string[] Human-readable blockers (deduplicated).
     */
    private function destroyBlockers(StockTransfer $transfer): array
    {
        $blockers = [];

        foreach ($transfer->items as $sti) {
            if ((float) $sti->quantity <= 0) {
                continue;
            }

            $destItemId = $sti->destination_item_id;
            $lastInId   = StockCardEntry::where('reference_type', 'transfer_in')
                ->where('reference_id', $transfer->id)
                ->where('item_id', $destItemId)
                ->max('id');

            if (! $lastInId) {
                continue;
            }

            $laterEntries = StockCardEntry::where('item_id', $destItemId)
                ->where('id', '>', $lastInId)
                ->orderBy('id')
                ->get();

            foreach ($laterEntries as $entry) {
                $moved = (float) $entry->issue_qty > 0 ? $entry->issue_qty : $entry->receipt_qty;
                $blockers[] = sprintf(
                    '%s — %s (%s, %s)',
                    $sti->destinationItem?->description ?? "Item #{$destItemId}",
                    $entry->reference ?: '(no reference)',
                    ucfirst(str_replace('_', ' ', (string) $entry->reference_type)),
                    number_format($moved, 4).' unit(s)'
                );
            }
        }

        return array_values(array_unique($blockers));
    }
}
