<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesWarehouse;
use App\Models\Item;
use App\Models\Reservation;
use App\Models\ReservationItem;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReservationController extends Controller
{
    use ScopesWarehouse;

    public function index(Request $request)
    {
        $user  = Auth::user();
        $query = Reservation::with(['items.item', 'items.warehouse', 'creator']);

        if (! $user->hasAdminAccess() && ! $user->isDeliveryUpdater()) {
            $assignedIds = $this->getUserWarehouseIds($user) ?? [];
            if (empty($assignedIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('items', fn ($q) => $q->whereIn('warehouse_id', $assignedIds));
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('warehouse_id')) {
            $query->whereHas('items', fn ($q) => $q->where('warehouse_id', $request->warehouse_id));
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('reservation_number', 'like', "%{$s}%")
                  ->orWhere('purpose', 'like', "%{$s}%")
                  ->orWhereHas('items.item', fn ($iq) => $iq->where('description', 'like', "%{$s}%"));
            });
        }

        $reservations = $query->orderByDesc('created_at')->paginate(20)->withQueryString();
        $warehouses   = Warehouse::where('is_active', true)->orderBy('name')->get();

        // For the create modal — non-admins only see their assigned warehouses
        $modalWarehouses = $warehouses;
        if (! $user->hasAdminAccess()) {
            $assignedIds     = $this->getUserWarehouseIds($user) ?? [];
            $modalWarehouses = $warehouses->whereIn('id', $assignedIds)->values();
        }

        return view('reservations.index', compact('reservations', 'warehouses', 'modalWarehouses'));
    }

    public function create(Request $request)
    {
        $user       = Auth::user();
        $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get();

        if (! $user->hasAdminAccess()) {
            $assignedIds = $this->getUserWarehouseIds($user) ?? [];
            $warehouses  = $warehouses->whereIn('id', $assignedIds)->values();
        }

        return view('reservations.create', compact('warehouses'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'purpose'    => 'nullable|string|max:500',
            'notes'      => 'nullable|string',
            'expires_at' => 'nullable|date|after:today',
            'items'      => 'required|array|min:1',
            'items.*.warehouse_id'      => 'required|exists:warehouses,id',
            'items.*.item_id'           => 'required|exists:items,id',
            'items.*.reserved_quantity' => 'required|integer|min:1',
        ]);

        $user = Auth::user();

        // Non-admins can only reserve from their assigned warehouses
        if (! $user->hasAdminAccess()) {
            $assignedIds = $this->getUserWarehouseIds($user) ?? [];
            foreach ($request->items as $idx => $line) {
                if (! in_array((int) $line['warehouse_id'], $assignedIds, true)) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.warehouse_id" => 'You can only reserve items from warehouses assigned to you.',
                    ]);
                }
            }
        }

        try {
            $reservation = DB::transaction(function () use ($request, $user) {
                $resNumber = Reservation::generateReservationNumber();

                $reservation = Reservation::create([
                    'reservation_number' => $resNumber,
                    'status'             => Reservation::STATUS_RESERVED,
                    'purpose'            => $request->purpose,
                    'notes'              => $request->notes,
                    'expires_at'         => $request->expires_at,
                    'created_by'         => $user->id,
                    'approved_by'        => $user->id,
                ]);

                foreach ($request->items as $idx => $line) {
                    $warehouseId = (int) $line['warehouse_id'];
                    $itemId      = (int) $line['item_id'];
                    $wantedQty   = (float) $line['reserved_quantity'];

                    // Lock the exact stock record
                    $item = Item::whereKey($itemId)
                        ->where('warehouse_id', $warehouseId)
                        ->lockForUpdate()
                        ->first();

                    if (! $item) {
                        throw ValidationException::withMessages([
                            "items.{$idx}.item_id" => 'Selected item does not belong to the selected warehouse.',
                        ]);
                    }

                    $available = $item->quantity - ReservationItem::reservedQuantityForItem($item->id);

                    if ($wantedQty > $available + 0.0001) {
                        throw ValidationException::withMessages([
                            "items.{$idx}.reserved_quantity" =>
                                "Cannot reserve {$wantedQty} of \"{$item->description}\" — only "
                                . number_format($available) . ' available (physical: '
                                . number_format($item->quantity) . ', already reserved: '
                                . number_format($item->quantity - $available) . ').',
                        ]);
                    }

                    ReservationItem::create([
                        'reservation_id'   => $reservation->id,
                        'item_id'          => $item->id,
                        'warehouse_id'     => $warehouseId,
                        'reserved_quantity'=> $wantedQty,
                        'deployed_quantity'=> 0,
                        'status'           => ReservationItem::STATUS_ACTIVE,
                        'unit_cost'        => $item->unit_cost,
                        'engas_unit_cost'  => $item->engas_unit_cost,
                        'expiration_date'  => $item->expiration_date?->format('Y-m-d'),
                    ]);
                }

                return $reservation;
            });

            return redirect()->route('reservations.index')
                ->with('success', "Reservation {$reservation->reservation_number} created and approved successfully.");

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return back()->withInput()
                ->with('error', 'Failed to create reservation: ' . $e->getMessage());
        }
    }

    public function show(Reservation $reservation)
    {
        $user = Auth::user();

        if (! $user->hasAdminAccess() && ! $user->isDeliveryUpdater()) {
            $assignedIds = $this->getUserWarehouseIds($user) ?? [];
            $hasAccess   = $reservation->items()
                ->whereIn('warehouse_id', $assignedIds)
                ->exists();
            if (! $hasAccess) {
                abort(403);
            }
        }

        $reservation->load([
            'items.item.sourceSubsidy',
            'items.warehouse',
            'items.dispatchItems.requisitionItem.requisition',
            'creator',
            'approver',
            'intendedRequisition',
        ]);

        return view('reservations.show', compact('reservation'));
    }

    /**
     * Admin: show the edit form for a reservation (header + lines).
     * Blocked for terminal statuses — use Cancel instead to release stock.
     */
    public function edit(Reservation $reservation)
    {
        abort_unless(Auth::user()->canWrite(), 403);

        if (in_array($reservation->status, [
            Reservation::STATUS_DEPLOYED,
            Reservation::STATUS_CANCELLED,
            Reservation::STATUS_EXPIRED,
        ])) {
            return redirect()->route('reservations.show', $reservation)
                ->with('error', 'This reservation cannot be edited because it is ' . strtolower($reservation->status_label) . '.');
        }

        $reservation->load(['items.item', 'items.warehouse']);
        $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get();

        return view('reservations.edit', compact('reservation', 'warehouses'));
    }

    /**
     * Admin: update reservation header (purpose/notes/expires) and lines.
     *
     * Lines may be edited (warehouse/item/quantity), added, or removed:
     *   - new quantity cannot go below already deployed_quantity
     *   - identity (warehouse/item) cannot change once deployed_quantity > 0
     *   - removed lines must have deployed_quantity == 0
     *   - increases are checked against unreserved stock
     *     (physical minus other active locks) under row locks
     *   - line status is recomputed (ACTIVE / PARTIALLY_DEPLOYED / DEPLOYED)
     */
    public function update(Request $request, Reservation $reservation)
    {
        abort_unless(Auth::user()->canWrite(), 403);

        if (in_array($reservation->status, [
            Reservation::STATUS_DEPLOYED,
            Reservation::STATUS_CANCELLED,
            Reservation::STATUS_EXPIRED,
        ])) {
            return back()->with('error', 'This reservation cannot be edited because it is ' . strtolower($reservation->status_label) . '.');
        }

        $request->validate([
            'purpose'    => 'nullable|string|max:500',
            'notes'      => 'nullable|string',
            'expires_at' => 'nullable|date|after:today',
            'items'      => 'required|array|min:1',
            'items.*.id' => 'nullable|integer|exists:reservation_items,id',
            'items.*.warehouse_id' => 'required|integer|exists:warehouses,id',
            'items.*.item_id' => 'required|integer|exists:items,id',
            'items.*.reserved_quantity' => 'required|integer|min:1',
        ]);

        try {
            DB::transaction(function () use ($request, $reservation) {
                // Lock all existing lines of this reservation
                $lines = ReservationItem::where('reservation_id', $reservation->id)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $submitted = array_values($request->items);
                $submittedIds = collect($submitted)
                    ->pluck('id')
                    ->filter(fn ($v) => ! empty($v))
                    ->map(fn ($v) => (int) $v)
                    ->all();

                // Every submitted id must belong to this reservation
                foreach ($submitted as $idx => $line) {
                    if (! empty($line['id']) && ! $lines->has((int) $line['id'])) {
                        throw ValidationException::withMessages([
                            "items.{$idx}.id" => 'One of the submitted items does not belong to this reservation.',
                        ]);
                    }
                    if (! empty($line['id'])) {
                        $ri = $lines[(int) $line['id']];
                        if ($ri->status === ReservationItem::STATUS_CANCELLED) {
                            throw ValidationException::withMessages([
                                "items.{$idx}.id" => 'This item was cancelled and can no longer be edited.',
                            ]);
                        }
                    }
                }

                // Removed lines: existed before but omitted now — only when
                // nothing was ever deployed from them.
                $removedIds = $lines->keys()->diff($submittedIds)->all();
                foreach ($removedIds as $removedId) {
                    $ri = $lines[$removedId];
                    if ((float) $ri->deployed_quantity > 0.0001) {
                        throw ValidationException::withMessages([
                            'items' => "Cannot remove \"{$ri->item?->description}\" — {$ri->deployed_quantity} unit(s) were already deployed through a RIS. Reduce its quantity to the deployed amount instead.",
                        ]);
                    }
                }

                // Validate identity + floors for submitted lines
                $targets = []; // idx => ['ri' (nullable), 'item', 'qty', 'deployed']
                foreach ($submitted as $idx => $line) {
                    $ri = ! empty($line['id']) ? $lines[(int) $line['id']] : null;
                    $deployed = $ri ? (float) $ri->deployed_quantity : 0.0;
                    $newQty = (float) $line['reserved_quantity'];
                    $warehouseId = (int) $line['warehouse_id'];
                    $itemId = (int) $line['item_id'];

                    if ($newQty < $deployed - 0.0001) {
                        $label = $ri?->item?->description ?? 'this item';
                        throw ValidationException::withMessages([
                            "items.{$idx}.reserved_quantity" =>
                                "Cannot set \"{$label}\" below its deployed quantity (" . number_format($deployed) . ').',
                        ]);
                    }

                    $item = Item::whereKey($itemId)->lockForUpdate()->first();
                    if (! $item || (int) $item->warehouse_id !== $warehouseId) {
                        throw ValidationException::withMessages([
                            "items.{$idx}.item_id" => 'Selected item does not belong to the selected warehouse.',
                        ]);
                    }

                    // Deployed lines are pinned to their original stock record
                    if ($ri && $deployed > 0.0001) {
                        if ((int) $ri->item_id !== $itemId || (int) $ri->warehouse_id !== $warehouseId) {
                            throw ValidationException::withMessages([
                                "items.{$idx}.item_id" => 'Warehouse / item cannot be changed once part of the reservation was deployed (deployed ' . number_format($deployed) . '). Adjust the quantity instead.',
                            ]);
                        }
                    }

                    $targets[$idx] = ['ri' => $ri, 'item' => $item, 'qty' => $newQty, 'deployed' => $deployed];
                }

                // Combined availability per stock record across the FINAL state.
                // oldActive = this reservation's current ACTIVE locks per item;
                // newActive = final ACTIVE locks per item (fully-deployed lines
                // release their lock and count 0).
                $oldActiveByItem = [];
                foreach ($lines as $ri) {
                    if (in_array($ri->status, ReservationItem::ACTIVE_STATUSES, true)) {
                        $oldActiveByItem[$ri->item_id] = ($oldActiveByItem[$ri->item_id] ?? 0) + (float) $ri->reserved_quantity;
                    }
                }

                $newActiveByItem = [];
                foreach ($targets as $t) {
                    $willBeActive = $t['qty'] > $t['deployed'] + 0.0001 || $t['deployed'] <= 0.0001;
                    if ($willBeActive) {
                        $itemId = $t['item']->id;
                        $newActiveByItem[$itemId] = ($newActiveByItem[$itemId] ?? 0) + $t['qty'];
                    }
                }

                $involvedItemIds = collect(array_merge(array_keys($oldActiveByItem), array_keys($newActiveByItem)))
                    ->map(fn ($v) => (int) $v)->unique()->values()->all();
                // Lock every involved stock row in a stable order (some are
                // already locked above — re-locking is a no-op within the txn).
                $itemsById = [];
                foreach (collect($involvedItemIds)->sort()->all() as $itemId) {
                    $locked = Item::whereKey($itemId)->lockForUpdate()->first();
                    if ($locked) {
                        $itemsById[$itemId] = $locked;
                    }
                }
                // Also ensure target items resolved earlier are in the map
                foreach ($targets as $t) {
                    $itemsById[$t['item']->id] = $itemsById[$t['item']->id] ?? $t['item'];
                }

                foreach ($newActiveByItem as $itemId => $newActive) {
                    $oldActive = (float) ($oldActiveByItem[$itemId] ?? 0);
                    if ($newActive > $oldActive + 0.0001) {
                        $item = $itemsById[$itemId] ?? Item::whereKey($itemId)->lockForUpdate()->first();
                        if (! $item) {
                            throw ValidationException::withMessages([
                                'items' => 'One of the reserved stock records no longer exists.',
                            ]);
                        }
                        $totalLocked = (float) ReservationItem::reservedQuantityForItem($itemId);
                        $lockedByOthers = $totalLocked - $oldActive;
                        $maxAllowed = (float) $item->quantity - $lockedByOthers;

                        if ($newActive > $maxAllowed + 0.0001) {
                            throw ValidationException::withMessages([
                                'items' =>
                                    "Insufficient stock for \"{$item->description}\": combined request " . number_format($newActive)
                                    . ' exceeds available ' . number_format(max(0, $maxAllowed))
                                    . ' (physical ' . number_format($item->quantity)
                                    . ', locked by others ' . number_format(max(0, $lockedByOthers)) . '). No changes were saved.',
                            ]);
                        }
                    }
                }

                // Apply header + line updates
                $reservation->update([
                    'purpose'    => $request->purpose,
                    'notes'      => $request->notes,
                    'expires_at' => $request->expires_at ?: null,
                ]);

                // Delete removed lines (all verified deployed == 0)
                if (! empty($removedIds)) {
                    ReservationItem::where('reservation_id', $reservation->id)
                        ->whereIn('id', $removedIds)
                        ->delete();
                }

                foreach ($targets as $t) {
                    /** @var ReservationItem|null $ri */
                    $ri = $t['ri'];
                    $item = $t['item'];
                    $newQty = $t['qty'];
                    $deployed = $t['deployed'];

                    $newLineStatus = $deployed <= 0.0001
                        ? ReservationItem::STATUS_ACTIVE
                        : ($newQty <= $deployed + 0.0001
                            ? ReservationItem::STATUS_DEPLOYED
                            : ReservationItem::STATUS_PARTIALLY_DEPLOYED);

                    $snapshot = [
                        'reserved_quantity' => $newQty,
                        'status'            => $newLineStatus,
                        'item_id'           => $item->id,
                        'warehouse_id'      => $item->warehouse_id,
                        'unit_cost'         => $item->unit_cost,
                        'engas_unit_cost'   => $item->engas_unit_cost,
                        'expiration_date'   => $item->expiration_date?->format('Y-m-d'),
                    ];

                    if ($ri) {
                        $ri->update($snapshot);
                    } else {
                        ReservationItem::create(array_merge($snapshot, [
                            'reservation_id'    => $reservation->id,
                            'deployed_quantity' => 0,
                        ]));
                    }
                }

                $reservation->refresh()->updateOverallStatus();
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()
                ->with('error', 'The reservation could not be updated. No changes were made. Please try again.');
        }

        return redirect()->route('reservations.show', $reservation)
            ->with('success', 'Reservation updated successfully.');
    }

    public function approve(Reservation $reservation)
    {
        abort_unless(Auth::user()->canWrite(), 403);

        // Reservations are auto-approved on creation — this endpoint is kept
        // for legacy PENDING records. Already-approved ones are a no-op.
        if ($reservation->status === Reservation::STATUS_RESERVED) {
            return back()->with('success', 'Reservation is already approved.');
        }

        if ($reservation->status !== Reservation::STATUS_PENDING) {
            return back()->with('error', 'Only PENDING reservations can be approved.');
        }

        $reservation->approve(Auth::user()->id);

        return redirect()->route('reservations.show', $reservation)
            ->with('success', 'Reservation approved.');
    }

    public function markReady(Reservation $reservation)
    {
        abort_unless(Auth::user()->canWrite(), 403);

        if ($reservation->status !== Reservation::STATUS_RESERVED) {
            return back()->with('error', 'Only RESERVED reservations can be marked as ready.');
        }

        $reservation->markReady();

        return redirect()->route('reservations.show', $reservation)
            ->with('success', 'Reservation marked as ready for requisition.');
    }

    public function cancel(Reservation $reservation)
    {
        abort_unless(Auth::user()->canWrite(), 403);

        if (in_array($reservation->status, [
            Reservation::STATUS_DEPLOYED,
            Reservation::STATUS_CANCELLED,
            Reservation::STATUS_EXPIRED,
        ])) {
            return back()->with('error', 'This reservation cannot be cancelled.');
        }

        $reservation->cancel();

        return redirect()->route('reservations.show', $reservation)
            ->with('success', 'Reservation cancelled and reserved quantities released.');
    }

    /**
     * Delete a reservation entirely. Only admin. Only allowed when no items
     * have been deployed — if any deployment history exists the reservation
     * must be cancelled instead, not deleted.
     */
    public function destroy(Reservation $reservation)
    {
        abort_unless(Auth::user()->isAdmin(), 403);

        // Refuse if any line has been at least partially deployed
        if ($reservation->items()->where('deployed_quantity', '>', 0)->exists()) {
            return back()->with('error',
                'This reservation cannot be deleted because some items have already been deployed through a RIS. Cancel it instead to release any remaining reserved quantities.'
            );
        }

        $reservationNumber = $reservation->reservation_number ?? '#' . $reservation->id;

        DB::transaction(function () use ($reservation) {
            // Cancel all items first so reserved quantities are released
            // (status moves out of ACTIVE_STATUSES, removing the soft lock)
            $reservation->items()->delete();
            $reservation->delete();
        });

        return redirect()->route('reservations.index')
            ->with('success', "Reservation {$reservationNumber} deleted.");
    }

    /**
     * Remove a single reservation item (only if it has never been deployed
     * and the reservation is still in a mutable status).
     */
    public function destroyItem(Reservation $reservation, ReservationItem $reservationItem)
    {
        abort_unless(Auth::user()->canWrite(), 403);

        if ($reservationItem->reservation_id !== $reservation->id) {
            abort(404);
        }

        if (in_array($reservation->status, [
            Reservation::STATUS_DEPLOYED,
            Reservation::STATUS_CANCELLED,
            Reservation::STATUS_EXPIRED,
        ])) {
            return back()->with('error', 'Cannot remove items from a completed or cancelled reservation.');
        }

        if ($reservationItem->deployed_quantity > 0) {
            return back()->with('error', 'Cannot remove an item that has already been partially or fully deployed.');
        }

        if ($reservation->items()->count() <= 1) {
            return back()->with('error', 'A reservation must have at least one item. Cancel the reservation instead.');
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($reservation, $reservationItem) {
            // Re-lock the line inside the transaction so a concurrent deploy
            // between the checks above and the delete cannot slip through.
            $locked = \App\Models\ReservationItem::whereKey($reservationItem->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ((float) $locked->deployed_quantity > 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'item' => 'Cannot remove an item that has already been partially or fully deployed.',
                ]);
            }
            $locked->delete();

            // Recompute the header: removing a line can resolve the reservation
            // back to fully-deployed (or change nothing) — never leave it stale.
            $reservation->refresh()->updateOverallStatus();
        });

        return back()->with('success', 'Item removed from reservation.');
    }

    // ── API endpoints ─────────────────────────────────────────────────────────

    /**
     * GET /reservations/items-by-warehouse?warehouse_id=X
     * Returns active items in a warehouse with available quantities.
     * Used by the reservation create form AJAX.
     */
    public function getItemsByWarehouse(Request $request)
    {
        $request->validate(['warehouse_id' => 'required|integer|exists:warehouses,id']);

        $user        = Auth::user();
        $warehouseId = (int) $request->warehouse_id;

        if (! $user->hasAdminAccess()) {
            $assignedIds = $this->getUserWarehouseIds($user) ?? [];
            if (! in_array($warehouseId, $assignedIds, true)) {
                abort(403, 'You do not have access to this warehouse.');
            }
        }

        $items = Item::where('warehouse_id', $warehouseId)
            ->where('is_active', true)
            ->where('quantity', '>', 0)
            ->whereNotNull('stock_number')
            ->orderBy('description')
            ->orderBy('unit_cost')
            ->get();

        // Pre-load all reserved quantities in ONE query instead of N queries
        $reservedMap = $items->isNotEmpty()
            ? ReservationItem::whereIn('item_id', $items->pluck('id'))
                ->whereIn('status', ReservationItem::ACTIVE_STATUSES)
                ->groupBy('item_id')
                ->selectRaw('item_id, SUM(GREATEST(reserved_quantity - deployed_quantity, 0)) as locked')
                ->pluck('locked', 'item_id')
                ->map(fn ($v) => (float) $v)
            : collect();

        $items = $items->map(function (Item $item) use ($reservedMap) {
                $reserved  = (float) ($reservedMap[$item->id] ?? 0);
                $available = max(0, $item->quantity - $reserved);
                return [
                    'id'                 => $item->id,
                    'description'        => $item->description,
                    'stock_number'       => $item->stock_number,
                    'unit'               => $item->unit,
                    'category'           => $item->getCategoryLabel(),
                    'physical_qty'       => (float) $item->quantity,
                    'reserved_qty'       => $reserved,
                    'available_qty'      => $available,
                    'unit_cost'          => (float) $item->unit_cost,
                    'engas_unit_cost'    => $item->engas_unit_cost !== null ? (float) $item->engas_unit_cost : null,
                    'expiration_date'    => $item->expiration_date?->format('Y-m-d'),
                    'expiry_formatted'   => $item->expiration_date?->format('M d, Y'),
                    'source_subsidy_code'=> $item->source_subsidy_code,
                ];
            })
            ->filter(fn ($i) => $i['available_qty'] > 0)
            ->values();

        return response()->json($items);
    }

    /**
     * GET /api/reservations/for-dispatch?description=X
     * Returns active reservation_items matching the given item description.
     * Used by the RIS DISPATCH form's "Reserved Items" source picker.
     * Each result includes all stock identity fields so the dispatcher
     * can verify the exact record being consumed.
     */
    public function getItemsForDispatch(Request $request)
    {
        $request->validate([
            'description' => 'required|string|max:500',
        ]);

        $user        = Auth::user();
        $description = mb_strtolower(trim($request->description));

        $query = ReservationItem::with(['reservation', 'item', 'warehouse'])
            ->whereIn('status', ReservationItem::ACTIVE_STATUSES)
            ->where('reserved_quantity', '>', 0)
            ->whereHas('item', fn ($q) =>
                $q->whereRaw('LOWER(TRIM(description)) = ?', [$description])
            )
            ->whereRaw('reserved_quantity > deployed_quantity');

        // Non-admins can only see reservation items for their warehouses
        if (! $user->hasAdminAccess()) {
            $assignedIds = $this->getUserWarehouseIds($user) ?? [];
            if (empty($assignedIds)) {
                return response()->json([]);
            }
            $query->whereIn('warehouse_id', $assignedIds);
        }

        $items = $query->get()
            ->filter(fn ($ri) => $ri->reservation !== null
                && in_array($ri->reservation->status, \App\Models\Reservation::ACTIVE_STATUSES)
                && $ri->item !== null
            )
            ->values()
            ->map(function (ReservationItem $ri) {
                $item = $ri->item;
                return [
                    'reservation_item_id'  => $ri->id,
                    'reservation_id'       => $ri->reservation_id,
                    'reservation_number'   => $ri->reservation->reservation_number ?? ('RES-' . str_pad($ri->reservation_id, 6, '0', STR_PAD_LEFT)),
                    'reservation_purpose'  => $ri->reservation->purpose,
                    'reservation_status'   => $ri->reservation->status_label,
                    'item_id'              => $ri->item_id,
                    'stock_number'         => $item->stock_number,
                    'description'          => $item->description,
                    'unit'                 => $item->unit,
                    'warehouse_id'         => $ri->warehouse_id,
                    'warehouse_name'       => $ri->warehouse->name ?? '—',
                    'reserved_quantity'    => (float) $ri->reserved_quantity,
                    'deployed_quantity'    => (float) $ri->deployed_quantity,
                    'remaining_quantity'   => (float) $ri->remaining_quantity,
                    'unit_cost'            => (float) ($ri->unit_cost ?? $item->unit_cost ?? 0),
                    'engas_unit_cost'      => $ri->engas_unit_cost !== null ? (float) $ri->engas_unit_cost : null,
                    'expiration_date'      => $ri->expiration_date?->format('Y-m-d'),
                    'expiry_formatted'     => $ri->expiration_date?->format('M d, Y'),
                    'source_subsidy_code'  => $item->source_subsidy_code,
                    // Display label shown in the dropdown
                    'display_label'        => sprintf(
                        '%s — %s · Remaining: %s · ₱%s%s%s',
                        $ri->reservation->reservation_number ?? 'RES',
                        $ri->warehouse->name ?? '—',
                        number_format($ri->remaining_quantity),
                        number_format($ri->unit_cost ?? $item->unit_cost ?? 0, 2),
                        $ri->engas_unit_cost !== null ? ' · ENGAS ₱' . number_format($ri->engas_unit_cost, 2) : '',
                        $ri->expiration_date ? ' · Exp: ' . $ri->expiration_date->format('M d, Y') : ''
                    ),
                ];
            });

        return response()->json($items);
    }

    /**
     * GET /api/reservations/active
     * Returns active reservations accessible to the current user.
     * Used by the RIS create form "Use Reserved Items" feature.
     */
    public function getActiveReservations(Request $request)
    {
        $user  = Auth::user();
        $query = Reservation::with(['items.item', 'items.warehouse'])
            ->whereIn('status', [
                Reservation::STATUS_RESERVED,
                Reservation::STATUS_READY,
                Reservation::STATUS_PARTIALLY_DEPLOYED,
            ]);

        if (! $user->hasAdminAccess()) {
            $assignedIds = $this->getUserWarehouseIds($user) ?? [];
            if (empty($assignedIds)) {
                return response()->json([]);
            }
            $query->whereHas('items', fn ($q) => $q->whereIn('warehouse_id', $assignedIds));
        }

        $reservations = $query->orderByDesc('created_at')->get();

        return response()->json($reservations->map(function (Reservation $res) {
            return [
                'id'                 => $res->id,
                'reservation_number' => $res->reservation_number,
                'purpose'            => $res->purpose,
                'status'             => $res->status,
                'status_label'       => $res->status_label,
                'item_count'         => $res->items->count(),
                'expires_at'         => $res->expires_at?->format('M d, Y'),
            ];
        }));
    }

    /**
     * GET /api/reservations/{reservation}/items
     * Returns the active reservation_items for a reservation.
     * Used by the RIS create form to populate the "deploy quantities" table.
     */
    public function getReservationItems(Reservation $reservation)
    {
        $user = Auth::user();

        if (! $user->hasAdminAccess()) {
            $assignedIds = $this->getUserWarehouseIds($user) ?? [];
            $hasAccess   = $reservation->items()
                ->whereIn('warehouse_id', $assignedIds)
                ->exists();
            abort_unless($hasAccess, 403);
        }

        $reservation->load(['items.item', 'items.warehouse']);

        $items = $reservation->items()
            ->whereIn('status', ReservationItem::ACTIVE_STATUSES)
            ->with(['item', 'warehouse'])
            ->get()
            ->map(function (ReservationItem $ri) {
                $physicalAvailable = max(0,
                    ($ri->item->quantity ?? 0)
                    - ReservationItem::reservedQuantityForItem($ri->item_id)
                );
                return [
                    'id'                  => $ri->id,
                    'item_id'             => $ri->item_id,
                    'warehouse_id'        => $ri->warehouse_id,
                    'description'         => $ri->item->description ?? '—',
                    'stock_number'        => $ri->item->stock_number ?? '—',
                    'unit'                => $ri->item->unit ?? '—',
                    'warehouse_name'      => $ri->warehouse->name ?? '—',
                    'reserved_quantity'   => (float) $ri->reserved_quantity,
                    'deployed_quantity'   => (float) $ri->deployed_quantity,
                    'remaining_quantity'  => (float) $ri->remaining_quantity,
                    'physical_available'  => $physicalAvailable,
                    'max_deployable'      => min($ri->remaining_quantity, $physicalAvailable),
                    'unit_cost'           => (float) $ri->unit_cost,
                    'engas_unit_cost'     => $ri->engas_unit_cost !== null ? (float) $ri->engas_unit_cost : null,
                    'expiration_date'     => $ri->expiration_date?->format('Y-m-d'),
                    'expiry_formatted'    => $ri->expiration_date?->format('M d, Y'),
                    'status'              => $ri->status,
                    'status_label'        => $ri->status_label,
                ];
            });

        return response()->json($items);
    }
}
