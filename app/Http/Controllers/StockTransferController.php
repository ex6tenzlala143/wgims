<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesWarehouse;
use App\Models\Item;
use App\Models\StockCardEntry;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StockTransferController extends Controller
{
    use ScopesWarehouse;

    /**
     * Paginated list of transfers, scoped by user role.
     */
    public function index(Request $request)
    {
        $user  = Auth::user();
        $query = StockTransfer::with(['fromWarehouse', 'toWarehouse', 'transferredBy'])
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

        $transfers  = $query->paginate(20)->withQueryString();
        $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get();

        return view('transfers.index', compact('transfers', 'warehouses'));
    }

    /**
     * Show the create transfer form.
     */
    public function create()
    {
        $user = Auth::user();

        // Center staff cannot create transfers
        if ($user->role === User::ROLE_STAFF) {
            abort(403);
        }

        $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get();

        if ($user->hasAdminAccess()) {
            $sourceWarehouse = null;
            $sourceItems = collect();
        } else {
            // For non-admin, source is their primary warehouse
            // (first assigned warehouse, falling back to legacy warehouse_id)
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

        return view('transfers.create', compact('warehouses', 'sourceWarehouse', 'sourceItems'));
    }

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
            'to_warehouse_id.different' => 'Source and destination warehouse must be different.',
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

        $createdTransferId = null;

        try {
            DB::transaction(function () use ($request, $fromWarehouse, $toWarehouse, $user, &$createdTransferId) {
                $transferNumber = StockTransfer::generateTransferNumber();

                $transfer = StockTransfer::create([
                    'transfer_number'   => $transferNumber,
                    'from_warehouse_id' => $fromWarehouse->id,
                    'to_warehouse_id'   => $toWarehouse->id,
                    'transfer_date'     => $request->transfer_date,
                    'transferred_by'    => $user->id,
                    'status'            => 'pending',   // starts as pending — dispatch fills it
                    'remarks'           => $request->remarks,
                ]);

                foreach ($request->items as $line) {
                    $unitCost = round((float) $line['unit_cost'], 2);

                    // Resolve/create the destination item slot now so it exists for dispatching later
                    $sourceItem = Item::find($line['item_id']);
                    $destItem   = Item::findOrCreateByUnitCost(
                        $toWarehouse->id,
                        $sourceItem->description,
                        $sourceItem->unit,
                        $sourceItem->category,
                        $unitCost,
                        $sourceItem->ris_number,
                        $sourceItem->expiration_date?->format('Y-m-d'),
                        $sourceItem->engas_unit_cost
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

                // Bulk-insert notifications for all admins (single query instead of N inserts)
                $adminIds = User::where('role', User::ROLE_ADMIN)->pluck('id');
                $now      = now();
                $notifRows = $adminIds->map(fn ($adminId) => [
                    'user_id'    => $adminId,
                    'title'      => "Stock Transfer {$transfer->transfer_number}",
                    'message'    => "Transfer from {$fromWarehouse->name} to {$toWarehouse->name} created — pending dispatch.",
                    'type'       => 'transfer',
                    'link'       => route('transfers.show', $transfer->id),
                    'is_read'    => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->toArray();

                if (! empty($notifRows)) {
                    SystemNotification::insert($notifRows);
                }

                $createdTransferId = $transfer->id;
            });
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', 'Transfer could not be saved. Please try again.');
        }

        return redirect()
            ->route('transfers.show', $createdTransferId)
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

        DB::transaction(function () use ($request, $transfer) {
            $transfer->load(['fromWarehouse', 'toWarehouse', 'items.sourceItem', 'items.destinationItem']);

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

                $sourceItem = $sti->sourceItem;
                $destItem   = $sti->destinationItem;

                if (! $sourceItem || ! $destItem) {
                    continue;
                }

                // Move stock
                $newSourceQty = max(0, $sourceItem->quantity - $dispatchQty);
                $newDestQty   = $destItem->quantity + $dispatchQty;

                $sourceItem->update(['quantity' => $newSourceQty]);
                $destItem->update(['quantity' => $newDestQty]);

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
            ->get(['id', 'description', 'unit', 'category', 'unit_cost', 'quantity', 'stock_number']);

        return response()->json($items);
    }

    /**
     * Admin: delete a stock transfer and reverse all stock movements.
     *
     * For each dispatched line:
     *   - source item gets its stock back (quantity += dispatched)
     *   - destination item loses the stock (quantity -= dispatched, clamped to 0)
     *   - transfer_out and transfer_in stock card entries are deleted
     * Then the transfer items and the transfer record itself are deleted.
     */
    public function destroy(StockTransfer $transfer)
    {
        abort_unless(Auth::user()->isAdmin(), 403);

        DB::transaction(function () use ($transfer) {
            $transfer->load(['items.sourceItem', 'items.destinationItem']);

            foreach ($transfer->items as $sti) {
                $sourceItem = $sti->sourceItem;
                $destItem   = $sti->destinationItem;
                $dispatched = (float) $sti->quantity; // how much was actually moved

                // Reverse stock only for dispatched quantity
                if ($dispatched > 0) {
                    if ($sourceItem) {
                        $sourceItem->increment('quantity', $dispatched);
                    }
                    if ($destItem) {
                        $newDestQty = max(0, $destItem->quantity - $dispatched);
                        $destItem->update(['quantity' => $newDestQty]);
                    }
                }

                // Delete stock card entries tied to this transfer
                StockCardEntry::where('reference_type', 'transfer_out')
                    ->where('reference_id', $transfer->id)
                    ->where('item_id', $sti->item_id)
                    ->delete();

                StockCardEntry::where('reference_type', 'transfer_in')
                    ->where('reference_id', $transfer->id)
                    ->where('item_id', $sti->destination_item_id)
                    ->delete();
            }

            // Delete line items then the transfer header
            $transfer->items()->delete();
            $transfer->delete();
        });

        return redirect()->route('transfers.index')
            ->with('success', "Transfer {$transfer->transfer_number} deleted and stock reversed.");
    }

    /**
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

                $sourceItem = $sti->sourceItem;
                $destItem   = $sti->destinationItem;

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

        return redirect()
            ->route('transfers.show', $transfer)
            ->with('success', 'Transfer updated and stock adjusted.');
    }
}
