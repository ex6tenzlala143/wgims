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
use App\Models\StockCardEntry;
use App\Models\StockTransfer;
use App\Models\StockTransferAuditLog;
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
            'has_deliveries'  => $dsi->deliveryItems()->count() > 0,
            'qty_delivered'   => (float) $dsi->qty_delivered,
            'locked'          => (float) $dsi->qty_delivered > 0,
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

        // ── The merged Edit Subsidy function ────────────────────────────────
        //  • No deliveries yet  → the whole request is editable (RIS number,
        //    supplier, header fields, line items) and is rebuilt from the
        //    submitted lines. Status is limited to pending / cancelled.
        //  • Deliveries exist    → this is a correction: the historical identity
        //    (RIS number / supplier / DR number) is frozen and only the header
        //    fields (date, place of delivery, remarks) plus per-line requested
        //    quantities may change. A line that already has delivered stock is
        //    locked to its item and can never drop below the delivered quantity.
        //    Shipments, delivered quantities, stock cards, inventory, warehouse
        //    assignments and DR numbers are NEVER modified here.
        //
        // In both modes the header requested total is recomputed from the lines
        // (so the header and the lines can never drift apart), the status is
        // recomputed from requested vs. delivered, and every change is written
        // to the audit log.
        $hasDeliveries = $deliverySubsidy->deliveries()->count() > 0;

        // Unit Cost and Warehouse are deliberately NOT editable here — they are
        // only assigned by the dispatcher when a delivery is recorded.
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
        ];

        if ($hasDeliveries) {
            // Frozen identity: every submitted line must be an existing line of
            // this subsidy (no additions, no removals — only quantity edits).
            $rules += [
                'ris_number'      => 'required|string|max:255',
                'supplier_id'     => 'required|exists:suppliers,id',
                'items.*.dsi_id'  => 'required|integer|exists:delivery_subsidy_items,id',
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
            // Historical identity is frozen once deliveries exist — reject any
            // attempt to change it, even from a tampered request.
            if ((string) $request->ris_number !== (string) $deliverySubsidy->ris_number) {
                throw ValidationException::withMessages([
                    'ris_number' => 'The RIS number cannot be changed once deliveries have been recorded.',
                ]);
            }
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

            if ($hasDeliveries) {
                // ── Header: only date / place of delivery / remarks may change ──
                // ris_number / supplier_id / dr_number are the historical
                // identity of the request and are deliberately frozen here.
                foreach (['date', 'place_of_delivery', 'remarks'] as $field) {
                    $oldVal = $deliverySubsidy->{$field} instanceof \DateTimeInterface
                        ? $deliverySubsidy->{$field}->format('Y-m-d')
                        : $deliverySubsidy->{$field};
                    $newVal = $request->{$field};
                    $headerData[$field] = $newVal;
                    if ((string) $oldVal !== (string) $newVal) {
                        $changes[$field] = ['old' => $oldVal, 'new' => $newVal];
                    }
                }

                // Every submitted line must belong to this subsidy.
                foreach ($request->items as $idx => $line) {
                    if (! $existingItems->has((int) $line['dsi_id'])) {
                        throw ValidationException::withMessages([
                            "items.{$idx}.dsi_id" => 'Invalid line item for this subsidy.',
                        ]);
                    }
                }

                // ── Lines: requested quantity (item change only when undelivered) ──
                foreach ($request->items as $idx => $line) {
                    $dsi    = $existingItems->get((int) $line['dsi_id']);
                    $oldQty = (float) $dsi->quantity;
                    $newQty = round((float) $line['quantity'], 4);
                    $locked = (float) $dsi->qty_delivered > 0;

                    // Inventory protection: the request can never drop below what is
                    // already delivered. Resolve an over-delivery via the shipment
                    // edit instead.
                    if ($newQty + 0.0001 < (float) $dsi->qty_delivered) {
                        throw ValidationException::withMessages([
                            "items.{$idx}.quantity" =>
                                'Requested quantity ('.number_format($newQty).') cannot be less than the '
                                .number_format($dsi->qty_delivered).' already delivered. '
                                .'Edit the shipment(s) first, then fix the request.',
                        ]);
                    }

                    // A delivered line is locked: only its requested quantity may
                    // change. Attempting to re-point it at another item is rejected.
                    if ($locked) {
                        $submittedCat  = $line['catalog_item_id'] ?? null;
                        $submittedItem = $line['item_id'] ?? null;
                        if ($submittedCat && (int) $submittedCat !== (int) $dsi->catalog_item_id) {
                            throw ValidationException::withMessages([
                                "items.{$idx}.catalog_item_id" => 'This item has already been delivered and cannot be changed. Edit the request quantity only.',
                            ]);
                        }
                        if ($submittedItem && (int) $submittedItem !== (int) $dsi->item_id) {
                            throw ValidationException::withMessages([
                                "items.{$idx}.item_id" => 'This item has already been delivered and cannot be changed. Edit the request quantity only.',
                            ]);
                        }
                    }

                    $data    = ['quantity' => $newQty];
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

                    $dsi->update($data);

                    if (abs($newQty - $oldQty) > 0.0001) {
                        $changes["items.{$dsi->id}.quantity"] = ['old' => $oldQty, 'new' => $newQty];
                    }
                    if (! $locked && $dsi->description !== $oldDesc) {
                        $changes["items.{$dsi->id}.item"] = ['old' => $oldDesc, 'new' => $dsi->description];
                    }
                }

                // Recompute the header requested total from the edited lines so
                // the header and the lines can never drift apart.
                $newRequested = (int) round((float) $deliverySubsidy->items()->sum('quantity'), 4);
                $headerData['quantity_requested'] = $newRequested;

                $deliverySubsidy->update($headerData);

                // Recompute requested → delivered → outstanding → status. Shipments
                // and inventory records are untouched — only the request is
                // reclassified.
                $deliverySubsidy->refresh();
                $deliverySubsidy->updateDeliveryStatus();

                if (abs($newRequested - $oldRequested) > 0.0001) {
                    $changes['quantity_requested'] = ['old' => $oldRequested, 'new' => $newRequested];
                }
                if ($deliverySubsidy->status !== $oldStatus) {
                    $changes['status'] = ['old' => $oldStatus, 'new' => $deliverySubsidy->status];
                }

                $cascadeSvc->recordAudit($deliverySubsidy->id, $changes, [], 'correction');
            } else {
                // ── No deliveries: full header edit + line rebuild ──────────
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

                // Recompute the requested total from the submitted lines.
                $newRequested = (int) collect($request->items)->sum(fn ($l) => (int) $l['quantity']);

                $deliverySubsidy->update($headerFields + ['quantity_requested' => $newRequested]);

                if (abs($newRequested - $oldRequested) > 0.0001) {
                    $changes['quantity_requested'] = ['old' => $oldRequested, 'new' => $newRequested];
                }

                // No deliveries yet — update matching lines in place (stable IDs
                // for the audit trail), create new lines, and drop orphans.
                // Nothing downstream exists (no shipments, no stock cards, no
                // transfers), so the historical identity is safe to rewrite.
                $submittedDsiIds = [];

                foreach ($request->items as $line) {
                    $data = [
                        'item_id'         => $line['item_id'] ?? null,
                        'catalog_item_id' => $line['catalog_item_id'] ?? null,
                        'account_code'    => $line['account_code'] ?? null,
                        'description'     => $line['description'],
                        'unit'            => $line['unit'],
                        'category'        => $line['category'],
                        'quantity'        => (int) $line['quantity'],
                        'expiration_date' => $line['expiration_date'] ?? null,
                    ];

                    $dsiId = $line['dsi_id'] ?? null;
                    $dsi   = $dsiId && $existingItems->has((int) $dsiId)
                        ? $existingItems->get((int) $dsiId)
                        : null;

                    if ($dsi) {
                        $oldQty  = (float) $dsi->quantity;
                        $oldDesc = $dsi->description;
                        $dsi->update($data);

                        if (abs((float) $data['quantity'] - $oldQty) > 0.0001) {
                            $changes["items.{$dsi->id}.quantity"] = ['old' => $oldQty, 'new' => (float) $data['quantity']];
                        }
                        if ($dsi->description !== $oldDesc) {
                            $changes["items.{$dsi->id}.item"] = ['old' => $oldDesc, 'new' => $dsi->description];
                        }
                        $submittedDsiIds[] = $dsi->id;
                    } else {
                        $dsi = DeliverySubsidyItem::create($data + [
                            'delivery_subsidy_id' => $deliverySubsidy->id,
                            'unit_cost'           => null,
                            'warehouse_id'        => null,
                            'amount'              => null,
                        ]);
                        $submittedDsiIds[] = $dsi->id;
                    }
                }

                // Remove orphan lines that were dropped from the form.
                DeliverySubsidyItem::where('delivery_subsidy_id', $deliverySubsidy->id)
                    ->whereNotIn('id', $submittedDsiIds)
                    ->whereDoesntHave('deliveryItems')
                    ->delete();

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
            $markedTransferCount = $this->markTransfersWithSubsidyState($deliverySubsidy, 'deleted', 'subsidy_deleted');

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

        $warning = $this->downstreamUsageWarning($lineageIds, $descendantIds);

        return redirect()->route('delivery_subsidies.index')
            ->with('success', $success)
            ->with('warning', $warning);
    }

    /**
     * Build the safety warning for deleted Subsidy stock that has already been
     * consumed by another transaction: a requisition issue from any lineage item,
     * or a second+ hop onward transfer of the moved stock. That stock is
     * preserved — never auto-reversed — and the administrator is told to review
     * the related transactions first. The flagged direct transfer itself is not
     * part of the warning: it is the preserved review item the admin already sees.
     */
    private function downstreamUsageWarning(array $lineageIds, array $descendantIds): ?string
    {
        $transferNumbers = [];
        $requisitionNumbers = [];

        foreach ($lineageIds as $itemId) {
            // Stock issued through a requisition dispatch.
            $requisitionNumbers = array_merge(
                $requisitionNumbers,
                RequisitionDispatchItem::where('item_id', $itemId)
                    ->with('requisitionItem.requisition')
                    ->get()
                    ->map(fn ($di) => $di->requisitionItem?->requisition?->ris_number)
                    ->filter()
                    ->unique()
                    ->all()
            );
        }

        // Stock sent onward to yet another warehouse (2nd+ hop): only transfers
        // whose SOURCE is a descendant count — the direct root→destination hop
        // is the transfer the admin already reviews.
        foreach ($descendantIds as $itemId) {
            $onward = StockTransferItem::with('transfer')
                ->where('item_id', $itemId)
                ->where('quantity', '>', 0)
                ->get();

            foreach ($onward as $sti) {
                if ($sti->transfer) {
                    $transferNumbers[] = $sti->transfer->transfer_number;
                }
            }
        }

        $transferNumbers = array_values(array_unique($transferNumbers));
        $requisitionNumbers = array_values(array_unique($requisitionNumbers));

        if (empty($transferNumbers) && empty($requisitionNumbers)) {
            return null;
        }

        $parts = [];
        if ($transferNumbers) {
            $parts[] = 'transferred onward (' . implode(', ', $transferNumbers) . ')';
        }
        if ($requisitionNumbers) {
            $parts[] = 'issued through a requisition (' . implode(', ', $requisitionNumbers) . ')';
        }

        return "This stock originated from a deleted Subsidy and has already been " . implode(' and ', $parts)
            . ". Review the related transactions before reversing — the affected stock has been preserved and flagged, not reversed.";
    }

    /**
     * Admin: archive a Subsidy (freeze it without deleting). Related Stock
     * Transfers are flagged 'archived' so the administrator can find them.
     */
    public function archive(DeliverySubsidy $deliverySubsidy)
    {
        abort_unless(Auth::user()->canWrite(), 403);

        if (! $deliverySubsidy->isArchived()) {
            try {
                DB::transaction(function () use ($deliverySubsidy) {
                    $deliverySubsidy->update(['is_archived' => true]);

                    $this->markTransfersWithSubsidyState($deliverySubsidy, 'archived', 'subsidy_archived');

                    $this->markItemsWithSubsidyState($deliverySubsidy, 'archived');

                    DeliverySubsidyAuditLog::create([
                        'delivery_subsidy_id' => $deliverySubsidy->id,
                        'user_id'             => Auth::user()->id,
                        'action'              => 'archive',
                        'changed_fields'      => [
                            'is_archived' => ['old' => false, 'new' => true],
                        ],
                    ]);
                });
            } catch (ValidationException $e) {
                throw $e;
            } catch (\Throwable $e) {
                Log::error('Delivery / subsidy archiving failed', [
                    'delivery_subsidy_id' => $deliverySubsidy->id,
                    'error'               => $e->getMessage(),
                    'trace'               => $e->getTraceAsString(),
                ]);

                return back()->with('error', 'The transaction could not be completed. No changes were made. Please try again.');
            }
        }

        return redirect()->route('delivery_subsidies.index')
            ->with('success', "RIS #{$deliverySubsidy->ris_number} archived. Related stock transfers are now flagged for review.");
    }

    /**
     * Admin: restore an archived Subsidy. Transfers flagged 'archived' by it
     * return to their normal state (the flag is cleared so they blend back into
     * the active list).
     */
    public function restore(DeliverySubsidy $deliverySubsidy)
    {
        abort_unless(Auth::user()->canWrite(), 403);

        if ($deliverySubsidy->isArchived()) {
            try {
                DB::transaction(function () use ($deliverySubsidy) {
                    $deliverySubsidy->update(['is_archived' => false]);

                    foreach ($this->relatedStockTransfers($deliverySubsidy) as $transfer) {
                        if ($transfer->source_subsidy_status !== 'archived') {
                            continue;
                        }

                        $transfer->update(['source_subsidy_status' => null]);

                        StockTransferAuditLog::create([
                            'stock_transfer_id' => $transfer->id,
                            'transfer_number'   => $transfer->transfer_number,
                            'user_id'           => Auth::user()->id,
                            'action'            => 'subsidy_restored',
                            'changed_fields'    => [
                                'ris_number'     => $deliverySubsidy->ris_number,
                                'dr_number'      => $deliverySubsidy->dr_number,
                                'subsidy_status' => null,
                            ],
                        ]);
                    }

                    $this->markItemsWithSubsidyState($deliverySubsidy, 'active');

                    DeliverySubsidyAuditLog::create([
                        'delivery_subsidy_id' => $deliverySubsidy->id,
                        'user_id'             => Auth::user()->id,
                        'action'              => 'restore',
                        'changed_fields'      => [
                            'is_archived' => ['old' => true, 'new' => false],
                        ],
                    ]);
                });
            } catch (ValidationException $e) {
                throw $e;
            } catch (\Throwable $e) {
                Log::error('Delivery / subsidy restore failed', [
                    'delivery_subsidy_id' => $deliverySubsidy->id,
                    'error'               => $e->getMessage(),
                    'trace'               => $e->getTraceAsString(),
                ]);

                return back()->with('error', 'The transaction could not be completed. No changes were made. Please try again.');
            }
        }

        return redirect()->route('delivery_subsidies.index')
            ->with('success', "RIS #{$deliverySubsidy->ris_number} restored. Related stock transfer flags were cleared.");
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
     * state ('deleted' | 'archived'), snapshot the RIS/DR references, and write
     * an audit entry on each transfer so the trail outlives the subsidy record.
     *
     * Returns the number of transfers flagged.
     */
    private function markTransfersWithSubsidyState(DeliverySubsidy $deliverySubsidy, string $state, string $auditAction): int
    {
        $transfers = $this->relatedStockTransfers($deliverySubsidy);

        foreach ($transfers as $transfer) {
            $transfer->forceFill([
                'source_subsidy_status' => $state,
                'source_ris_number'     => $transfer->source_ris_number ?: $deliverySubsidy->ris_number,
                'source_dr_number'      => $transfer->source_dr_number ?: $deliverySubsidy->dr_number,
            ])->save();

            StockTransferAuditLog::create([
                'stock_transfer_id' => $transfer->id,
                'transfer_number'   => $transfer->transfer_number,
                'user_id'           => Auth::user()->id,
                'action'            => $auditAction,
                'changed_fields'    => [
                    'ris_number'     => $deliverySubsidy->ris_number,
                    'dr_number'      => $deliverySubsidy->dr_number,
                    'subsidy_status' => $state,
                    'marked_at'      => now()->toDateTimeString(),
                ],
            ]);
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
     * across warehouses. When restoring an archived subsidy, we clear the
     * 'archived' flag (set to null) instead of marking it as 'active', since
     * 'active' is confusing and looks like a warning.
     */
    private function markItemsWithSubsidyState(DeliverySubsidy $deliverySubsidy, string $state): void
    {
        $lineageIds = $this->transferLineageItemIds(
            $this->relatedItems($deliverySubsidy)->pluck('id')->all()
        );

        foreach (Item::whereIn('id', $lineageIds)->get() as $item) {
            // When restoring (state = 'active'), only clear items that this subsidy previously archived
            if ($state === 'active') {
                if ($item->source_subsidy_status === 'archived' && $item->source_subsidy_id === $deliverySubsidy->id) {
                    // Clear the archived status by setting to null
                    $item->applySubsidySnapshot(
                        $deliverySubsidy->id,
                        $deliverySubsidy->ris_number,
                        $deliverySubsidy->dr_number,
                        null,
                        $deliverySubsidy->subsidy_code
                    );
                }
                continue;
            }

            // For deleted/archived states, apply the status
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

    public function delivery(DeliverySubsidy $deliverySubsidy)
    {
        $user = Auth::user();
        if (! $this->canAccessDeliverySubsidy($user, $deliverySubsidy)) {
            abort(403);
        }
        if ($deliverySubsidy->isArchived()) {
            return redirect()->route('delivery_subsidies.show', $deliverySubsidy)
                ->with('error', 'This Subsidy is archived. Restore it before recording new deliveries.');
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
        if ($deliverySubsidy->isArchived()) {
            return back()->with('error', 'This Subsidy is archived. Restore it before recording new deliveries.');
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

        try {
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
            'items.*.quantity_delivered' => 'required|integer|min:0',
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
                $delta     = $newQty - $oldQty;
                $oldCost   = (float) $di->unit_cost;

                $dsItem = $di->deliverySubsidyItem;
                $oldItem = Item::whereKey($di->item_id)->lockForUpdate()->first();

                if (! $dsItem) {
                    continue;
                }

                // Per-line values before mutation (expiry lives on the item).
                $oldDiValues = [
                    'quantity_delivered' => $oldQty,
                    'unit_cost'          => $oldCost,
                    'engas_unit_cost'    => $di->engas_unit_cost,
                    'expiration_date'    => $oldItem?->expiration_date?->toDateString(),
                    'warehouse_id'       => $di->warehouse_id,
                    'dr_number'          => $di->dr_number,
                ];

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
                            $dsItem->account_code ?: ($dsItem->item?->account_code ?? null),
                            $deliverySubsidy->id
                        );

                        // Lock the exact stock row before the quantity read-modify-write.
                        $item = Item::whereKey($item->id)->lockForUpdate()->first() ?? $item;

                        $item->update([
                            'quantity'   => $item->quantity + $newQty,
                            'unit_cost'  => $newCost,
                            'ris_number' => $deliverySubsidy->ris_number,
                        ]);
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
                            'unit_cost'  => $newCost,
                            'ris_number' => $deliverySubsidy->ris_number,
                        ];
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
                $oldEngas = $oldItem?->engas_unit_cost;
                if (abs($delta) > 0.0001
                    || abs($oldCost - $newCost) > 0.001
                    || ($newEngas !== null && abs((float) ($oldEngas ?? 0) - $newEngas) > 0.001)) {
                    $cascadeSvc->cascadeItemCost($item, $newCost, $newEngas, $cascadeSummary);
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

                // Record what actually changed on this line for the audit trail.
                $newDiValues = [
                    'quantity_delivered' => $newQty,
                    'unit_cost'          => $newCost,
                    'engas_unit_cost'    => $newEngas,
                    'expiration_date'    => $newExpiry,
                    'warehouse_id'       => $newWarehouseId,
                    'dr_number'          => $newDr,
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

            // Header-level diffs complete the audit record for this edit.
            $headerFields = [
                'delivery_date'      => ['old' => $oldHeader['delivery_date'],      'new' => $request->delivery_date],
                'dr_number'          => ['old' => $oldHeader['dr_number'],          'new' => $request->dr_number ?? $oldHeader['dr_number']],
                'batch_number'       => ['old' => $oldHeader['batch_number'],       'new' => $request->batch_number],
                'condition_status'   => ['old' => $oldHeader['condition_status'],   'new' => $request->condition_status],
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
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Delivery edit failed', [
                'delivery_subsidy_id' => $deliverySubsidy->id,
                'delivery_id'         => $delivery->id,
                'error'               => $e->getMessage(),
                'trace'               => $e->getTraceAsString(),
            ]);

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

        try {
            DB::transaction(function () use ($deliverySubsidy, $delivery) {
                $affectedItemIds = [];

                foreach ($delivery->items as $di) {
                    // Reverse the quantity this shipment added to inventory
                    if ($di->item) {
                        $newQty = max(0, $di->item->quantity - $di->quantity_delivered);
                        $di->item->update(['quantity' => $newQty]);
                        $affectedItemIds[$di->item_id] = true;
                    }

                    // Decrement the subsidy line's running delivered total
                    if ($di->deliverySubsidyItem) {
                        $newDelivered = max(0, $di->deliverySubsidyItem->qty_delivered - $di->quantity_delivered);
                        $di->deliverySubsidyItem->update(['qty_delivered' => $newDelivered]);
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
