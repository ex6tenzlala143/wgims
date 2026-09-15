<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesWarehouse;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Item;
use App\Models\ItemCatalogItem;
use App\Models\DeliverySubsidy;
use App\Models\DeliverySubsidyAuditLog;
use App\Models\DeliverySubsidyItem;
use App\Models\RequisitionDispatchItem;
use App\Models\RequisitionItem;
use App\Models\ReservationItem;
use App\Models\StockCardEntry;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Supplier;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DeliverySubsidyCascadeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class DeliverySubsidyController extends Controller
{
    use ScopesWarehouse;

    public function index(Request $request)
    {
        $user = Auth::user();
        $query = DeliverySubsidy::with(['supplier', 'warehouse', 'creator', 'items.warehouse', 'deliveries.items.warehouse']);

        // Multi-warehouse deliveries: a record is visible when its header OR
        // any of its line items OR any of its dispatches is assigned to a
        // warehouse the user belongs to. The three alternatives are grouped
        // so that status/search filters below apply to ALL of them (a bare
        // orWhereHas would let later AND-filters bind to only one branch).
        $ids      = $this->getUserWarehouseIds($user);
        $filterWh = $request->warehouse_id ? (int) $request->warehouse_id : null;

        if ($ids !== null) {
            if (empty($ids)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where(function ($q) use ($ids) {
                    $q->whereIn('delivery_subsidies.warehouse_id', $ids)
                      ->orWhereHas('items', fn ($i) => $i->whereIn('warehouse_id', $ids))
                      ->orWhereHas('deliveries.items', fn ($i) => $i->whereIn('warehouse_id', $ids));
                });
            }
        } elseif ($filterWh) {
            $query->where(function ($q) use ($filterWh) {
                $q->where('delivery_subsidies.warehouse_id', $filterWh)
                  ->orWhereHas('items', fn ($i) => $i->where('warehouse_id', $filterWh))
                  ->orWhereHas('deliveries.items', fn ($i) => $i->where('warehouse_id', $filterWh));
            });
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        // Free-text search across the key transaction references (case-insensitive
        // partial match via LIKE): RIS No., DR No., Subsidy ID, supplier name,
        // line-item description, and remarks. Always kept server-side so the
        // page stays fast and paginated even with thousands of records.
        if ($search = trim((string) $request->search)) {
            $query->where(function ($q) use ($search) {
                $q->where('ris_number', 'like', "%{$search}%")
                  ->orWhere('dr_number', 'like', "%{$search}%")
                  ->orWhere('subsidy_code', 'like', "%{$search}%")
                  ->orWhere('remarks', 'like', "%{$search}%")
                  ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('items', fn ($iq) => $iq->where('description', 'like', "%{$search}%"));
            });
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

        abort_unless($user->canCreate(), 403);

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
            'items.*.quantity'       => 'required|integer|min:1',
            'items.*.expiration_date'=> 'nullable|date',
            'items.*.catalog_item_id'=> 'nullable|exists:item_catalog_items,id',
            'items.*.account_code'   => 'nullable|string|max:50',
        ]);

        $user = Auth::user();

        try {
            DB::transaction(function () use ($request, $user) {
            // quantity_requested = sum of all line item quantities.
            // Unit Cost and Warehouse are deliberately NOT captured at creation —
            // they are decided by the dispatcher when the shipment is recorded.
            $quantityRequested = collect($request->items)->sum(fn ($l) => (int) $l['quantity']);

            // Use ris_number as dr_number; append suffix only if duplicate
            $drNumber = $request->ris_number;
            $suffix = 0;
            while (DeliverySubsidy::where('dr_number', $drNumber)->exists() && $suffix < 100) {
                $suffix++;
                $drNumber = $request->ris_number . '-' . $suffix;
            }

            $subsidy = DeliverySubsidy::create([
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
                    'delivery_subsidy_id' => $subsidy->id,
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
                'message'    => "DR #{$subsidy->dr_number} has been created.",
                'type'       => 'info',
                'link'       => route('delivery_subsidies.show', $subsidy->id),
                'is_read'    => false,
                'created_at' => $now,
                'updated_at' => $now,
            ])->toArray();

            if (! empty($notifRows)) {
                \App\Models\SystemNotification::insert($notifRows);
            }
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Delivery / subsidy creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->withInput()->with('error', 'The transaction could not be completed. No changes were made. Please try again.');
        }

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
            'items.deliveryItems.warehouse',
            'items.deliveryItems.item',
            'deliveries.items.item',
            'deliveries.items.warehouse',
            'deliveries.items.deliverySubsidyItem',
            'deliveries.receiver',
        ]);

        $data = compact('deliverySubsidy');

        // The "Edit Subsidy" button opens the shared edit modal — give it the same
        // option lists the modal needs to build its item autocomplete.
        if ($user->canWrite()) {
            $data = array_merge($data, $this->createFormData($user));
        }

        return view('delivery_subsidies.show', $data);
    }

    public function edit(DeliverySubsidy $deliverySubsidy)
    {
        abort_unless(Auth::user()->canWrite(), 403);

        // Editing happens inside a modal on the index page. Send the admin back
        // there with the modal pre-opened for this record.
        return redirect()->route('delivery_subsidies.index', ['edit' => $deliverySubsidy->id]);
    }

    /**
     * JSON payload that feeds the (merged) Edit Subsidy modal — the complete
     * record exactly as saved, plus per-line flags for lines that already have
     * deliveries recorded. Lines with qty_delivered > 0 are locked: only their
     * requested quantity may change; the RIS number, supplier and DR number are
     * frozen for the whole record once any delivery exists.
     */
    public function editData(DeliverySubsidy $deliverySubsidy)
    {
        abort_unless(Auth::user()->canWrite(), 403);

        $deliverySubsidy->load(['supplier', 'items.deliveryItems']);

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
            'has_deliveries'  => $dsi->deliveryItems->count() > 0,
            'qty_delivered'   => (float) $dsi->qty_delivered,
            'locked'          => (float) $dsi->qty_delivered > 0,
            // RIS issuance / transfer movement / reservation lock on this
            // line's stock freezes its requested quantity entirely.
            'downstream_use'  => $this->deliverySubsidyItemUsage($dsi),
        ]);

        return response()->json([
            'id'                 => $deliverySubsidy->id,
            'ris_number'         => $deliverySubsidy->ris_number,
            'dr_number'          => $deliverySubsidy->dr_number,
            'supplier_id'        => $deliverySubsidy->supplier_id,
            'supplier_name'      => $deliverySubsidy->supplier->name ?? '',
            'date'               => $deliverySubsidy->date?->format('Y-m-d'),
            'place_of_delivery'  => $deliverySubsidy->place_of_delivery,
            'remarks'            => $deliverySubsidy->remarks,
            'status'             => $deliverySubsidy->status,
            'quantity_requested' => (float) $deliverySubsidy->quantity_requested,
            'has_deliveries'     => $deliverySubsidy->deliveries()->count() > 0,
            'items'              => $items->values(),
        ]);
    }

    public function update(Request $request, DeliverySubsidy $deliverySubsidy)
    {
        abort_unless(Auth::user()->canWrite(), 403);

        // ── The merged Edit Subsidy function (QUANTITY-LOCKED) ──────────────
        // DATA-INTEGRITY RULE: the requested quantity of a subsidy — header
        // `quantity_requested` and every line `delivery_subsidy_items.quantity`
        // — is PERMANENTLY LOCKED once the subsidy row is created.
        //
        // IDENTIFIER RULE: the subsidy is found by its permanent internal ID
        // (PK + `subsidy_code`, route-model binding). Related rows link via
        // `delivery_subsidy_id` / `source_subsidy_id` foreign keys — NEVER via
        // the RIS number. `ris_number` is a correctable document/reference
        // number: it MAY be edited in both modes without touching the ID, the
        // FKs, or any quantity.
        //
        //  • No deliveries yet  → ris_number, supplier, status plus date /
        //    place / remarks plus per-line DESCRIPTIVE fields (description,
        //    unit, category, catalog, account, expiry) remain editable.
        //    Quantities and the line set (no adds/removals) are frozen.
        //  • Deliveries exist    → same quantity/line-set freeze, plus supplier
        //    / DR number stay frozen. ris_number REMAINS EDITABLE (document
        //    correction). Only date / place / remarks (+ ris_number) plus
        //    per-line descriptive fields on undelivered lines may change.
        //
        // Shipments, delivered quantities (qty_delivered), stock cards,
        // inventory, warehouse assignments and DR numbers are NEVER modified
        // here. The header `quantity_requested` is NEVER recomputed from user
        // input — the stored value is always preserved — so remaining,
        // balances, lineage and reports can never drift. Changing ris_number
        // updates ONLY `delivery_subsidies.ris_number`; the subsidy ID, all
        // FKs, snapshots and quantities are left untouched.
        $hasDeliveries = $deliverySubsidy->deliveries()->count() > 0;

        // Unit Cost and Warehouse are deliberately NOT editable here — they are
        // only assigned by the dispatcher when a delivery is recorded.
        // Quantity is accepted in the payload for backward compatibility with
        // existing forms, but ANY deviation from the stored value is rejected
        // below (backend quantity lock — never trust the client).
        $rules = [
            'date'                    => 'required|date',
            'place_of_delivery'       => 'nullable|string|max:255',
            'remarks'                 => 'nullable|string|max:1000',
            'items'                   => 'required|array|min:1',
            'items.*.item_id'         => 'nullable|exists:items,id',
            'items.*.catalog_item_id' => 'nullable|exists:item_catalog_items,id',
            'items.*.account_code'    => 'nullable|string|max:50',
            'items.*.description'     => 'required|string|max:255',
            'items.*.unit'            => 'required|string',
            'items.*.category'        => 'required|string|in:'.implode(',', array_keys(Item::getCategories())),
            'items.*.quantity'        => 'required|integer|min:1',
            'items.*.expiration_date' => 'nullable|date',
            // Line identity is always required: lines can never be added or
            // removed via edit (that would change the locked total).
            'items.*.dsi_id'          => 'required|integer|exists:delivery_subsidy_items,id',
        ];

        if ($hasDeliveries) {
            $rules += [
                'ris_number'      => 'required|string|max:255',
                'supplier_id'     => 'required|exists:suppliers,id',
            ];
        } else {
            $rules += [
                'ris_number'  => 'required|string|max:255',
                'supplier_id' => 'required|exists:suppliers,id',
                'status'      => 'sometimes|string|in:pending,cancelled',
            ];
        }

        $request->validate($rules);

        if ($hasDeliveries) {
            // Supplier is frozen once deliveries exist — reject any attempt to
            // change it, even from a tampered request. ris_number is
            // deliberately NOT frozen: it is a correctable document number and
            // is never used as a relationship key (FKs use the subsidy ID).
            if ((int) $request->supplier_id !== (int) $deliverySubsidy->supplier_id) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'The supplier cannot be changed once deliveries have been recorded.',
                ]);
            }
        }

        $cascadeSvc    = new DeliverySubsidyCascadeService();
        $existingItems = $deliverySubsidy->items()->get()->keyBy('id');
        $oldStatus     = $deliverySubsidy->status;
        $oldRequested  = (float) $deliverySubsidy->quantity_requested;
        $changes       = [];

        try {
            DB::transaction(function () use ($request, $deliverySubsidy, $existingItems, $cascadeSvc, $hasDeliveries, &$changes, &$oldStatus, &$oldRequested) {
            $headerData = [];

            // ── UNIVERSAL QUANTITY LOCK (both modes) ─────────────────────
            // The line set must match exactly (no adds, no removals — either
            // would change the locked total), and every submitted quantity
            // must equal the stored quantity. This covers: plain edits,
            // dispatched lines, augmentation (transfer) lines, RIS-issued
            // lines, reserved lines, and any crafted API request.
            $existingIds  = $existingItems->keys()->map(fn ($k) => (int) $k)->sort()->values()->all();
            $submittedIds = collect($request->items)->map(fn ($l) => (int) ($l['dsi_id'] ?? 0))->sort()->values()->all();
            if ($submittedIds !== $existingIds) {
                throw ValidationException::withMessages([
                    'items' => 'Subsidy line items cannot be added or removed. The locked quantity must be preserved.',
                ]);
            }
            foreach ($request->items as $idx => $line) {
                if (! $existingItems->has((int) $line['dsi_id'])) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.dsi_id" => 'Invalid line item for this subsidy.',
                    ]);
                }
                $dsi    = $existingItems->get((int) $line['dsi_id']);
                $oldQty = (float) $dsi->quantity;
                $newQty = round((float) ($line['quantity'] ?? $oldQty), 4);
                if (abs($newQty - $oldQty) > 0.0001) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.quantity" =>
                            'Quantity is locked and cannot be changed after the subsidy is created. '
                            .'Original: '.number_format($oldQty).'. No changes were made to inventory, dispatches, augmentations, or reports.',
                    ]);
                }
            }
            // Header total is always preserved — never taken from user input
            // and never recomputed from it — so crafted `quantity_requested`
            // values are ignored by design.

            if ($hasDeliveries) {
                // ── Header: date / place / remarks + ris_number may change ──
                // ris_number is a correctable document number (FKs use the
                // subsidy ID, so links are unaffected). supplier_id /
                // dr_number / quantity_requested stay frozen. Only the
                // `ris_number` column itself is written — related rows, stock
                // and quantities are never touched.
                foreach (['ris_number', 'date', 'place_of_delivery', 'remarks'] as $field) {
                    $oldVal = $deliverySubsidy->{$field} instanceof \DateTimeInterface
                        ? $deliverySubsidy->{$field}->format('Y-m-d')
                        : $deliverySubsidy->{$field};
                    $newVal = $request->{$field};
                    $headerData[$field] = $newVal;
                    if ((string) $oldVal !== (string) $newVal) {
                        $changes[$field] = ['old' => $oldVal, 'new' => $newVal];
                    }
                }

                // ── Lines: descriptive fields only (quantity NEVER written) ──
                foreach ($request->items as $idx => $line) {
                    $dsi    = $existingItems->get((int) $line['dsi_id']);
                    $locked = (float) $dsi->qty_delivered > 0;

                    // A delivered line is locked to its item identity as well:
                    // re-pointing it would orphan dispatched stock history.
                    if ($locked) {
                        $submittedCat  = $line['catalog_item_id'] ?? null;
                        $submittedItem = $line['item_id'] ?? null;
                        if ($submittedCat && (int) $submittedCat !== (int) $dsi->catalog_item_id) {
                            throw ValidationException::withMessages([
                                "items.{$idx}.catalog_item_id" => 'This item has already been delivered and cannot be changed.',
                            ]);
                        }
                        if ($submittedItem && (int) $submittedItem !== (int) $dsi->item_id) {
                            throw ValidationException::withMessages([
                                "items.{$idx}.item_id" => 'This item has already been delivered and cannot be changed.',
                            ]);
                        }
                    }

                    // NOTE: `quantity` is deliberately excluded — the stored
                    // value is preserved. `qty_delivered` is never touched here
                    // (dispatched quantities live on shipments/stock cards).
                    $data    = [];
                    $oldDesc = $dsi->description;

                    if (! $locked) {
                        $data += [
                            'item_id'         => $line['item_id'] ?? null,
                            'catalog_item_id' => $line['catalog_item_id'] ?? null,
                            'account_code'    => $line['account_code'] ?? null,
                            'description'     => $line['description'],
                            'unit'            => $line['unit'],
                            'category'        => $line['category'],
                            'expiration_date' => $line['expiration_date'] ?? null,
                        ];
                    }

                    if (! empty($data)) {
                        $dsi->update($data);
                    }

                    if (! $locked && $dsi->description !== $oldDesc) {
                        $changes["items.{$dsi->id}.item"] = ['old' => $oldDesc, 'new' => $dsi->description];
                    }
                }

                // Preserve the locked header total; refresh status only (which
                // is a pure function of locked-requested vs. delivered, so it
                // cannot drift unless shipments changed elsewhere).
                $deliverySubsidy->update($headerData);

                $deliverySubsidy->refresh();
                $deliverySubsidy->updateDeliveryStatus();

                if ($deliverySubsidy->status !== $oldStatus) {
                    $changes['status'] = ['old' => $oldStatus, 'new' => $deliverySubsidy->status];
                }

                $cascadeSvc->recordAudit($deliverySubsidy->id, $changes, [], 'correction');
            } else {
                // ── No deliveries: header identity + descriptive fields only ─
                // Quantity and line set stay locked even before any dispatch —
                // the subsidy is historical from the moment it is created.
                $headerFields = [
                    'ris_number'        => $request->ris_number,
                    'supplier_id'       => (int) $request->supplier_id,
                    'date'              => $request->date,
                    'place_of_delivery' => $request->place_of_delivery,
                    'status'            => $request->status ?? $deliverySubsidy->status,
                    'remarks'           => $request->remarks,
                ];

                foreach ($headerFields as $field => $newVal) {
                    $oldVal = $deliverySubsidy->{$field} instanceof \DateTimeInterface
                        ? $deliverySubsidy->{$field}->format('Y-m-d')
                        : $deliverySubsidy->{$field};
                    if ((string) $oldVal !== (string) $newVal) {
                        $changes[$field] = ['old' => $oldVal, 'new' => $newVal];
                    }
                }

                // quantity_requested is NEVER updated — the original total is
                // preserved even when descriptive fields are corrected.
                $deliverySubsidy->update($headerFields);

                // Update descriptive fields in place (stable IDs for audit).
                // No creation, no deletion — the locked line set is preserved.
                foreach ($request->items as $line) {
                    $dsi     = $existingItems->get((int) $line['dsi_id']);
                    $oldDesc = $dsi->description;
                    $dsi->update([
                        'item_id'         => $line['item_id'] ?? null,
                        'catalog_item_id' => $line['catalog_item_id'] ?? null,
                        'account_code'    => $line['account_code'] ?? null,
                        'description'     => $line['description'],
                        'unit'            => $line['unit'],
                        'category'        => $line['category'],
                        'expiration_date' => $line['expiration_date'] ?? null,
                    ]);

                    if ($dsi->description !== $oldDesc) {
                        $changes["items.{$dsi->id}.item"] = ['old' => $oldDesc, 'new' => $dsi->description];
                    }
                }

                $cascadeSvc->recordAudit($deliverySubsidy->id, $changes, []);
            }
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Delivery / subsidy update failed', [
                'delivery_subsidy_id' => $deliverySubsidy->id,
                'error'               => $e->getMessage(),
                'trace'               => $e->getTraceAsString(),
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'errors' => ['general' => ['The transaction could not be completed. No changes were made. Please try again.']],
                ], 500);
            }

            return back()->withInput()->with('error', 'The transaction could not be completed. No changes were made. Please try again.');
        }

        $success = $hasDeliveries
            ? 'Delivery / Subsidy corrected. Delivered stock and inventory records were not changed.'
            : 'Delivery / Subsidy updated.';

        if ($request->expectsJson()) {
            return response()->json(['redirect' => route('delivery_subsidies.show', $deliverySubsidy), 'success' => $success]);
        }

        return redirect()->route('delivery_subsidies.show', $deliverySubsidy)->with('success', $success);
    }

    public function destroy(DeliverySubsidy $deliverySubsidy)
    {
        abort_unless(Auth::user()->canWrite(), 403);

        // Hard rule: a subsidy with downstream transactions can never be
        // deleted. RIS augmentation (issuance), executed stock transfers, and
        // active reservation locks all block deletion — reversing the receipt
        // underneath those movements would corrupt them (same rule as single
        // shipment deletion). Planned-but-undispatched transfers (moved
        // quantity = 0) do NOT block; they are flagged as before.
        $blockers = $this->subsidyDeletionBlockers($deliverySubsidy);
        if (! empty($blockers)) {
            return back()->with('error', 'This subsidy cannot be deleted because its stock has downstream transactions: ' . implode('; ', array_unique($blockers)) . '. Reverse or resolve those transactions first, then delete this subsidy.');
        }

        $markedTransferCount = 0;
        $lineageIds = [];
        $descendantIds = [];

        try {
            DB::transaction(function () use ($deliverySubsidy, &$markedTransferCount, &$lineageIds, &$descendantIds) {
            // ── Flag every related Stock Transfer BEFORE the subsidy disappears ──
            // The transfer keeps its inventory movement and is only *marked*, never
            // auto-deleted: the administrator reviews it and decides afterwards.
            // The snapshot columns (source_ris_number / source_dr_number) survive
            // the hard delete because the FK is nullOnDelete.
            $markedTransferCount = $this->markTransfersWithSubsidyState($deliverySubsidy, 'deleted');

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

                            // Snapshot the subsidy before the row is gone so the item
                            // carries a permanent "FROM DELETED SUBSIDY" trail.
                            $di->item->applySubsidySnapshot(
                                $deliverySubsidy->id,
                                $deliverySubsidy->ris_number,
                                $deliverySubsidy->dr_number,
                                'deleted',
                                $deliverySubsidy->subsidy_code
                            );

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

                // Flag every destination item that received this subsidy's stock
                // through a Stock Transfer (multi-hop included), so the "FROM
                // DELETED SUBSIDY" marker appears on the stock that moved to
                // other warehouses too. The transfer's own movement is never
                // reversed here — the preserved, flagged transfer is what the
                // administrator reviews.
                $rootIds = array_keys($affectedItemIds);
                $lineageIds = $this->transferLineageItemIds($rootIds);
                $descendantIds = array_values(array_diff($lineageIds, $rootIds));

                foreach ($descendantIds as $descendantId) {
                    $item = Item::find($descendantId);
                    if ($item) {
                        $item->applySubsidySnapshot(
                            $deliverySubsidy->id,
                            $deliverySubsidy->ris_number,
                            $deliverySubsidy->dr_number,
                            'deleted',
                            $deliverySubsidy->subsidy_code
                        );
                    }
                }

            $deliverySubsidy->items()->delete();
            $deliverySubsidy->delete();

            // Hard-delete the inventory records that existed ONLY because of
            // this subsidy. An affected item that has been fully reversed
            // (quantity back to 0) and is no longer referenced by any other
            // transaction is removed entirely, so it stops cluttering the
            // Items page and the inventory reports. Items with any remaining
            // stock or any other reference — other deliveries, subsidies,
            // requisitions, stock cards, transfers or purchase orders — are
            // always preserved.
            foreach (array_keys($affectedItemIds) as $itemId) {
                $item = Item::find($itemId);
                if (! $item || (float) $item->quantity > 0) {
                    continue;
                }

                $stillReferenced = DeliveryItem::where('item_id', $itemId)->exists()
                    || DeliverySubsidyItem::where('item_id', $itemId)->exists()
                    || RequisitionItem::where('item_id', $itemId)->exists()
                    || RequisitionDispatchItem::where('item_id', $itemId)->exists()
                    || StockCardEntry::where('item_id', $itemId)->exists()
                    || StockTransferItem::where('item_id', $itemId)->orWhere('destination_item_id', $itemId)->exists()
                    || (Schema::hasTable('purchase_orders') && DB::table('purchase_orders')->where('item_id', $itemId)->exists());

                if (! $stillReferenced) {
                    $item->delete();
                }
            }

            // Rebuild running stock-card balances so the entries that remain
            // (from other transactions) keep correct cumulative figures.
            foreach (array_keys($affectedItemIds) as $itemId) {
                if (Item::whereKey($itemId)->exists()) {
                    StockCardEntry::recalculateBalancesForItem($itemId);
                }
            }
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Delivery / subsidy deletion failed', [
                'delivery_subsidy_id' => $deliverySubsidy->id,
                'error'               => $e->getMessage(),
                'trace'               => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'The transaction could not be completed. No changes were made. Please try again.');
        }

        $success = "DR #{$deliverySubsidy->dr_number} deleted and stock reversed. "
            . ($markedTransferCount > 0
                ? "{$markedTransferCount} related stock transfer(s) were preserved and flagged as \"Related to Deleted Subsidy\" for review."
                : '');

        return redirect()->route('delivery_subsidies.index')
            ->with('success', $success);
    }

    /**
     * Downstream transactions that forbid deleting the given Subsidy:
     * RIS issuance from any lineage item (root deliveries + multi-hop
     * transfer descendants), executed transfers moving lineage stock
     * (source or destination side), and active reservation locks.
     * Returns human-readable blocker notes (empty = safe to delete).
     */
    /**
     * Downstream usage of ONE subsidy line's delivered stock: RIS issuance,
     * onward stock transfers (augmentation), and active reservation locks —
     * traced through the transfer lineage so moved stock still counts.
     * Returns human-readable usage notes (empty = freely editable).
     * A non-empty result means the line's requested quantity is fully frozen.
     */
    private function deliverySubsidyItemUsage(DeliverySubsidyItem $dsi): array
    {
        $rootIds = $dsi->deliveryItems()->whereNotNull('item_id')->distinct()->pluck('item_id')->all();

        if (empty($rootIds)) {
            return [];
        }

        $lineageIds = $this->transferLineageItemIds($rootIds);
        $usage = [];

        $risNos = RequisitionDispatchItem::whereIn('item_id', $lineageIds)
            ->with('requisitionItem.requisition')
            ->get()
            ->map(fn ($di) => $di->requisitionItem?->requisition?->ris_number)
            ->filter()->unique()->values()->all();
        foreach ($risNos as $n) {
            $usage[] = "issued through RIS {$n}";
        }

        $trfNos = StockTransferItem::where(function ($q) use ($lineageIds) {
                $q->whereIn('item_id', $lineageIds)
                  ->orWhereIn('destination_item_id', $lineageIds);
            })
            ->where('quantity', '>', 0)
            ->with('transfer')
            ->get()
            ->map(fn ($sti) => $sti->transfer?->transfer_number)
            ->filter()->unique()->values()->all();
        foreach ($trfNos as $t) {
            $usage[] = "moved by transfer {$t}";
        }

        foreach ($lineageIds as $itemId) {
            if (ReservationItem::reservedQuantityForItem((int) $itemId) > 0.0001) {
                $item = Item::find($itemId);
                $usage[] = 'locked by an active reservation (' . ($item->stock_number ?? $item?->description ?? 'stock') . ')';
                break;
            }
        }

        return $usage;
    }

    private function subsidyDeletionBlockers(DeliverySubsidy $deliverySubsidy): array
    {
        $rootIds = DeliveryItem::whereHas('delivery', fn ($q) => $q->where('delivery_subsidy_id', $deliverySubsidy->id))
            ->whereNotNull('item_id')
            ->distinct()
            ->pluck('item_id')
            ->all();

        if (empty($rootIds)) {
            return [];
        }

        $lineageIds = $this->transferLineageItemIds($rootIds);
        $blockers = [];

        $risNos = RequisitionDispatchItem::whereIn('item_id', $lineageIds)
            ->with('requisitionItem.requisition')
            ->get()
            ->map(fn ($di) => $di->requisitionItem?->requisition?->ris_number)
            ->filter()->unique()->values()->all();
        foreach ($risNos as $n) {
            $blockers[] = "issued through RIS {$n}";
        }

        $trfNos = StockTransferItem::where(function ($q) use ($lineageIds) {
                $q->whereIn('item_id', $lineageIds)
                  ->orWhereIn('destination_item_id', $lineageIds);
            })
            ->where('quantity', '>', 0)
            ->with('transfer')
            ->get()
            ->map(fn ($sti) => $sti->transfer?->transfer_number)
            ->filter()->unique()->values()->all();
        foreach ($trfNos as $t) {
            $blockers[] = "moved by transfer {$t}";
        }

        foreach ($lineageIds as $itemId) {
            if (ReservationItem::reservedQuantityForItem((int) $itemId) > 0.0001) {
                $item = Item::find($itemId);
                $blockers[] = 'locked by an active reservation (' . ($item->stock_number ?? $item?->description ?? 'stock') . ')';
                break;
            }
        }

        return $blockers;
    }

    /**
     * All Stock Transfers that trace back to this Subsidy: linked directly by
     * FK, or matched by the RIS/DR snapshot on transfers whose subsidy row is
     * already gone.
     */
    private function relatedStockTransfers(DeliverySubsidy $deliverySubsidy): \Illuminate\Database\Eloquent\Collection
    {
        $risNumber = (string) $deliverySubsidy->ris_number;
        $drNumber  = (string) $deliverySubsidy->dr_number;

        return StockTransfer::query()
            ->where(function ($q) use ($deliverySubsidy, $risNumber, $drNumber) {
                $q->where('delivery_subsidy_id', $deliverySubsidy->id);

                if ($risNumber !== '') {
                    $q->orWhere(function ($q2) use ($risNumber) {
                        $q2->whereNull('delivery_subsidy_id')
                           ->where('source_ris_number', $risNumber);
                    });
                }

                if ($drNumber !== '') {
                    $q->orWhere(function ($q2) use ($drNumber) {
                        $q2->whereNull('delivery_subsidy_id')
                           ->where('source_dr_number', $drNumber);
                    });
                }
            })
            ->get();
    }

    /**
     * Flag every Stock Transfer related to the given Subsidy with a new source
     * state ('deleted') and snapshot the RIS/DR references.
     *
     * Returns the number of transfers flagged.
     */
    private function markTransfersWithSubsidyState(DeliverySubsidy $deliverySubsidy, string $state): int
    {
        $transfers = $this->relatedStockTransfers($deliverySubsidy);

        foreach ($transfers as $transfer) {
            $transfer->forceFill([
                'source_subsidy_status' => $state,
                'source_ris_number'     => $transfer->source_ris_number ?: $deliverySubsidy->ris_number,
                'source_dr_number'      => $transfer->source_dr_number ?: $deliverySubsidy->dr_number,
            ])->save();
        }

        return $transfers->count();
    }

    /**
     * All inventory Items delivered by this Subsidy (via its delivery lines).
     */
    private function relatedItems(DeliverySubsidy $deliverySubsidy): \Illuminate\Database\Eloquent\Collection
    {
        return Item::whereHas('deliverySubsidyItems', function ($q) use ($deliverySubsidy) {
            $q->where('delivery_subsidy_id', $deliverySubsidy->id);
        })->get();
    }

    /**
     * Sync the source-subsidy snapshot on every Item delivered by the given
     * Subsidy AND on every Item that received that stock through a Stock
     * Transfer (including multi-hop chains), so the marker follows the stock
     * across warehouses.
     */
    private function markItemsWithSubsidyState(DeliverySubsidy $deliverySubsidy, string $state): void
    {
        $lineageIds = $this->transferLineageItemIds(
            $this->relatedItems($deliverySubsidy)->pluck('id')->all()
        );

        foreach (Item::whereIn('id', $lineageIds)->get() as $item) {
            // For deleted state, apply the status
            $item->applySubsidySnapshot(
                $deliverySubsidy->id,
                $deliverySubsidy->ris_number,
                $deliverySubsidy->dr_number,
                $state,
                $deliverySubsidy->subsidy_code
            );
        }
    }

    /**
     * Every Item id reachable from the given root ids through Stock Transfers,
     * including multi-hop chains (a destination item may itself be transferred
     * onward). BFS with a cycle guard. The source is identified by following
     * the transfer chain — never by warehouse — so stock that moved to another
     * warehouse is still traced back to the deleted Subsidy.
     */
    private function transferLineageItemIds(array $rootIds): array
    {
        $lineage = [];
        $visited = [];
        $pending = array_values($rootIds);

        while ($pending) {
            $current = array_shift($pending);

            if (in_array($current, $visited, true)) {
                continue;
            }

            $visited[] = $current;
            $lineage[] = $current;

            $children = StockTransferItem::where('item_id', $current)
                ->whereNotNull('destination_item_id')
                ->pluck('destination_item_id')
                ->all();

            foreach ($children as $childId) {
                if (! in_array($childId, $visited, true)) {
                    $pending[] = $childId;
                }
            }
        }

        return $lineage;
    }

    /**
     * Downstream transactions on ONE delivered stock line: RIS issuance,
     * executed stock-transfer movement (either side), and active reservation
     * locks on the exact stock record. Same semantics as the shipment-delete
     * guard. A non-empty result means the line's delivered quantity is frozen
     * (metadata like costs, DR, expiry and condition may still be corrected).
     */
    private function deliveryItemTransactions(DeliveryItem $di): array
    {
        if (empty($di->item_id)) {
            return [];
        }

        $use = [];

        $risNo = RequisitionDispatchItem::where('item_id', $di->item_id)
            ->with('requisitionItem.requisition')
            ->get()
            ->map(fn ($d) => $d->requisitionItem?->requisition?->ris_number)
            ->filter()->unique()->values()->first();
        if ($risNo) {
            $use[] = "issued through RIS {$risNo}";
        } elseif (RequisitionDispatchItem::where('item_id', $di->item_id)->exists()) {
            $use[] = 'issued through a RIS';
        }

        $moved = StockTransferItem::where(function ($q) use ($di) {
                $q->where('item_id', $di->item_id)
                  ->orWhere('destination_item_id', $di->item_id);
            })
            ->where('quantity', '>', 0)
            ->exists();
        if ($moved) {
            $use[] = 'moved by a stock transfer';
        }

        if (ReservationItem::reservedQuantityForItem((int) $di->item_id) > 0.0001) {
            $use[] = 'locked by an active reservation';
        }

        return $use;
    }

    public function delivery(DeliverySubsidy $deliverySubsidy)
    {
        $user = Auth::user();
        if (! $this->canAccessDeliverySubsidy($user, $deliverySubsidy)) {
            abort(403);
        }
        $deliverySubsidy->load(['items.item', 'items.warehouse', 'items.deliveryItems', 'supplier', 'warehouse']);

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

        // Normalize blank cost inputs to null BEFORE validation: HTML forms
        // submit '' for empty fields, and '' must mean "no cost given" (not a
        // numeric zero and not a validation trip). An explicitly entered 0
        // stays 0. Required-ness for dispatching lines is enforced below.
        $request->merge(['items' => collect($request->input('items', []))->map(function ($line) {
            if (is_array($line)) {
                foreach (['unit_cost', 'engas_unit_cost'] as $costField) {
                    if (array_key_exists($costField, $line) && $line[$costField] === '') {
                        $line[$costField] = null;
                    }
                }
            }
            return $line;
        })->toArray()]);

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
            'condition_status'   => 'nullable|string|in:good,damaged',
            'quantity_delivered' => 'required|integer|min:1',
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
            $rules["items.{$key}.quantity_delivered"] = ['integer', 'min:0'];
            $rules["items.{$key}.unit_cost"]          = ['nullable', 'numeric', 'min:0'];
            $rules["items.{$key}.engas_unit_cost"]    = ['nullable', 'numeric', 'min:0'];
            $rules["items.{$key}.warehouse_id"]       = ['exists:warehouses,id'];
            $rules["items.{$key}.expiration_date"]    = ['nullable', 'date'];
            $rules["items.{$key}.dr_number"]          = ['string', 'max:100'];
            // Per-item condition: validated when present; dispatching lines
            // without one fall back to the header input (legacy payloads).
            $rules["items.{$key}.condition"]          = ['nullable', 'string', 'in:good,damaged'];

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

        try {
            DB::transaction(function () use ($request, $deliverySubsidy, $user) {
            // ── Delivery header: one row per shipment ──────────────────────
            // DR# is now tracked per dispatched item; the header keeps an
            // optional reference for legacy records only.
            // Condition is tracked per dispatched item; the header keeps the
            // most common value as a summary (legacy submissions without
            // per-item values fall back to the header input).
            $headerFallback = in_array($request->condition_status, ['good', 'damaged'], true)
                ? $request->condition_status
                : 'good';
            $dispatchedConditions = [];
            foreach ($request->items as $line) {
                if ((int) ($line['quantity_delivered'] ?? 0) > 0) {
                    $c = strtolower(trim((string) ($line['condition'] ?? $line['condition_status'] ?? $headerFallback)));
                    $dispatchedConditions[] = in_array($c, ['good', 'damaged'], true) ? $c : $headerFallback;
                }
            }
            $conditionCounts = array_count_values($dispatchedConditions);
            arsort($conditionCounts);
            $headerCondition = ! empty($conditionCounts) ? array_key_first($conditionCounts) : $headerFallback;
            $delivery = Delivery::create([
                'delivery_subsidy_id'  => $deliverySubsidy->id,
                'dr_number'          => $request->dr_number ?? null,
                'received_by'        => $user->id,
                'delivery_date'      => $request->delivery_date,
                'batch_number'       => $request->batch_number,
                'condition_status'   => $headerCondition,
                'quantity_delivered' => $request->quantity_delivered,
                'remarks'            => $request->remarks,
            ]);

            // ── Line items: per-item stock detail ──────────────────────────
            // Rows without a positive quantity (e.g. fully delivered items that
            // were ignored by validation) are skipped entirely.
            foreach ($request->items as $line) {
                $qtyDelivered = (int) ($line['quantity_delivered'] ?? 0);
                if ($qtyDelivered <= 0) {
                    continue;
                }

                $dsItem = DeliverySubsidyItem::where('id', $line['ds_item_id'])
                    ->where('delivery_subsidy_id', $deliverySubsidy->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Re-check the remaining quantity against the LOCKED row: two
                // concurrent duplicate submissions are serialized here, and the
                // second one sees the reduced remaining instead of double-recording.
                $freshRemaining = max(0, (float) $dsItem->quantity - (float) $dsItem->qty_delivered);
                if ($qtyDelivered > $freshRemaining + 0.0001) {
                    throw ValidationException::withMessages([
                        "items.{$line['ds_item_id']}.quantity_delivered" =>
                            "Quantity exceeds the remaining quantity of {$freshRemaining} for this item.",
                    ]);
                }

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

                // Total ENGAS cost is always computed server-side — the client
                // cannot override it (qty × engas unit cost).
                $engasTotalCost = $engasUnitCost !== null ? round($qtyDelivered * $engasUnitCost, 2) : null;

                // Condition is chosen per dispatched item in the form; legacy
                // submissions without a per-item value fall back to the header.
                $lineCondition = strtolower(trim((string) ($line['condition'] ?? $line['condition_status'] ?? $headerCondition)));
                if (! in_array($lineCondition, ['good', 'damaged'], true)) {
                    $lineCondition = $headerCondition;
                }

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
                    $dsItem->account_code ?: ($baseItem->account_code ?? null),
                    $deliverySubsidy->id
                );

                // Lock the exact stock row before the quantity read-modify-write.
                $item = Item::whereKey($item->id)->lockForUpdate()->first() ?? $item;

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
                    'condition'              => $lineCondition,
                    'dr_number'              => $drNumber,
                ]);

                $dsItem->increment('qty_delivered', $qtyDelivered);

                $newQty = $item->quantity + $qtyDelivered;
                $item->update([
                    'quantity'   => $newQty,
                    'unit_cost'  => $actualUnitCost,
                    'ris_number' => $deliverySubsidy->ris_number,
                ]);

                $item->applySubsidySnapshot(
                    $deliverySubsidy->id,
                    $deliverySubsidy->ris_number,
                    $deliverySubsidy->dr_number,
                    'active',
                    $deliverySubsidy->subsidy_code
                );

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
                ->sum(fn ($i) => $i->amount ?? ($i->unit_cost !== null ? $i->quantity * $i->unit_cost : 0));

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
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Delivery recording failed', [
                'delivery_subsidy_id' => $deliverySubsidy->id,
                'error'               => $e->getMessage(),
                'trace'               => $e->getTraceAsString(),
            ]);

            return back()->withInput()->with('error', 'The transaction could not be completed. No changes were made. Please try again.');
        }

        return redirect()->route('delivery_subsidies.show', $deliverySubsidy)
            ->with('success', 'Shipment recorded and stock updated.');
    }

    /**
     * Admin: show the edit form for a single delivery record.
     */
    public function editDelivery(Request $request, DeliverySubsidy $deliverySubsidy, Delivery $delivery)
    {
        abort_unless(Auth::user()->canWrite(), 403);
        abort_unless($delivery->delivery_subsidy_id === $deliverySubsidy->id, 404);

        $delivery->load(['items.item', 'items.deliverySubsidyItem']);
        $deliverySubsidy->load(['supplier', 'warehouse']);

        $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get();

        // Optional single-row focus (?di=delivery_item_id) from the Partial
        // Delivery Breakdown: show only the selected DR row for editing.
        // Unsubmitted lines are never touched by updateDelivery (it processes
        // submitted lines only and recomputes the header from the database),
        // so scoping the form is safe. Unknown IDs fall back to all rows.
        $allItemsCount = $delivery->items->count();
        $focusedDiId   = (int) $request->query('di', 0);
        if ($focusedDiId > 0) {
            $focused = $delivery->items->firstWhere('id', $focusedDiId);
            if ($focused) {
                $delivery->setRelation('items', collect([$focused]));
            } else {
                $focusedDiId = 0;
            }
        }

        // Per-line downstream transactions for the form: lines whose stock
        // was already issued, transferred or reserved render read-only.
        $transactions = $delivery->items->mapWithKeys(
            fn ($di) => [$di->id => $this->deliveryItemTransactions($di)]
        );

        return view('delivery_subsidies.edit_delivery', compact('deliverySubsidy', 'delivery', 'warehouses', 'focusedDiId', 'allItemsCount', 'transactions'));
    }

    /**
     * Admin JSON: shipment data for the Edit Shipment popup modal on the
     * subsidy details page. Mirrors the standalone edit page payload.
     *
     * GET /delivery-subsidies/{ds}/deliveries/{delivery}/edit-data
     */
    public function deliveryEditData(DeliverySubsidy $deliverySubsidy, Delivery $delivery)
    {
        abort_unless(Auth::user()->canWrite(), 403);
        abort_unless($delivery->delivery_subsidy_id === $deliverySubsidy->id, 404);

        $delivery->load(['items.item', 'items.deliverySubsidyItem', 'items.warehouse']);
        $deliverySubsidy->load(['supplier']);

        $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        return response()->json([
            'id'               => $delivery->id,
            'delivery_date'    => $delivery->delivery_date?->toDateString(),
            'batch_number'     => $delivery->batch_number,
            'remarks'          => $delivery->remarks,
            'quantity_delivered' => $delivery->quantity_delivered,
            'ris_number'       => $deliverySubsidy->ris_number,
            'supplier_name'    => $deliverySubsidy->supplier->name ?? null,
            'warehouses'       => $warehouses,
            'items'            => $delivery->items->map(function ($di) {
                $dsLine  = $di->deliverySubsidyItem;
                $lineMax = $dsLine ? max(0, (float) $dsLine->quantity - (float) $dsLine->qty_delivered) : 0;
                $cond    = strtolower((string) ($di->condition ?? 'good'));
                return [
                    'di_id'              => $di->id,
                    'description'        => $di->item->description ?? $dsLine->description ?? '—',
                    'unit'               => $di->item->unit ?? null,
                    'stock_number'       => $di->item->stock_number ?? null,
                    'current_stock'      => $di->item->quantity ?? 0,
                    'warehouse_id'       => $di->warehouse_id ?? $dsLine->warehouse_id ?? $di->item->warehouse_id ?? null,
                    'quantity_delivered' => $di->quantity_delivered,
                    'max_qty'            => (float) $di->quantity_delivered + $lineMax,
                    'remaining'          => $lineMax,
                    'unit_cost'          => $di->unit_cost,
                    'engas_unit_cost'    => $di->engas_unit_cost,
                    'dr_number'          => $di->dr_number ?? null,
                    'expiration_date'    => $di->item?->expiration_date?->toDateString(),
                    'condition'          => in_array($cond, ['good', 'damaged'], true) ? $cond : 'good',
                    // Non-empty when this line's stock has downstream
                    // transactions — its quantity is frozen in the modal.
                    'transactions'       => $this->deliveryItemTransactions($di),
                ];
            })->values(),
        ]);
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

        // Same blank-to-null normalization as storeDelivery: '' means "no
        // cost given", while an explicit 0 stays 0.
        $request->merge(['items' => collect($request->input('items', []))->map(function ($line) {
            if (is_array($line)) {
                foreach (['unit_cost', 'engas_unit_cost'] as $costField) {
                    if (array_key_exists($costField, $line) && $line[$costField] === '') {
                        $line[$costField] = null;
                    }
                }
            }
            return $line;
        })->toArray()]);

        // Dispatch fields are strictly required only for lines that still carry
        // a positive quantity; a line set to 0 fully reverses and needs none.
        $rules = [
            'delivery_date'      => 'required|date',
            'dr_number'          => 'nullable|string|max:100',
            'batch_number'       => 'nullable|string|max:100',
            'condition_status'   => 'nullable|string|in:good,damaged',
            'remarks'            => 'nullable|string|max:1000',
            'items'              => 'required|array|min:1',
            'items.*.di_id'              => 'required|exists:delivery_items,id',
            'items.*.quantity_delivered' => 'required|integer|min:0',
            'items.*.unit_cost'          => 'nullable|numeric|min:0',
            'items.*.engas_unit_cost'    => 'nullable|numeric|min:0',
            'items.*.warehouse_id'       => 'exists:warehouses,id',
            'items.*.expiration_date'    => 'nullable|date',
            'items.*.dr_number'          => 'string|max:100',
            // Per-item condition: validated when present; lines without one
            // fall back to the header input (legacy payloads).
            'items.*.condition'          => 'nullable|string|in:good,damaged',
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
            if ($request->expectsJson()) {
                return response()->json(['errors' => $editErrors], 422);
            }
            return back()->withInput()->withErrors($editErrors);
        }

        $cascadeSvc     = new DeliverySubsidyCascadeService();
        $cascadeSummary = [];

        try {
            DB::transaction(function () use ($request, $deliverySubsidy, $delivery, $cascadeSvc, &$cascadeSummary) {
            $affectedItemIds = [];

            // Snapshot header values before any edits so the audit trail shows
            // exactly what changed on this shipment.
            $oldHeader = [
                'delivery_date'      => $delivery->delivery_date?->toDateString(),
                'dr_number'          => $delivery->dr_number,
                'batch_number'       => $delivery->batch_number,
                'condition_status'   => $delivery->condition_status,
                'remarks'            => $delivery->remarks,
                'quantity_delivered' => $delivery->quantity_delivered,
            ];
            $changedFields = [];
            $finalConditions = [];

            foreach ($request->items as $idx => $line) {
                /** @var DeliveryItem $di */
                $di = DeliveryItem::with(['deliverySubsidyItem'])
                    ->whereKey($line['di_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

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
                // Condition is chosen per dispatched item in the form; legacy
                // submissions without a per-item value fall back to the header
                // input, then to the stored line value.
                $newCondition = strtolower(trim((string) ($line['condition'] ?? $line['condition_status'] ?? $request->condition_status ?? $di->condition ?? 'good')));
                if (! in_array($newCondition, ['good', 'damaged'], true)) {
                    $newCondition = in_array($request->condition_status, ['good', 'damaged'], true)
                        ? $request->condition_status
                        : ($di->condition ?? 'good');
                }
                if ($newQty > 0) {
                    $finalConditions[] = $newCondition;
                }
                $delta     = $newQty - $oldQty;
                $oldCost   = (float) $di->unit_cost;

                $dsItem = DeliverySubsidyItem::whereKey($di->delivery_subsidy_item_id)->lockForUpdate()->first();
                $oldItem = Item::whereKey($di->item_id)->lockForUpdate()->first();

                if (! $dsItem) {
                    continue;
                }

                // Transaction freeze: once this line's stock was issued (RIS),
                // moved (transfer) or reserved, its delivered quantity cannot
                // change in either direction — only metadata (costs, DR,
                // expiry, condition) may still be corrected.
                $lineTxn = $this->deliveryItemTransactions($di);
                if (! empty($lineTxn) && abs($newQty - $oldQty) > 0.0001) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.quantity_delivered" =>
                            'Quantity cannot be changed because this stock already has '
                            .implode('; ', array_unique($lineTxn)).'.',
                    ]);
                }

                // Per-line values before mutation (expiry lives on the item).
                $oldDiValues = [
                    'quantity_delivered' => $oldQty,
                    'unit_cost'          => $oldCost,
                    'engas_unit_cost'    => $di->engas_unit_cost,
                    'expiration_date'    => $oldItem?->expiration_date?->toDateString(),
                    'warehouse_id'       => $di->warehouse_id,
                    'dr_number'          => $di->dr_number,
                    'condition'          => $di->condition,
                ];

                $oldItemId = $oldItem ? $oldItem->id : null;
                $movedWarehouse = $oldItem && $newWarehouseId > 0 && (int) $oldItem->warehouse_id !== $newWarehouseId;

                // ── Resolve the item that ends up holding the stock ─────────
                if ($movedWarehouse && $newQty > 0) {
                    // The old record must actually hold what this receipt put
                    // there — otherwise moving it would duplicate stock that
                    // downstream transactions already consumed. Reject instead
                    // of flooring with max(0, …).
                    if ($oldQty > (float) $oldItem->quantity + 0.0001) {
                        throw ValidationException::withMessages([
                            "items.{$idx}.warehouse_id" =>
                                'Cannot move this line: the source record only holds '
                                .number_format($oldItem->quantity).' of the '
                                .number_format($oldQty).' received (the rest was already issued or transferred).',
                        ]);
                    }

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
                            $dsItem->account_code ?: ($dsItem->item?->account_code ?? null),
                            $deliverySubsidy->id
                        );

                        // Lock the exact stock row before the quantity read-modify-write.
                        $item = Item::whereKey($item->id)->lockForUpdate()->first() ?? $item;

                        $item->update([
                            'quantity'   => $item->quantity + $newQty,
                            'ris_number' => $deliverySubsidy->ris_number,
                        ]);
                        // Costs describe live stock: a fully-reversed line
                        // (newQty 0, possibly blank cost input) must not zero
                        // the shared record's cost basis or ENGAS.
                        if ($newQty > 0.0001) {
                            $item->update(['unit_cost' => $newCost]);
                        }
                        if ($newEngas !== null) {
                            $item->update(['engas_unit_cost' => $newEngas]);
                        }
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
                            'ris_number' => $deliverySubsidy->ris_number,
                        ];
                        // Same rule as the cross-warehouse branch above: costs
                        // only sync while the line still carries quantity.
                        if ($newQty > 0.0001) {
                            $itemUpdate['unit_cost'] = $newCost;
                        }
                        if ($newEngas !== null) {
                            $itemUpdate['engas_unit_cost'] = $newEngas;
                        }
                        if ($newExpiry) {
                            $itemUpdate['expiration_date'] = $newExpiry;
                        }
                        $item->update($itemUpdate);
                    }
                }

                if (! $item) {
                    continue;
                }

                $item->applySubsidySnapshot(
                    $deliverySubsidy->id,
                    $deliverySubsidy->ris_number,
                    $deliverySubsidy->dr_number,
                    'active',
                    $deliverySubsidy->subsidy_code
                );
                $affectedItemIds[$item->id] = true;

                // Cascade cost/ENGAS changes to every snapshot referencing this
                // item — requisition lines, dispatch records, and the full
                // transfer chain — so reports never show stale values.
                // Cost changes only cascade while the line still carries
                // quantity; a full reversal must not rewrite history to zero.
                $oldEngas = $oldItem?->engas_unit_cost;
                $costChanged  = $newQty > 0.0001 && abs($oldCost - $newCost) > 0.001;
                $engasChanged = $newQty > 0.0001 && $newEngas !== null && abs((float) ($oldEngas ?? 0) - $newEngas) > 0.001;
                if (abs($delta) > 0.0001 || $costChanged || $engasChanged) {
                    $cascadeSvc->cascadeItemCost($item, $costChanged ? $newCost : $oldCost, $newEngas, $cascadeSummary);
                }

                // Adjust delivery/subsidy item qty_delivered (never negative).
                // The line's own cost basis only syncs while it still carries
                // quantity — a reversal to zero keeps the last real cost.
                $newDsDelivered = max(0, $dsItem->qty_delivered + $delta);
                $dsItemUpdate = ['qty_delivered' => $newDsDelivered];
                if ($newQty > 0.0001) {
                    $dsItemUpdate['unit_cost'] = $newCost;
                    $dsItemUpdate['amount']    = round($dsItem->quantity * $newCost, 2);
                }
                $dsItem->update($dsItemUpdate);
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
                    'condition'          => $newCondition,
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

                // Record what actually changed on this line for the audit trail.
                $newDiValues = [
                    'quantity_delivered' => $newQty,
                    'unit_cost'          => $newCost,
                    'engas_unit_cost'    => $newEngas,
                    'expiration_date'    => $newExpiry,
                    'warehouse_id'       => $newWarehouseId,
                    'dr_number'          => $newDr,
                    'condition'          => $newCondition,
                ];
                foreach ($newDiValues as $field => $newValue) {
                    if ((string) $oldDiValues[$field] !== (string) $newValue) {
                        $changedFields["items.{$idx}.{$field}"] = [
                            'old' => $oldDiValues[$field],
                            'new' => $newValue,
                        ];
                    }
                }
            }

            // Rebuild every affected item's running stock-card balances so later
            // entries stay consistent with the edited receipt.
            foreach (array_keys($affectedItemIds) as $itemId) {
                StockCardEntry::recalculateBalancesForItem($itemId);
            }

            // Header quantity must always equal the sum of its dispatched lines,
            // otherwise the subsidy status/fulfilment figures drift.
            // Header condition keeps the most common per-item value as a
            // summary (legacy header-only submissions fall back gracefully).
            $newHeaderQty = (float) $delivery->items()->sum('quantity_delivered');
            $headerFallback = in_array($request->condition_status, ['good', 'damaged'], true)
                ? $request->condition_status
                : ($delivery->condition_status ?? 'good');
            $headerConditionCounts = array_count_values($finalConditions);
            arsort($headerConditionCounts);
            $newHeaderCondition = ! empty($headerConditionCounts) ? array_key_first($headerConditionCounts) : $headerFallback;
            $delivery->update([
                'dr_number'          => $request->dr_number ?? $delivery->dr_number,
                'delivery_date'      => $request->delivery_date,
                'batch_number'       => $request->batch_number,
                'condition_status'   => $newHeaderCondition,
                'quantity_delivered' => $newHeaderQty,
                'remarks'            => $request->remarks,
            ]);

            // Recompute subsidy total_amount (amounts live on the line items)
            $dispatchedTotal = DeliverySubsidyItem::where('delivery_subsidy_id', $deliverySubsidy->id)
                ->get()
                ->sum(fn ($i) => $i->amount ?? ($i->unit_cost !== null ? $i->quantity * $i->unit_cost : 0));

            $deliverySubsidy->update(['total_amount' => round($dispatchedTotal, 2)]);
            $deliverySubsidy->updateDeliveryStatus();

            // Header-level diffs complete the audit record for this edit.
            $headerFields = [
                'delivery_date'      => ['old' => $oldHeader['delivery_date'],      'new' => $request->delivery_date],
                'dr_number'          => ['old' => $oldHeader['dr_number'],          'new' => $request->dr_number ?? $oldHeader['dr_number']],
                'batch_number'       => ['old' => $oldHeader['batch_number'],       'new' => $request->batch_number],
                'condition_status'   => ['old' => $oldHeader['condition_status'],   'new' => $newHeaderCondition],
                'remarks'            => ['old' => $oldHeader['remarks'],            'new' => $request->remarks],
                'quantity_delivered' => ['old' => $oldHeader['quantity_delivered'], 'new' => $newHeaderQty],
            ];
            foreach ($headerFields as $field => $pair) {
                if ((string) $pair['old'] !== (string) $pair['new']) {
                    $changedFields[$field] = $pair;
                }
            }

            $cascadeSvc->recordAudit($deliverySubsidy->id, $changedFields, $cascadeSummary);
            });

            if ($request->expectsJson()) {
                return response()->json(['redirect' => route('delivery_subsidies.show', $deliverySubsidy)]);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Delivery edit failed', [
                'delivery_subsidy_id' => $deliverySubsidy->id,
                'delivery_id'         => $delivery->id,
                'error'               => $e->getMessage(),
                'trace'               => $e->getTraceAsString(),
            ]);

            if ($request->expectsJson()) {
                return response()->json(['errors' => ['general' => ['The transaction could not be completed. No changes were made. Please try again.']]], 500);
            }

            return back()->withInput()->with('error', 'The transaction could not be completed. No changes were made. Please try again.');
        }

        return redirect()
            ->route('delivery_subsidies.show', $deliverySubsidy)
            ->with('success', 'Shipment updated and stock adjusted.');
    }

    /**
     * Admin: delete a single delivery (shipment) record and reverse its stock
     * and stock-card movements. The parent DeliverySubsidy and its other
     * shipments are preserved. The per-item qty_delivered counters on the
     * DeliverySubsidyItem rows are decremented accordingly.
     *
     * DELETE /delivery-subsidies/{ds}/deliveries/{delivery}
     */
    public function destroyDelivery(DeliverySubsidy $deliverySubsidy, Delivery $delivery)
    {
        abort_unless(Auth::user()->isAdmin(), 403);
        abort_unless($delivery->delivery_subsidy_id === $deliverySubsidy->id, 404);

        $delivery->load(['items.item', 'items.deliverySubsidyItem']);

        // Refuse when this shipment's stock was already consumed downstream
        // (RIS issuance, onward transfer, active reservation lock). Reversing
        // the receipt underneath those movements would leave them pointing at
        // stock that no longer exists — resolve them first, same rule as
        // transfer deletion.
        $blockers = [];
        foreach ($delivery->items as $di) {
            if (! $di->item) {
                continue;
            }
            $label = $di->item->stock_number ?? $di->item->description;
            $risNos = RequisitionDispatchItem::where('item_id', $di->item_id)
                ->with('requisitionItem.requisition')
                ->get()
                ->map(fn ($d) => $d->requisitionItem?->requisition?->ris_number)
                ->filter()->unique()->values()->all();
            foreach ($risNos as $n) {
                $blockers[] = "RIS {$n} issued {$label}";
            }
            $trfNos = StockTransferItem::where('item_id', $di->item_id)
                ->where('quantity', '>', 0)
                ->with('transfer')
                ->get()
                ->map(fn ($s) => $s->transfer?->transfer_number)
                ->filter()->unique()->values()->all();
            foreach ($trfNos as $t) {
                $blockers[] = "transfer {$t} moved {$label}";
            }
            if (ReservationItem::reservedQuantityForItem($di->item_id) > 0.0001) {
                $blockers[] = "active reservation locks {$label}";
            }
        }
        if (! empty($blockers)) {
            return back()->with('error', 'This shipment cannot be deleted because its stock has since been used by: '.implode('; ', array_unique($blockers)).'. Resolve those transactions first, then delete this shipment.');
        }

        try {
            DB::transaction(function () use ($deliverySubsidy, $delivery) {
                $affectedItemIds = [];

                foreach ($delivery->items as $di) {
                    // Lock the exact record, then reverse the quantity this
                    // shipment added. The guard above guarantees the record
                    // still holds at least what is reversed.
                    $item = $di->item ? Item::whereKey($di->item_id)->lockForUpdate()->first() : null;
                    if ($item) {
                        $newQty = max(0, $item->quantity - $di->quantity_delivered);
                        $item->update(['quantity' => $newQty]);
                        $affectedItemIds[$di->item_id] = true;
                    }

                    // Decrement the subsidy line's running delivered total
                    if ($di->deliverySubsidyItem) {
                        $dsi = DeliverySubsidyItem::whereKey($di->delivery_subsidy_item_id)->lockForUpdate()->first();
                        if ($dsi) {
                            $newDelivered = max(0, $dsi->qty_delivered - $di->quantity_delivered);
                            $dsi->update(['qty_delivered' => $newDelivered]);
                        }
                    }

                    // Remove the stock-card receipt entry for this shipment line
                    StockCardEntry::where('reference_type', 'delivery')
                        ->where('reference_id', $delivery->id)
                        ->where('item_id', $di->item_id)
                        ->delete();
                }

                $delivery->items()->delete();
                $delivery->delete();

                // Rebuild running balances for every affected item so later
                // stock-card entries stay consistent.
                foreach (array_keys($affectedItemIds) as $itemId) {
                    StockCardEntry::recalculateBalancesForItem($itemId);
                }

                // Re-sync the parent subsidy status (e.g. fully_delivered → partial)
                $deliverySubsidy->updateDeliveryStatus();
            });
        } catch (\Throwable $e) {
            Log::error('Delivery deletion failed', [
                'delivery_subsidy_id' => $deliverySubsidy->id,
                'delivery_id'         => $delivery->id,
                'error'               => $e->getMessage(),
                'trace'               => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'The shipment could not be deleted. No changes were made. Please try again.');
        }

        return redirect()
            ->route('delivery_subsidies.show', $deliverySubsidy)
            ->with('success', 'Shipment deleted and stock reversed.');
    }

    /**
     * A non-admin user can access it when the header warehouse OR any of its
     * line-item warehouses OR any dispatch (delivery_items) warehouse belongs
     * to them.
     */
    protected function canAccessDeliverySubsidy(User $user, DeliverySubsidy $deliverySubsidy): bool
    {
        if ($user->hasAdminAccess() || $user->isDeliveryUpdater()) {
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
