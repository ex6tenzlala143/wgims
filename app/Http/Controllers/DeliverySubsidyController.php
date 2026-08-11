<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesWarehouse;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Item;
use App\Models\ItemCatalogItem;
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
        $query = DeliverySubsidy::with(['supplier', 'warehouse', 'creator', 'items.warehouse', 'deliveries.items.warehouse']);

        $scoped = $this->applyWarehouseScope($query, $user, $request->warehouse_id ? (int) $request->warehouse_id : null);

        // Multi-warehouse deliveries: a record is also visible when any of its
        // line items OR any of its dispatches is assigned to a warehouse the
        // user belongs to.
        if ($scoped) {
            $ids = $this->getUserWarehouseIds($user);
            if ($ids !== null) {
                $query->orWhereHas('items', fn ($q) => $q->whereIn('warehouse_id', $ids))
                    ->orWhereHas('deliveries.items', fn ($q) => $q->whereIn('warehouse_id', $ids));
            } elseif ($request->warehouse_id) {
                $query->orWhereHas('items', fn ($q) => $q->where('warehouse_id', (int) $request->warehouse_id))
                    ->orWhereHas('deliveries.items', fn ($q) => $q->where('warehouse_id', (int) $request->warehouse_id));
            }
        }

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

        $data = compact('pos', 'warehouses', 'accountCodes', 'descriptions');

        // The "New Delivery/Subsidy" button opens a modal on this page — the
        // modal form needs the same data the standalone create page uses.
        if ($user->canCreate()) {
            $data = array_merge($data, $this->createFormData($user));
        }

        return view('delivery_subsidies.index', $data);
    }

    /**
     * Data shared by the standalone create page and the modal form embedded
     * on the index page.
     */
    protected function createFormData(User $user): array
    {
        $suppliers = Supplier::where('is_active', true)->orderBy('name')->get();

        if ($user->hasAdminAccess()) {
            $warehouses = Warehouse::where('is_active', true)->get();
            $items = Item::where('is_active', true)->with('warehouse')->orderBy('description')->get();
        } else {
            $warehouseIds = $this->getUserWarehouseIds($user);
            $warehouses = Warehouse::whereIn('id', $warehouseIds)->where('is_active', true)->get();
            $items = Item::whereIn('warehouse_id', $warehouseIds)->where('is_active', true)->orderBy('description')->get();
        }

        // Configured item names (per category) that drive the searchable
        // item dropdown in the New Delivery/Subsidy form.
        $catalogItems = ItemCatalogItem::with('category')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return compact('suppliers', 'warehouses', 'items', 'catalogItems');
    }

    public function create()
    {
        $user = Auth::user();

        // Center staff cannot create delivery/subsidies
        if ($user->role === \App\Models\User::ROLE_STAFF) {
            abort(403);
        }

        return view('delivery_subsidies.create', $this->createFormData($user));
    }

    public function store(Request $request)
    {
        $request->validate([
            'ris_number'         => 'required|string|max:255',
            'supplier_id'        => 'required|exists:suppliers,id',
            'date'               => 'required|date',
            'items'              => 'required|array|min:1',
            'items.*.description'    => 'required|string|max:255',
            'items.*.unit'           => 'required|string',
            'items.*.category' => 'required|string|in:'.implode(',', array_keys(Item::getCategories())),
            'items.*.quantity'       => 'required|numeric|min:0.01',
            'items.*.expiration_date'=> 'nullable|date',
            'items.*.catalog_item_id'=> 'nullable|exists:item_catalog_items,id',
            'items.*.account_code'   => 'nullable|string|max:50',
        ]);

        $user = Auth::user();

        DB::transaction(function () use ($request, $user) {
            // quantity_requested = sum of all line item quantities.
            // Unit Cost and Warehouse are deliberately NOT captured at creation —
            // they are decided by the dispatcher when the shipment is recorded.
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
                'warehouse_id' => null,
                'created_by' => $user->id,
                'date' => $request->date,
                'ris_number' => $request->ris_number,
                'place_of_delivery' => $request->place_of_delivery,
                'total_amount' => 0,
                'quantity_requested' => $quantityRequested,
                'remarks' => $request->remarks,
            ]);

            foreach ($request->items as $line) {
                DeliverySubsidyItem::create([
                    'delivery_subsidy_id' => $po->id,
                    'item_id' => ! empty($line['item_id']) ? (int) $line['item_id'] : null,
                    'catalog_item_id' => ! empty($line['catalog_item_id']) ? (int) $line['catalog_item_id'] : null,
                    'account_code' => $line['account_code'] ?? null,
                    'warehouse_id' => null,
                    'quantity' => $line['quantity'],
                    'unit_cost' => null,
                    'amount' => null,
                    'description' => $line['description'],
                    'unit' => $line['unit'],
                    'category' => $line['category'],
                    'expiration_date' => $line['expiration_date'] ?? null,
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
        if (! $this->canAccessDeliverySubsidy($user, $deliverySubsidy)) {
            abort(403);
        }
        $deliverySubsidy->load([
            'supplier', 'warehouse', 'creator',
            'items.item', 'items.warehouse',
            'deliveries.items.item',
            'deliveries.items.warehouse',
            'deliveries.items.deliverySubsidyItem',
            'deliveries.receiver',
        ]);

        $data = compact('deliverySubsidy');

        // The "Edit PO" button opens the shared edit modal — give it the same
        // option lists the modal needs to build its item autocomplete.
        if ($user->canWrite()) {
            $data = array_merge($data, $this->createFormData($user));
        }

        return view('delivery_subsidies.show', $data);
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

        // Editing happens inside a modal on the index page. Send the admin back
        // there with the modal pre-opened for this record.
        return redirect()->route('delivery_subsidies.index', ['edit' => $deliverySubsidy->id]);
    }

    /**
     * JSON payload that feeds the Edit Subsidy modal — the complete record
     * exactly as saved, plus per-line flags for lines that already have
     * deliveries recorded (those cannot be removed).
     */
    public function editData(DeliverySubsidy $deliverySubsidy)
    {
        abort_unless(Auth::user()->canWrite(), 403);

        $deliverySubsidy->load(['items.deliveryItems']);

        $items = $deliverySubsidy->items->map(fn ($dsi) => [
            'dsi_id'          => $dsi->id,
            'item_id'         => $dsi->item_id,
            'catalog_item_id' => $dsi->catalog_item_id,
            'account_code'    => $dsi->account_code,
            'description'     => $dsi->item?->description ?? $dsi->description,
            'unit'            => $dsi->item?->unit ?? $dsi->unit,
            'category'        => $dsi->item?->category ?? $dsi->category,
            'quantity'        => (float) $dsi->quantity,
            'expiration_date' => $dsi->expiration_date?->format('Y-m-d')
                ?? ($dsi->item?->expiration_date?->format('Y-m-d') ?? ''),
            'has_deliveries'  => $dsi->deliveryItems()->count() > 0,
        ]);

        return response()->json([
            'id'                 => $deliverySubsidy->id,
            'ris_number'         => $deliverySubsidy->ris_number,
            'supplier_id'        => $deliverySubsidy->supplier_id,
            'date'               => $deliverySubsidy->date?->format('Y-m-d'),
            'place_of_delivery'  => $deliverySubsidy->place_of_delivery,
            'remarks'            => $deliverySubsidy->remarks,
            'status'             => $deliverySubsidy->status,
            'quantity_requested' => (float) $deliverySubsidy->quantity_requested,
            'has_deliveries'     => $deliverySubsidy->deliveries()->count() > 0,
            'items'              => $items,
        ]);
    }

    public function update(Request $request, DeliverySubsidy $deliverySubsidy)
    {
        abort_unless(Auth::user()->canWrite(), 403);

        // Unit Cost and Warehouse are deliberately NOT editable here — they are
        // only assigned by the dispatcher when a delivery is recorded.
        $request->validate([
            'ris_number'              => 'required|string|max:255',
            'supplier_id'             => 'required|exists:suppliers,id',
            'date'                    => 'required|date',
            'status'                  => 'required|string|in:pending,partial,fully_delivered,cancelled',
            'quantity_requested'      => 'required|numeric|min:0.01',
            'items'                   => 'required|array|min:1',
            'items.*.item_id'         => 'nullable|exists:items,id',
            'items.*.catalog_item_id' => 'nullable|exists:item_catalog_items,id',
            'items.*.account_code'    => 'nullable|string|max:50',
            'items.*.description'     => 'required|string|max:255',
            'items.*.unit'            => 'required|string',
            'items.*.category'        => 'required|string|in:'.implode(',', array_keys(Item::getCategories())),
            'items.*.quantity'        => 'required|numeric|min:0.01',
            'items.*.expiration_date' => 'nullable|date',
        ]);

        // Enforce: 'fully_delivered' may only be set when quantity delivered >= quantity requested.
        if ($request->status === 'fully_delivered') {
            $requested  = (float) $deliverySubsidy->quantity_requested;
            $delivered  = (float) $deliverySubsidy->totalDelivered();
            $epsilon    = 0.0001;

            if ($requested <= 0 || $delivered < $requested - $epsilon) {
                $error = ['status' => 'Status cannot be set to "Fully Delivered" — quantity delivered ('
                    . number_format($delivered, 4) . ') has not yet reached the quantity requested ('
                    . number_format($requested, 4) . '). Record the remaining shipment first.'];

                if ($request->expectsJson()) {
                    return response()->json(['errors' => $error], 422);
                }

                return back()->withInput()->withErrors($error);
            }
        }

        $cascadeSvc = new DeliverySubsidyCascadeService();

        DB::transaction(function () use ($request, $deliverySubsidy, $cascadeSvc) {
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

            // ── Update header (total_amount / warehouse stay untouched here:
            //    they are only meaningful once a dispatch has been recorded) ──
            $deliverySubsidy->update([
                'supplier_id'        => $request->supplier_id,
                'date'               => $request->date,
                'ris_number'         => $newRisNumber,
                'place_of_delivery'  => $request->place_of_delivery,
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
                            $dsi->update([
                                'item_id'         => $line['item_id'] ?? $dsi->item_id,
                                'catalog_item_id' => $line['catalog_item_id'] ?? $dsi->catalog_item_id,
                                'account_code'    => $line['account_code'] ?? $dsi->account_code,
                                'description'     => $line['description'],
                                'unit'            => $line['unit'],
                                'category'        => $line['category'],
                                'quantity'        => $line['quantity'],
                                'expiration_date' => $line['expiration_date'] ?? null,
                            ]);
                            $submittedDsiIds[] = $dsi->id;
                            continue;
                        }
                    }

                    // New line (no dsi_id or invalid) — create fresh
                    $dsi = DeliverySubsidyItem::create([
                        'delivery_subsidy_id' => $deliverySubsidy->id,
                        'item_id'         => $line['item_id'] ?? null,
                        'catalog_item_id' => $line['catalog_item_id'] ?? null,
                        'account_code'    => $line['account_code'] ?? null,
                        'description'     => $line['description'],
                        'unit'            => $line['unit'],
                        'category'        => $line['category'],
                        'quantity'        => $line['quantity'],
                        'expiration_date' => $line['expiration_date'] ?? null,
                        'unit_cost'       => null,
                        'warehouse_id'    => null,
                        'amount'          => null,
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
                        'item_id'         => $line['item_id'] ?? null,
                        'catalog_item_id' => $line['catalog_item_id'] ?? null,
                        'account_code'    => $line['account_code'] ?? null,
                        'description'     => $line['description'],
                        'unit'            => $line['unit'],
                        'category'        => $line['category'],
                        'quantity'        => $line['quantity'],
                        'expiration_date' => $line['expiration_date'] ?? null,
                        'unit_cost'       => null,
                        'warehouse_id'    => null,
                        'amount'          => null,
                    ]);
                }
            }

            // ── Write audit log ───────────────────────────────────────────
            $cascadeSvc->recordAudit($deliverySubsidy->id, $changedFields, $cascadeSummary);
        });

        $success = 'Delivery / Subsidy updated.';

        if ($request->expectsJson()) {
            return response()->json(['redirect' => route('delivery_subsidies.show', $deliverySubsidy), 'success' => $success]);
        }

        return redirect()->route('delivery_subsidies.show', $deliverySubsidy)->with('success', $success);
    }

    public function destroy(DeliverySubsidy $deliverySubsidy)
    {
        abort_unless(Auth::user()->canWrite(), 403);

        DB::transaction(function () use ($deliverySubsidy) {
            // Eager-load to avoid N+1 inside the nested loops
            $deliverySubsidy->loadMissing('deliveries.items.item');

            $affectedItemIds = [];

            foreach ($deliverySubsidy->deliveries as $delivery) {
                foreach ($delivery->items as $di) {
                    if ($di->item) {
                        // Reverse the exact movement this delivery line created:
                        // dispatching added stock to the warehouse, so deleting
                        // subtracts that same quantity back. Clamped to zero so a
                        // stock card / other transaction can never push it negative.
                        $newQty = max(0, $di->item->quantity - $di->quantity_delivered);
                        $di->item->update(['quantity' => $newQty]);
                        $affectedItemIds[$di->item_id] = true;
                    }
                    // Remove this delivery line's stock-card receipt entirely —
                    // never leave a movement on the card for a deleted shipment.
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

            // Rebuild running stock-card balances so the entries that remain
            // (from other transactions) keep correct cumulative figures.
            foreach (array_keys($affectedItemIds) as $itemId) {
                StockCardEntry::recalculateBalancesForItem($itemId);
            }
        });

        return redirect()->route('delivery_subsidies.index')
            ->with('success', "DR #{$deliverySubsidy->dr_number} deleted and stock reversed.");
    }

    public function delivery(DeliverySubsidy $deliverySubsidy)
    {
        $user = Auth::user();
        if (! $this->canAccessDeliverySubsidy($user, $deliverySubsidy)) {
            abort(403);
        }
        $deliverySubsidy->load(['items.item', 'items.warehouse', 'supplier', 'warehouse']);

        // Warehouses the dispatcher may route this shipment to (center users are
        // limited to the warehouses they belong to).
        $warehouses = $user->hasAdminAccess()
            ? Warehouse::where('is_active', true)->orderBy('name')->get()
            : Warehouse::whereIn('id', $this->getUserWarehouseIds($user))->where('is_active', true)->orderBy('name')->get();

        return view('delivery_subsidies.delivery', compact('deliverySubsidy', 'warehouses'));
    }

    public function storeDelivery(Request $request, DeliverySubsidy $deliverySubsidy)
    {
        $user = Auth::user();
        if (! $this->canAccessDeliverySubsidy($user, $deliverySubsidy)) {
            abort(403);
        }

        // Build per-item validation rules. A line is treated as a real dispatch
        // ONLY when it still has a remaining quantity (requested − already
        // delivered) AND a positive quantity is being submitted for it.
        //
        // Lines that are already fully delivered are ignored/skipped — they must
        // never trigger required-field errors (qty, unit cost, warehouse, DR#).
        // The dispatch fields are still strictly required for lines that are
        // actually being dispatched.
        $rules = [
            'delivery_date'      => 'required|date',
            'dr_number'          => 'nullable|string|max:100',
            'batch_number'       => 'nullable|string|max:100',
            'condition_status'   => 'required|string|in:good,damaged,partial',
            'quantity_delivered' => 'required|numeric|min:0.01',
            'items'              => 'required|array',
        ];

        foreach ($request->input('items', []) as $key => $line) {
            $dsItem = DeliverySubsidyItem::where('id', $line['ds_item_id'] ?? null)
                ->where('delivery_subsidy_id', $deliverySubsidy->id)
                ->first();

            $remaining    = $dsItem ? max(0, (float) $dsItem->quantity - (float) $dsItem->qty_delivered) : 0;
            $submittedQty = (float) ($line['quantity_delivered'] ?? 0);
            $dispatching  = $remaining > 0 && $submittedQty > 0;

            $rules["items.{$key}.ds_item_id"]      = ['required', 'exists:delivery_subsidy_items,id'];
            $rules["items.{$key}.quantity_delivered"] = ['numeric', 'min:0'];
            $rules["items.{$key}.unit_cost"]          = ['numeric', 'min:0.01'];
            $rules["items.{$key}.engas_unit_cost"]    = ['numeric', 'min:0'];
            $rules["items.{$key}.warehouse_id"]       = ['exists:warehouses,id'];
            $rules["items.{$key}.expiration_date"]    = ['nullable', 'date'];
            $rules["items.{$key}.dr_number"]          = ['string', 'max:100'];

            if ($dispatching) {
                $rules["items.{$key}.quantity_delivered"][] = 'required';
                $rules["items.{$key}.unit_cost"][]          = 'required';
                $rules["items.{$key}.engas_unit_cost"][]    = 'required';
                $rules["items.{$key}.warehouse_id"][]       = 'required';
                $rules["items.{$key}.dr_number"][]          = 'required';
            }
        }

        $request->validate($rules);

        // Validate that each batch's ds_item_id belongs to this delivery/subsidy,
        // and enforce the per-item remaining-quantity limit. This is the
        // authoritative backend check — it cannot be bypassed by skipping the
        // frontend (e.g. direct requests/API calls).
        $remainingByDsItem = [];
        foreach ($request->items as $key => $line) {
            if ((float) ($line['quantity_delivered'] ?? 0) <= 0) {
                continue;
            }
            $dsItem = DeliverySubsidyItem::where('id', $line['ds_item_id'])
                ->where('delivery_subsidy_id', $deliverySubsidy->id)
                ->first();
            if (! $dsItem) {
                return back()->withInput()->with('error', 'Invalid item reference in submission.');
            }

            // Non-admin users may only dispatch items to warehouses they belong to
            if ($user->isCenterUser() && ! $this->userCanAccessWarehouse($user, (int) $line['warehouse_id'])) {
                abort(403, 'You cannot dispatch items to that warehouse.');
            }

            $remaining = (float) $dsItem->quantity - (float) $dsItem->qty_delivered;
            $remainingByDsItem[$dsItem->id]['remaining'] = max(0, $remaining);
            $remainingByDsItem[$dsItem->id]['lines'][$key] = (float) $line['quantity_delivered'];
        }

        if (empty($remainingByDsItem)) {
            return back()->withInput()->with('error', 'No items are being dispatched — enter a quantity for at least one item with remaining stock.');
        }

        // Reject any item whose submitted quantity — a single batch or the
        // combined batches for that line — exceeds what is still needed.
        // If remaining is 0, any positive quantity is rejected outright.
        $errors = [];
        foreach ($remainingByDsItem as $dsItemId => $info) {
            $remaining = $info['remaining'];
            foreach ($info['lines'] as $key => $qty) {
                if ($qty > $remaining + 0.0001) {
                    $errors["items.{$key}.quantity_delivered"] =
                        "Quantity exceeds the remaining quantity of {$remaining} for this item.";
                }
            }
            if (array_sum($info['lines']) > $remaining + 0.0001) {
                foreach ($info['lines'] as $key => $qty) {
                    if (! isset($errors["items.{$key}.quantity_delivered"])) {
                        $errors["items.{$key}.quantity_delivered"] =
                            "The combined batches exceed the remaining quantity of {$remaining} for this item.";
                    }
                }
            }
        }
        if (! empty($errors)) {
            return back()->withInput()->withErrors($errors);
        }

        DB::transaction(function () use ($request, $deliverySubsidy, $user) {
            // ── Delivery header: one row per shipment ──────────────────────
            // DR# is now tracked per dispatched item; the header keeps an
            // optional reference for legacy records only.
            $delivery = Delivery::create([
                'delivery_subsidy_id'  => $deliverySubsidy->id,
                'dr_number'          => $request->dr_number ?? null,
                'received_by'        => $user->id,
                'delivery_date'      => $request->delivery_date,
                'batch_number'       => $request->batch_number,
                'condition_status'   => $request->condition_status,
                'quantity_delivered' => $request->quantity_delivered,
                'remarks'            => $request->remarks,
            ]);

            // ── Line items: per-item stock detail ──────────────────────────
            // Rows without a positive quantity (e.g. fully delivered items that
            // were ignored by validation) are skipped entirely.
            foreach ($request->items as $line) {
                if ((float) ($line['quantity_delivered'] ?? 0) <= 0) {
                    continue;
                }

                $dsItem = DeliverySubsidyItem::where('id', $line['ds_item_id'])
                    ->where('delivery_subsidy_id', $deliverySubsidy->id)
                    ->firstOrFail();

                $baseItem = $dsItem->item;

                // Lines created from catalog item names may not be resolved to
                // a real inventory Item yet — fall back to the snapshots taken
                // at creation (description / unit / category / account code).
                $baseDescription = $baseItem->description ?? $dsItem->description;
                $baseUnit        = $baseItem->unit ?? $dsItem->unit;
                $baseCategory    = $baseItem->category ?? $dsItem->category;

                if (! $baseDescription || ! $baseUnit || ! $baseCategory) {
                    continue;
                }

                $actualUnitCost = round((float) $line['unit_cost'], 2);
                $engasUnitCost  = ($line['engas_unit_cost'] ?? null) !== null && $line['engas_unit_cost'] !== ''
                    ? round((float) $line['engas_unit_cost'], 2)
                    : null;
                $expirationDate = $line['expiration_date'] ?? null;
                $drNumber       = trim((string) $line['dr_number']);

                // Quantity was already validated against the remaining amount
                // above — use it verbatim (rows with 0 are simply skipped).
                $qtyDelivered = (float) $line['quantity_delivered'];
                if ($qtyDelivered <= 0) {
                    continue;
                }

                // Total ENGAS cost is always computed server-side — the client
                // cannot override it (qty × engas unit cost).
                $engasTotalCost = $engasUnitCost !== null ? round($qtyDelivered * $engasUnitCost, 2) : null;

                // Stock lands in the warehouse the dispatcher selected for this
                // item (chosen at dispatch time, not at creation).
                $sourceWarehouseId = (int) $line['warehouse_id'];

                $item = Item::findOrCreateByUnitCost(
                    $sourceWarehouseId,
                    $baseDescription,
                    $baseUnit,
                    $baseCategory,
                    $actualUnitCost,
                    $baseItem?->ris_number ?? $deliverySubsidy->ris_number,
                    $expirationDate ?: ($baseItem?->expiration_date?->format('Y-m-d')),
                    $engasUnitCost ?? $baseItem?->engas_unit_cost,
                    $dsItem->account_code ?: ($baseItem->account_code ?? null)
                );

                if ($item->id !== $dsItem->item_id || $dsItem->unit_cost != $actualUnitCost) {
                    $dsItemUpdate = [
                        'item_id'   => $item->id,
                        'unit_cost' => $actualUnitCost,
                    ];

                    // The requested line records its default destination only on
                    // the FIRST dispatch; later partial dispatches to other
                    // warehouses must not overwrite it (each dispatch keeps its
                    // own warehouse on the delivery_items row below).
                    if (empty($dsItem->warehouse_id)) {
                        $dsItemUpdate['warehouse_id'] = $sourceWarehouseId;
                    }

                    $dsItem->update($dsItemUpdate);
                }

                if ($dsItem->amount != $dsItem->quantity * $actualUnitCost) {
                    $dsItem->update(['amount' => round($dsItem->quantity * $actualUnitCost, 2)]);
                }

                DeliveryItem::create([
                    'delivery_id'            => $delivery->id,
                    'delivery_subsidy_item_id' => $dsItem->id,
                    'item_id'                => $item->id,
                    'warehouse_id'           => $sourceWarehouseId,
                    'quantity_delivered'     => $qtyDelivered,
                    'unit_cost'              => $actualUnitCost,
                    'engas_unit_cost'        => $engasUnitCost,
                    'engas_total_cost'       => $engasTotalCost,
                    'condition'              => $request->condition_status,
                    'dr_number'              => $drNumber,
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
                    'reference'           => $drNumber,   // per-item DR No.
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

            // ── Recalculate subsidy status + totals ────────────────────────
            // Recompute the header total_amount from dispatched line items
            // (amounts are only known once unit cost is set at dispatch time).
            $dispatchedTotal = DeliverySubsidyItem::where('delivery_subsidy_id', $deliverySubsidy->id)
                ->get()
                ->sum(fn ($i) => $i->amount ?? ($i->unit_cost ? $i->quantity * $i->unit_cost : 0));

            $deliverySubsidy->update(['total_amount' => round($dispatchedTotal, 2)]);

            $deliverySubsidy->updateDeliveryStatus();

            $adminIds  = User::where('role', 'admin')->pluck('id');
            $now       = now();
            $notifRows = $adminIds->map(fn ($id) => [
                'user_id'    => $id,
                'title'      => 'Delivery Recorded',
                'message'    => "Shipment recorded for TXN #{$deliverySubsidy->dr_number}.",
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

        $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get();

        return view('delivery_subsidies.edit_delivery', compact('deliverySubsidy', 'delivery', 'warehouses'));
    }

    /**
     * Admin: apply edits to a delivery record and correct stock/stock-card accordingly.
     *
     * Strategy per line:
     *   old_qty  = what was previously recorded
     *   new_qty  = what the admin is submitting now
     *   delta    = new_qty - old_qty
     *
     * Same warehouse:
     *   item.quantity  += delta          (positive = more stock, negative = less)
     *   ds_item.qty_delivered += delta
     *
     * Different warehouse:
     *   old item.quantity   -= old_qty   (stock returns to the old warehouse)
     *   new item.quantity   += new_qty   (stock is re-added in the new warehouse)
     *   The delivery stock-card entry is re-pointed at the new item so no stock
     *   is ever double-counted.
     *
     * After every edit the affected items' stock-card running balances are
     * recomputed from scratch (entry-by-entry), so later entries never go stale.
     */
    public function updateDelivery(Request $request, DeliverySubsidy $deliverySubsidy, Delivery $delivery)
    {
        abort_unless(Auth::user()->canWrite(), 403);
        abort_unless($delivery->delivery_subsidy_id === $deliverySubsidy->id, 404);

        // Dispatch fields are strictly required only for lines that still carry
        // a positive quantity; a line set to 0 fully reverses and needs none.
        $rules = [
            'delivery_date'      => 'required|date',
            'dr_number'          => 'nullable|string|max:100',
            'batch_number'       => 'nullable|string|max:100',
            'condition_status'   => 'required|string|in:good,damaged,partial',
            'remarks'            => 'nullable|string|max:1000',
            'items'              => 'required|array|min:1',
            'items.*.di_id'              => 'required|exists:delivery_items,id',
            'items.*.quantity_delivered' => 'required|numeric|min:0',
            'items.*.unit_cost'          => 'numeric|min:0.01',
            'items.*.engas_unit_cost'    => 'numeric|min:0',
            'items.*.warehouse_id'       => 'exists:warehouses,id',
            'items.*.expiration_date'    => 'nullable|date',
            'items.*.dr_number'          => 'string|max:100',
        ];

        foreach ($request->input('items', []) as $key => $line) {
            if ((float) ($line['quantity_delivered'] ?? 0) > 0) {
                $rules["items.{$key}.unit_cost"][]       = 'required';
                $rules["items.{$key}.engas_unit_cost"][] = 'required';
                $rules["items.{$key}.warehouse_id"][]    = 'required';
                $rules["items.{$key}.dr_number"][]       = 'required';
            }
        }

        $request->validate($rules);

        // Enforce the per-item remaining-quantity limit on edits too, so an
        // admin cannot push a line's cumulative dispatched quantity past its
        // requested quantity (remaining = quantity − already dispatched).
        $maxPerDsItem = [];
        foreach ($request->items as $idx => $line) {
            $di = DeliveryItem::with('deliverySubsidyItem')->find($line['di_id'] ?? null);
            if (! $di || $di->delivery_id !== $delivery->id || ! $di->deliverySubsidyItem) {
                continue;
            }
            $dsItem = $di->deliverySubsidyItem;
            $remaining = max(0, (float) $dsItem->quantity - (float) $dsItem->qty_delivered);
            $maxPerDsItem[$dsItem->id]['remaining'] = $remaining;
            $maxPerDsItem[$dsItem->id]['oldSum'] = ($maxPerDsItem[$dsItem->id]['oldSum'] ?? 0) + (float) $di->quantity_delivered;
            $maxPerDsItem[$dsItem->id]['newSum'] = ($maxPerDsItem[$dsItem->id]['newSum'] ?? 0) + (float) $line['quantity_delivered'];
            $maxPerDsItem[$dsItem->id]['lines'][$idx] = [
                'old' => (float) $di->quantity_delivered,
                'new' => (float) $line['quantity_delivered'],
            ];
        }

        $editErrors = [];
        foreach ($maxPerDsItem as $dsItemId => $info) {
            $remaining = $info['remaining'];
            foreach ($info['lines'] as $idx => $q) {
                if ($q['new'] > $q['old'] + $remaining + 0.0001) {
                    $editErrors["items.{$idx}.quantity_delivered"] =
                        "Quantity exceeds the remaining quantity of {$remaining} for this item.";
                }
            }
            if ($info['newSum'] > $info['oldSum'] + $remaining + 0.0001) {
                foreach ($info['lines'] as $idx => $q) {
                    if (! isset($editErrors["items.{$idx}.quantity_delivered"])) {
                        $editErrors["items.{$idx}.quantity_delivered"] =
                            "The combined quantity exceeds the remaining quantity of {$remaining} for this item.";
                    }
                }
            }
        }
        if (! empty($editErrors)) {
            return back()->withInput()->withErrors($editErrors);
        }

        DB::transaction(function () use ($request, $deliverySubsidy, $delivery) {
            $affectedItemIds = [];

            foreach ($request->items as $line) {
                /** @var DeliveryItem $di */
                $di = DeliveryItem::with(['item', 'deliverySubsidyItem'])->findOrFail($line['di_id']);

                if ($di->delivery_id !== $delivery->id) {
                    continue;
                }

                $oldQty    = (float) $di->quantity_delivered;
                $newQty    = (float) $line['quantity_delivered'];
                $newCost   = round((float) $line['unit_cost'], 2);
                $newEngas  = ($line['engas_unit_cost'] ?? null) !== null && $line['engas_unit_cost'] !== ''
                    ? round((float) $line['engas_unit_cost'], 2)
                    : null;
                $newWarehouseId = (int) ($line['warehouse_id'] ?? 0);
                $newExpiry = ($line['expiration_date'] ?? null) ?: null;
                $newDr     = trim((string) $line['dr_number']);
                $delta     = $newQty - $oldQty;
                $oldCost   = (float) $di->unit_cost;

                $dsItem = $di->deliverySubsidyItem;
                $oldItem = $di->item;

                if (! $dsItem) {
                    continue;
                }

                $oldItemId = $oldItem ? $oldItem->id : null;
                $movedWarehouse = $oldItem && $newWarehouseId > 0 && (int) $oldItem->warehouse_id !== $newWarehouseId;

                // ── Resolve the item that ends up holding the stock ─────────
                if ($movedWarehouse && $newQty > 0) {
                    $baseDescription = $dsItem->item?->description ?? $dsItem->description;
                    $baseUnit        = $dsItem->item?->unit ?? $dsItem->unit;
                    $baseCategory    = $dsItem->item?->category ?? $dsItem->category;

                    // Reverse the old receipt from the old warehouse first.
                    $oldItem->update(['quantity' => max(0, $oldItem->quantity - $oldQty)]);
                    $affectedItemIds[$oldItem->id] = true;

                    if ($baseDescription && $baseUnit && $baseCategory) {
                        $item = Item::findOrCreateByUnitCost(
                            $newWarehouseId,
                            $baseDescription,
                            $baseUnit,
                            $baseCategory,
                            $newCost,
                            $dsItem->item?->ris_number ?? $deliverySubsidy->ris_number,
                            $newExpiry ?: ($dsItem->item?->expiration_date?->format('Y-m-d')),
                            $newEngas ?? $dsItem->item?->engas_unit_cost,
                            $dsItem->account_code ?: ($dsItem->item?->account_code ?? null)
                        );
                        $item->update([
                            'quantity'   => $item->quantity + $newQty,
                            'unit_cost'  => $newCost,
                            'ris_number' => $deliverySubsidy->ris_number,
                        ]);
                        $affectedItemIds[$item->id] = true;
                    } else {
                        // Cannot resolve a target item — keep the stock on the
                        // original item as a safe fallback (no double-count).
                        $item = $oldItem;
                        $item->update(['quantity' => $item->quantity + $newQty]);
                    }
                } else {
                    $item = $oldItem;

                    if ($item) {
                        $itemUpdate = [
                            'quantity'   => max(0, $item->quantity + $delta),
                            'unit_cost'  => $newCost,
                            'ris_number' => $deliverySubsidy->ris_number,
                        ];
                        if ($newExpiry) {
                            $itemUpdate['expiration_date'] = $newExpiry;
                        }
                        $item->update($itemUpdate);
                    }
                }

                if (! $item) {
                    continue;
                }
                $affectedItemIds[$item->id] = true;

                // Cascade unit cost changes to related RIS and stock transfer records
                if (abs($delta) > 0.0001 || abs($oldCost - $newCost) > 0.001) {
                    RequisitionItem::where('item_id', $item->id)
                        ->update(['unit_cost' => $newCost]);
                    StockTransferItem::where('item_id', $item->id)
                        ->update(['unit_cost' => $newCost]);
                }

                // Adjust delivery/subsidy item qty_delivered (never negative)
                $newDsDelivered = max(0, $dsItem->qty_delivered + $delta);
                $dsItem->update([
                    'qty_delivered' => $newDsDelivered,
                    'unit_cost'     => $newCost,
                    'amount'        => round($dsItem->quantity * $newCost, 2),
                ]);
                if ($newQty > 0) {
                    $dsItemUpdate = ['item_id' => $item->id];

                    // Same rule as the create path: the requested line keeps the
                    // default destination from its FIRST dispatch only. Each
                    // dispatch's own warehouse lives on the delivery_items row.
                    if (empty($dsItem->warehouse_id)) {
                        $dsItemUpdate['warehouse_id'] = $newWarehouseId;
                    }

                    $dsItem->update($dsItemUpdate);
                }

                // Total ENGAS cost is always computed server-side (qty × engas)
                $newEngasTotal = $newQty > 0 && $newEngas !== null ? round($newQty * $newEngas, 2) : null;

                // Update the delivery item row (re-point to the new item if the
                // warehouse moved, so the dispatch record follows the stock).
                $di->update([
                    'item_id'            => $item->id,
                    'warehouse_id'       => $newWarehouseId,
                    'quantity_delivered' => $newQty,
                    'unit_cost'          => $newCost,
                    'engas_unit_cost'    => $newEngas,
                    'engas_total_cost'   => $newEngasTotal,
                    'condition'          => $request->condition_status,
                    'dr_number'          => $newDr,
                ]);

                // ── Move/update the delivery stock-card entry ───────────────
                $entry = StockCardEntry::where('reference_type', 'delivery')
                    ->where('reference_id', $delivery->id)
                    ->where('item_id', $oldItemId)
                    ->first();

                if ($newQty <= 0) {
                    // Fully reversed line — remove its receipt from the stock card
                    if ($entry) {
                        $entry->delete();
                    }
                } elseif ($entry) {
                    $entry->update([
                        'item_id'            => $item->id,
                        'entry_date'         => $request->delivery_date,
                        'reference'          => $newDr,
                        'receipt_qty'        => $newQty,
                        'receipt_unit_cost'  => $newCost,
                        'receipt_total_cost' => round($newQty * $newCost, 2),
                        'from_to'            => $deliverySubsidy->supplier->name ?? $entry->from_to,
                    ]);
                } else {
                    StockCardEntry::create([
                        'item_id'             => $item->id,
                        'entry_date'          => $request->delivery_date,
                        'reference'           => $newDr,
                        'reference_type'      => 'delivery',
                        'reference_id'        => $delivery->id,
                        'receipt_qty'         => $newQty,
                        'receipt_unit_cost'   => $newCost,
                        'receipt_total_cost'  => round($newQty * $newCost, 2),
                        'issue_qty'           => 0,
                        'balance_qty'         => 0,
                        'balance_unit_cost'   => $newCost,
                        'balance_total_cost'  => 0,
                        'from_to'             => $deliverySubsidy->supplier->name ?? '',
                    ]);
                }
            }

            // Rebuild every affected item's running stock-card balances so later
            // entries stay consistent with the edited receipt.
            foreach (array_keys($affectedItemIds) as $itemId) {
                StockCardEntry::recalculateBalancesForItem($itemId);
            }

            // Header quantity must always equal the sum of its dispatched lines,
            // otherwise the subsidy status/fulfilment figures drift.
            $newHeaderQty = (float) $delivery->items()->sum('quantity_delivered');
            $delivery->update([
                'dr_number'          => $request->dr_number ?? $delivery->dr_number,
                'delivery_date'      => $request->delivery_date,
                'batch_number'       => $request->batch_number,
                'condition_status'   => $request->condition_status,
                'quantity_delivered' => $newHeaderQty,
                'remarks'            => $request->remarks,
            ]);

            // Recompute subsidy total_amount (amounts live on the line items)
            $dispatchedTotal = DeliverySubsidyItem::where('delivery_subsidy_id', $deliverySubsidy->id)
                ->get()
                ->sum(fn ($i) => $i->amount ?? ($i->unit_cost ? $i->quantity * $i->unit_cost : 0));

            $deliverySubsidy->update(['total_amount' => round($dispatchedTotal, 2)]);
            $deliverySubsidy->updateDeliveryStatus();
        });

        return redirect()
            ->route('delivery_subsidies.show', $deliverySubsidy)
            ->with('success', 'Shipment updated and stock adjusted.');
    }

    /**
     * A delivery/subsidy may span multiple warehouses (one per line item).
     * A non-admin user can access it when the header warehouse OR any of its
     * line-item warehouses OR any dispatch (delivery_items) warehouse belongs
     * to them.
     */
    protected function canAccessDeliverySubsidy(User $user, DeliverySubsidy $deliverySubsidy): bool
    {
        if ($user->hasAdminAccess()) {
            return true;
        }

        $ids = $this->getUserWarehouseIds($user) ?? [];

        if (in_array($deliverySubsidy->warehouse_id, $ids, true)) {
            return true;
        }

        return $deliverySubsidy->items()->whereIn('warehouse_id', $ids)->exists()
            || $deliverySubsidy->deliveries()->whereHas('items', fn ($q) => $q->whereIn('warehouse_id', $ids))->exists();
    }
}
