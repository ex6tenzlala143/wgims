<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesWarehouse;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Item;
use App\Models\DeliverySubsidy;
use App\Models\DeliverySubsidyItem;
use App\Models\RequisitionItem;
use App\Models\StockCardEntry;
use App\Models\StockTransferItem;
use App\Models\Supplier;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DeliverySubsidyCascadeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DeliverySubsidyController extends Controller
{
    use ScopesWarehouse;

    public function index(Request $request)
    {
        $user = Auth::user();
        $query = DeliverySubsidy::with(['supplier', 'warehouse', 'creator']);

        $this->applyWarehouseScope($query, $user, $request->warehouse_id ? (int) $request->warehouse_id : null);

        if ($request->status) {
            $query->where('status', $request->status);
        }

        $query->when($request->description, fn ($q, $desc) => $q->whereHas('items.item', fn ($iq) => $iq->where('description', $desc)))
            ->when($request->account_code, fn ($q, $code) => $q->whereHas('items.item', fn ($iq) => $iq->where('account_code', $code)));

        $pos = $query->withSum('deliveries', 'quantity_delivered')
                     ->orderByDesc('date')->paginate(20)->withQueryString();

        $warehouses = $user->hasAdminAccess() ? Warehouse::where('is_active', true)->get() : collect();

        $accountCodes = collect(Item::getCategories())
            ->mapWithKeys(fn ($cat, $key) => [$cat['account_code'] => $cat['account_code'] . ' — ' . $cat['label']])
            ->unique()
            ->sortKeys();

        $descriptions = Item::where('is_active', true)
            ->select('description')->distinct()
            ->orderBy('description')
            ->pluck('description');

        return view('delivery_subsidies.index', compact('pos', 'warehouses', 'accountCodes', 'descriptions'));
    }

    public function create()
    {
        $user = Auth::user();

        // Center staff cannot create delivery/subsidies
        if ($user->role === \App\Models\User::ROLE_STAFF) {
            abort(403);
        }

        $suppliers = Supplier::where('is_active', true)->orderBy('name')->get();

        if ($user->hasAdminAccess()) {
            $warehouses = Warehouse::where('is_active', true)->get();
            $items = Item::where('is_active', true)->with('warehouse')->orderBy('description')->get();
        } else {
            $warehouseIds = $this->getUserWarehouseIds($user);
            $warehouses = Warehouse::whereIn('id', $warehouseIds)->where('is_active', true)->get();
            $items = Item::whereIn('warehouse_id', $warehouseIds)->where('is_active', true)->orderBy('description')->get();
        }

        return view('delivery_subsidies.create', compact('suppliers', 'warehouses', 'items'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'ris_number'         => 'required|string|max:255',
            'supplier_id'        => 'required|exists:suppliers,id',
            'warehouse_id'       => 'required|exists:warehouses,id',
            'date'               => 'required|date',
            'items'              => 'required|array|min:1',
            'items.*.description'    => 'required|string|max:255',
            'items.*.unit'           => 'required|string',
            'items.*.category' => 'required|string|in:'.implode(',', array_keys(Item::getCategories())),
            'items.*.quantity'       => 'required|numeric|min:0.01',
            'items.*.unit_cost'      => 'required|numeric|min:0',
            'items.*.expiration_date'=> 'nullable|date',
        ]);

        $user = Auth::user();
        if ($user->isCenterUser() && !$this->userCanAccessWarehouse($user, (int) $request->warehouse_id)) {
            abort(403);
        }

        // Validate items belong to the correct warehouse
        $warehouseId = (int) $request->warehouse_id;
        foreach ($request->items as $line) {
            $item = Item::find($line['item_id'] ?? null);
            if ($item && $item->warehouse_id !== $warehouseId && ! $user->isAdmin()) {
                abort(403, 'Item does not belong to your warehouse.');
            }
        }

        DB::transaction(function () use ($request, $user) {
            $total = collect($request->items)->sum(fn ($l) => $l['quantity'] * $l['unit_cost']);

            // Derive quantity_requested from the sum of all line item quantities
            $quantityRequested = collect($request->items)->sum(fn ($l) => (float) $l['quantity']);

            // Use ris_number as dr_number; append suffix only if duplicate
            $drNumber = $request->ris_number;
            $suffix = 0;
            while (DeliverySubsidy::where('dr_number', $drNumber)->exists() && $suffix < 100) {
                $suffix++;
                $drNumber = $request->ris_number . '-' . $suffix;
            }

            $po = DeliverySubsidy::create([
                'dr_number' => $drNumber,
                'supplier_id' => $request->supplier_id,
                'warehouse_id' => $request->warehouse_id,
                'created_by' => $user->id,
                'date' => $request->date,
                'ris_number' => $request->ris_number,
                'place_of_delivery' => $request->place_of_delivery,
                'total_amount' => $total,
                'quantity_requested' => $quantityRequested,
                'remarks' => $request->remarks,
            ]);

            foreach ($request->items as $line) {
                // Resolve item_id: use provided id if valid, otherwise find/create by description
                $itemId = null;
                if (! empty($line['item_id'])) {
                    $itemId = (int) $line['item_id'];
                } else {
                    // Find existing item in this warehouse matching description+unit+category
                    $existingItem = Item::where('warehouse_id', $po->warehouse_id)
                        ->where('description', $line['description'])
                        ->where('unit', $line['unit'])
                        ->where('category', $line['category'])
                        ->first();

                    if ($existingItem) {
                        $itemId = $existingItem->id;
                    } else {
                        // Create a placeholder item (no stock number yet — assigned on delivery)
                        $newItem = Item::create([
                            'description' => $line['description'],
                            'unit' => $line['unit'],
                            'category' => $line['category'],
                            'account_code' => Item::getAccountCodeForCategory($line['category']),
                            'warehouse_id' => $po->warehouse_id,
                            'unit_cost' => $line['unit_cost'],
                            'quantity' => 0,
                            'expiration_date' => $line['expiration_date'] ?? null,
                            'is_active' => true,
                        ]);
                        $itemId = $newItem->id;
                    }
                }

                DeliverySubsidyItem::create([
                    'delivery_subsidy_id' => $po->id,
                    'item_id' => $itemId,
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                    'amount' => $line['quantity'] * $line['unit_cost'],
                ]);
            }

            // Bulk-insert notifications for all admins (single query instead of N inserts)
            $adminIds = User::where('role', 'admin')->pluck('id');
            $now = now();
            $notifRows = $adminIds->map(fn ($id) => [
                'user_id'    => $id,
                'title'      => 'New Delivery/Subsidy',
                'message'    => "DR #{$po->dr_number} has been created.",
                'type'       => 'info',
                'link'       => route('delivery_subsidies.show', $po->id),
                'is_read'    => false,
                'created_at' => $now,
                'updated_at' => $now,
            ])->toArray();

            if (! empty($notifRows)) {
                \App\Models\SystemNotification::insert($notifRows);
            }
        });

        return redirect()->route('delivery_subsidies.index')->with('success', 'Delivery / Subsidy created successfully.');
    }

    public function show(DeliverySubsidy $deliverySubsidy)
    {
        $user = Auth::user();
        if (! $this->userCanAccessWarehouse($user, $deliverySubsidy->warehouse_id)) {
            abort(403);
        }
        $deliverySubsidy->load(['supplier', 'warehouse', 'creator', 'items.item', 'deliveries.items.item', 'deliveries.receiver']);

        return view('delivery_subsidies.show', compact('deliverySubsidy'));
    }

    public function auditLog(DeliverySubsidy $deliverySubsidy)
    {
        abort_unless(Auth::user()->isAdmin(), 403);
        $deliverySubsidy->load(['supplier', 'warehouse']);
        $logs = $deliverySubsidy->auditLogs()->with('user')->paginate(25);

        return view('delivery_subsidies.audit_log', compact('deliverySubsidy', 'logs'));
    }

    public function edit(DeliverySubsidy $deliverySubsidy)
    {
        abort_unless(Auth::user()->canWrite(), 403);
        $suppliers = Supplier::where('is_active', true)->get();
        $warehouses = Warehouse::where('is_active', true)->get();
        $items = Item::where('is_active', true)->with('warehouse')->orderBy('description')->get();
        $deliverySubsidy->load('items.item');

        return view('delivery_subsidies.edit', compact('deliverySubsidy', 'suppliers', 'warehouses', 'items'));
    }

    public function update(Request $request, DeliverySubsidy $deliverySubsidy)
    {
        abort_unless(Auth::user()->canWrite(), 403);

        $request->validate([
            'ris_number'         => 'required|string|max:255',
            'supplier_id'        => 'required|exists:suppliers,id',
            'warehouse_id'       => 'required|exists:warehouses,id',
            'date'               => 'required|date',
            'status'             => 'required|string|in:pending,partial,fully_delivered,cancelled',
            'quantity_requested' => 'required|numeric|min:0.01',
            'items'              => 'required|array|min:1',
            'items.*.item_id'    => 'required|exists:items,id',
            'items.*.quantity'   => 'required|numeric|min:0.01',
            'items.*.unit_cost'  => 'required|numeric|min:0',
        ]);

        // Enforce: 'fully_delivered' may only be set when quantity delivered >= quantity requested.
        if ($request->status === 'fully_delivered') {
            $requested  = (float) $deliverySubsidy->quantity_requested;
            $delivered  = (float) $deliverySubsidy->totalDelivered();
            $epsilon    = 0.0001;

            if ($requested <= 0 || $delivered < $requested - $epsilon) {
                return back()->withInput()->withErrors([
                    'status' => 'Status cannot be set to "Fully Delivered" — quantity delivered ('
                        . number_format($delivered, 4) . ') has not yet reached the quantity requested ('
                        . number_format($requested, 4) . '). Record the remaining shipment first.',
                ]);
            }
        }

        $cascadeSvc = new DeliverySubsidyCascadeService();

        DB::transaction(function () use ($request, $deliverySubsidy, $cascadeSvc) {
            $total = collect($request->items)->sum(fn ($l) => $l['quantity'] * $l['unit_cost']);

            // ── Snapshot old values for audit + cascade detection ─────────
            $oldRisNumber  = $deliverySubsidy->ris_number;
            $oldSupplierId = $deliverySubsidy->supplier_id;
            $newRisNumber  = $request->ris_number;
            $newSupplierId = (int) $request->supplier_id;

            $changedFields  = [];
            $cascadeSummary = [];

            $trackableFields = [
                'ris_number'         => ['old' => $deliverySubsidy->ris_number,         'new' => $request->ris_number],
                'supplier_id'        => ['old' => $deliverySubsidy->supplier_id,         'new' => $newSupplierId],
                'warehouse_id'       => ['old' => $deliverySubsidy->warehouse_id,        'new' => (int) $request->warehouse_id],
                'date'               => ['old' => $deliverySubsidy->date?->toDateString(), 'new' => $request->date],
                'status'             => ['old' => $deliverySubsidy->status,              'new' => $request->status],
                'quantity_requested' => ['old' => $deliverySubsidy->quantity_requested,  'new' => (float) $request->quantity_requested],
                'place_of_delivery'  => ['old' => $deliverySubsidy->place_of_delivery,  'new' => $request->place_of_delivery],
                'remarks'            => ['old' => $deliverySubsidy->remarks,             'new' => $request->remarks],
            ];

            foreach ($trackableFields as $field => $vals) {
                if ((string) $vals['old'] !== (string) $vals['new']) {
                    $changedFields[$field] = $vals;
                }
            }

            // ── Update header ─────────────────────────────────────────────
            $deliverySubsidy->update([
                'supplier_id'        => $request->supplier_id,
                'warehouse_id'       => $request->warehouse_id,
                'date'               => $request->date,
                'ris_number'         => $newRisNumber,
                'place_of_delivery'  => $request->place_of_delivery,
                'total_amount'       => $total,
                'quantity_requested' => $request->quantity_requested,
                'status'             => $request->status,
                'remarks'            => $request->remarks,
            ]);

            $hasDeliveries = $deliverySubsidy->deliveries()->count() > 0;

            if ($hasDeliveries) {
                // ── Smart upsert: update existing, create new, delete orphans ─
                $submittedDsiIds = [];

                foreach ($request->items as $line) {
                    if (! empty($line['dsi_id'])) {
                        $dsi = DeliverySubsidyItem::where('id', $line['dsi_id'])
                            ->where('delivery_subsidy_id', $deliverySubsidy->id)
                            ->first();

                        if ($dsi) {
                            $oldCost   = (float) $dsi->unit_cost;
                            $newCost   = (float) $line['unit_cost'];
                            $costDelta = abs($oldCost - $newCost);

                            $dsi->update([
                                'item_id'   => $line['item_id'],
                                'quantity'  => $line['quantity'],
                                'unit_cost' => $newCost,
                                'amount'    => $line['quantity'] * $newCost,
                            ]);
                            $submittedDsiIds[] = $dsi->id;

                            // ── Cascade unit-cost change through the full transfer chain ──
                            if ($costDelta > 0.001) {
                                $item = $dsi->item()->withoutGlobalScopes()->first();
                                if ($item) {
                                    $cascadeSvc->cascadeUnitCost($item, $newCost, $cascadeSummary);

                                    // Track per-line cost change in audit
                                    $changedFields['line_unit_cost_' . $dsi->id] = [
                                        'old'  => $oldCost,
                                        'new'  => $newCost,
                                        'item' => $item->description,
                                    ];
                                }
                            }
                            continue;
                        }
                    }

                    // New line (no dsi_id or invalid) — create fresh
                    $dsi = DeliverySubsidyItem::create([
                        'delivery_subsidy_id' => $deliverySubsidy->id,
                        'item_id'   => $line['item_id'],
                        'quantity'  => $line['quantity'],
                        'unit_cost' => $line['unit_cost'],
                        'amount'    => $line['quantity'] * $line['unit_cost'],
                    ]);
                    $submittedDsiIds[] = $dsi->id;
                }

                // Remove orphan lines that have no deliveries referencing them
                DeliverySubsidyItem::where('delivery_subsidy_id', $deliverySubsidy->id)
                    ->whereNotIn('id', $submittedDsiIds)
                    ->whereDoesntHave('deliveryItems')
                    ->delete();

                // ── Cascade RIS number change through the full transfer chain ──
                if ($oldRisNumber !== $newRisNumber) {
                    $deliverySubsidy->loadMissing('items');
                    foreach ($deliverySubsidy->items as $dsi) {
                        $item = $dsi->item()->withoutGlobalScopes()->first();
                        if ($item) {
                            $cascadeSvc->cascadeRisNumber($item, $newRisNumber, $cascadeSummary);
                        }
                    }
                }

                // ── Cascade supplier name to delivery + transfer stock card entries ──
                if ($newSupplierId !== (int) $oldSupplierId) {
                    $newSupplierName = Supplier::find($newSupplierId)?->name ?? '';
                    $deliveryIds     = $deliverySubsidy->deliveries()->pluck('id');
                    $cascadeSvc->cascadeSupplierName($deliveryIds, $newSupplierName, $cascadeSummary);
                }
            } else {
                // No deliveries yet — delete all lines and recreate from scratch
                $deliverySubsidy->items()->delete();
                foreach ($request->items as $line) {
                    DeliverySubsidyItem::create([
                        'delivery_subsidy_id' => $deliverySubsidy->id,
                        'item_id'   => $line['item_id'],
                        'quantity'  => $line['quantity'],
                        'unit_cost' => $line['unit_cost'],
                        'amount'    => $line['quantity'] * $line['unit_cost'],
                    ]);
                }
            }

            // ── Write audit log ───────────────────────────────────────────
            $cascadeSvc->recordAudit($deliverySubsidy->id, $changedFields, $cascadeSummary);
        });

        return redirect()->route('delivery_subsidies.show', $deliverySubsidy)->with('success', 'Delivery / Subsidy updated.');
    }

    public function destroy(DeliverySubsidy $deliverySubsidy)
    {
        abort_unless(Auth::user()->canWrite(), 403);

        DB::transaction(function () use ($deliverySubsidy) {
            // Eager-load to avoid N+1 inside the nested loops
            $deliverySubsidy->loadMissing('deliveries.items.item');

            foreach ($deliverySubsidy->deliveries as $delivery) {
                foreach ($delivery->items as $di) {
                    if ($di->item) {
                        // Clamp to zero — never go negative
                        $newQty = max(0, $di->item->quantity - $di->quantity_delivered);
                        $di->item->update(['quantity' => $newQty]);
                    }
                    StockCardEntry::where('reference_type', 'delivery')
                        ->where('reference_id', $delivery->id)
                        ->where('item_id', $di->item_id)
                        ->delete();
                }
                $delivery->items()->delete();
                $delivery->delete();
            }
            $deliverySubsidy->items()->delete();
            $deliverySubsidy->delete();
        });

        return redirect()->route('delivery_subsidies.index')
            ->with('success', "DR #{$deliverySubsidy->dr_number} deleted and stock reversed.");
    }

    public function delivery(DeliverySubsidy $deliverySubsidy)
    {
        $user = Auth::user();
        if (! $this->userCanAccessWarehouse($user, $deliverySubsidy->warehouse_id)) {
            abort(403);
        }
        $deliverySubsidy->load(['items.item', 'supplier', 'warehouse']);

        return view('delivery_subsidies.delivery', compact('deliverySubsidy'));
    }

    public function storeDelivery(Request $request, DeliverySubsidy $deliverySubsidy)
    {
        $user = Auth::user();
        if (! $this->userCanAccessWarehouse($user, $deliverySubsidy->warehouse_id)) {
            abort(403);
        }

        $request->validate([
            'delivery_date'      => 'required|date',
            'dr_number'          => 'required|string|max:100',
            'batch_number'       => 'nullable|string|max:100',
            'condition_status'   => 'required|string|in:good,damaged,partial',
            'quantity_delivered' => 'required|numeric|min:0.01',
            'items'              => 'required|array',
            'items.*.ds_item_id'         => 'required|exists:delivery_subsidy_items,id',
            'items.*.quantity_delivered' => 'required|numeric|min:0',
            'items.*.unit_cost'          => 'required|numeric|min:0.01',
            'items.*.expiration_date'    => 'nullable|date',
        ]);

        // Validate that each batch's ds_item_id belongs to this delivery/subsidy
        foreach ($request->items as $line) {
            if ((float) ($line['quantity_delivered'] ?? 0) <= 0) {
                continue;
            }
            $belongs = DeliverySubsidyItem::where('id', $line['ds_item_id'])
                ->where('delivery_subsidy_id', $deliverySubsidy->id)
                ->exists();
            if (! $belongs) {
                return back()->withInput()->with('error', 'Invalid item reference in submission.');
            }
        }

        DB::transaction(function () use ($request, $deliverySubsidy, $user) {
            // ── Delivery header: one row per shipment ──────────────────────
            $delivery = Delivery::create([
                'delivery_subsidy_id'  => $deliverySubsidy->id,
                'dr_number'          => $request->dr_number,
                'received_by'        => $user->id,
                'delivery_date'      => $request->delivery_date,
                'batch_number'       => $request->batch_number,
                'condition_status'   => $request->condition_status,
                'quantity_delivered' => $request->quantity_delivered,
                'remarks'            => $request->remarks,
            ]);

            // ── Line items: per-item stock detail ──────────────────────────
            foreach ($request->items as $line) {
                if ((float) $line['quantity_delivered'] <= 0) {
                    continue;
                }
                $dsItem = DeliverySubsidyItem::where('id', $line['ds_item_id'])
                    ->where('delivery_subsidy_id', $deliverySubsidy->id)
                    ->firstOrFail();

                $baseItem = $dsItem->item;
                if (! $baseItem) {
                    continue;
                }

                $actualUnitCost = round((float) $line['unit_cost'], 2);
                $expirationDate = $line['expiration_date'] ?? null;

                // Guard against over-delivery per line
                $remaining    = $dsItem->quantity - $dsItem->qty_delivered;
                $qtyDelivered = min((float) $line['quantity_delivered'], $remaining);
                if ($qtyDelivered <= 0) {
                    continue;
                }

                $item = Item::findOrCreateByUnitCost(
                    $deliverySubsidy->warehouse_id,
                    $baseItem->description,
                    $baseItem->unit,
                    $baseItem->category,
                    $actualUnitCost,
                    $baseItem->ris_number,
                    $expirationDate ?: ($baseItem->expiration_date?->format('Y-m-d')),
                    $baseItem->engas_unit_cost
                );

                if ($item->id !== $dsItem->item_id) {
                    $dsItem->update(['item_id' => $item->id, 'unit_cost' => $actualUnitCost]);
                }

                DeliveryItem::create([
                    'delivery_id'            => $delivery->id,
                    'delivery_subsidy_item_id' => $dsItem->id,
                    'item_id'                => $item->id,
                    'quantity_delivered'     => $qtyDelivered,
                    'unit_cost'              => $actualUnitCost,
                    'condition'              => $request->condition_status,
                ]);

                $dsItem->increment('qty_delivered', $qtyDelivered);

                $newQty = $item->quantity + $qtyDelivered;
                $item->update([
                    'quantity'   => $newQty,
                    'unit_cost'  => $actualUnitCost,
                    'ris_number' => $deliverySubsidy->ris_number,
                ]);

                StockCardEntry::create([
                    'item_id'             => $item->id,
                    'entry_date'          => $request->delivery_date,
                    'reference'           => $request->dr_number,   // shipment-level DR No.
                    'reference_type'      => 'delivery',
                    'reference_id'        => $delivery->id,
                    'receipt_qty'         => $qtyDelivered,
                    'receipt_unit_cost'   => $actualUnitCost,
                    'receipt_total_cost'  => $qtyDelivered * $actualUnitCost,
                    'issue_qty'           => 0,
                    'balance_qty'         => $newQty,
                    'balance_unit_cost'   => $actualUnitCost,
                    'balance_total_cost'  => $newQty * $actualUnitCost,
                    'from_to'             => $deliverySubsidy->supplier->name ?? '',
                ]);
            }

            // ── Recalculate PO status ──────────────────────────────────────
            $deliverySubsidy->updateDeliveryStatus();

            $adminIds  = User::where('role', 'admin')->pluck('id');
            $now       = now();
            $notifRows = $adminIds->map(fn ($id) => [
                'user_id'    => $id,
                'title'      => 'Delivery Recorded',
                'message'    => "Shipment DR #{$request->dr_number} recorded for TXN #{$deliverySubsidy->dr_number}.",
                'type'       => 'success',
                'link'       => route('delivery_subsidies.show', $deliverySubsidy->id),
                'is_read'    => false,
                'created_at' => $now,
                'updated_at' => $now,
            ])->toArray();

            if (! empty($notifRows)) {
                \App\Models\SystemNotification::insert($notifRows);
            }
        });

        return redirect()->route('delivery_subsidies.show', $deliverySubsidy)
            ->with('success', 'Shipment recorded and stock updated.');
    }

    /**
     * Admin: show the edit form for a single delivery record.
     */
    public function editDelivery(DeliverySubsidy $deliverySubsidy, Delivery $delivery)
    {
        abort_unless(Auth::user()->canWrite(), 403);
        abort_unless($delivery->delivery_subsidy_id === $deliverySubsidy->id, 404);

        $delivery->load(['items.item', 'items.deliverySubsidyItem']);
        $deliverySubsidy->load(['supplier', 'warehouse']);

        return view('delivery_subsidies.edit_delivery', compact('deliverySubsidy', 'delivery'));
    }

    /**
     * Admin: apply edits to a delivery record and correct stock/stock-card accordingly.
     *
     * Strategy per line:
     *   old_qty  = what was previously recorded
     *   new_qty  = what the admin is submitting now
     *   delta    = new_qty - old_qty
     *
     *   item.quantity  += delta          (positive = more stock, negative = less)
     *   ds_item.qty_delivered += delta
     *   stock_card entry receipt_qty updated; balance columns recalculated
     */
    public function updateDelivery(Request $request, DeliverySubsidy $deliverySubsidy, Delivery $delivery)
    {
        abort_unless(Auth::user()->canWrite(), 403);
        abort_unless($delivery->delivery_subsidy_id === $deliverySubsidy->id, 404);

        $request->validate([
            'delivery_date'      => 'required|date',
            'dr_number'          => 'required|string|max:100',
            'batch_number'       => 'nullable|string|max:100',
            'condition_status'   => 'required|string|in:good,damaged,partial',
            'quantity_delivered' => 'required|numeric|min:0',
            'remarks'            => 'nullable|string|max:1000',
            'items'              => 'required|array|min:1',
            'items.*.di_id'              => 'required|exists:delivery_items,id',
            'items.*.quantity_delivered' => 'required|numeric|min:0',
            'items.*.unit_cost'          => 'required|numeric|min:0.01',
        ]);

        DB::transaction(function () use ($request, $deliverySubsidy, $delivery) {
            // Update delivery header (dr_number + quantity_delivered live here)
            $delivery->update([
                'dr_number'          => $request->dr_number,
                'delivery_date'      => $request->delivery_date,
                'batch_number'       => $request->batch_number,
                'condition_status'   => $request->condition_status,
                'quantity_delivered' => $request->quantity_delivered,
                'remarks'            => $request->remarks,
            ]);

            foreach ($request->items as $line) {
                /** @var DeliveryItem $di */
                $di = DeliveryItem::with(['item', 'deliverySubsidyItem'])->findOrFail($line['di_id']);

                if ($di->delivery_id !== $delivery->id) {
                    continue;
                }

                $oldQty  = $di->quantity_delivered;
                $newQty  = (float) $line['quantity_delivered'];
                $newCost = round((float) $line['unit_cost'], 2);
                $delta   = $newQty - $oldQty;

                $item  = $di->item;
                $dsItem = $di->deliverySubsidyItem;

                if (! $item || ! $dsItem) {
                    continue;
                }

                $newItemQty = max(0, $item->quantity + $delta);
                $item->update([
                    'quantity'   => $newItemQty,
                    'unit_cost'  => $newCost,
                    'ris_number' => $deliverySubsidy->ris_number,
                ]);

                // Cascade unit cost changes to related RIS and stock transfer records
                if (abs($delta) > 0.0001 || abs($di->unit_cost - $newCost) > 0.001) {
                    RequisitionItem::where('item_id', $item->id)
                        ->update(['unit_cost' => $newCost]);
                    StockTransferItem::where('item_id', $item->id)
                        ->update(['unit_cost' => $newCost]);
                }

                // Adjust delivery/subsidy item qty_delivered
                $newDsDelivered = max(0, $dsItem->qty_delivered + $delta);
                $dsItem->update([
                    'qty_delivered' => $newDsDelivered,
                    'unit_cost'     => $newCost,
                    'amount'        => $dsItem->quantity * $newCost,
                ]);

                // Update the delivery item row
                $di->update([
                    'quantity_delivered' => $newQty,
                    'unit_cost'          => $newCost,
                    'condition'          => $request->condition_status,
                ]);

                $entry = StockCardEntry::where('reference_type', 'delivery')
                    ->where('reference_id', $delivery->id)
                    ->where('item_id', $item->id)
                    ->first();

                if ($entry) {
                    $entry->update([
                        'entry_date'         => $request->delivery_date,
                        'reference'          => $request->dr_number,
                        'receipt_qty'        => $newQty,
                        'receipt_unit_cost'  => $newCost,
                        'receipt_total_cost' => $newQty * $newCost,
                        'balance_qty'        => $newItemQty,
                        'balance_unit_cost'  => $newCost,
                        'balance_total_cost' => $newItemQty * $newCost,
                    ]);
                }
            }

            $deliverySubsidy->updateDeliveryStatus();
        });

        return redirect()
            ->route('delivery_subsidies.show', $deliverySubsidy)
            ->with('success', 'Shipment updated and stock adjusted.');
    }
}
