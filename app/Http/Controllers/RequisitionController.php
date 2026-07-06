<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesWarehouse;
use App\Models\Item;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\StockCardEntry;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RequisitionController extends Controller
{
    use ScopesWarehouse;

    public function index(Request $request)
    {
        $user = Auth::user();
        $query = Requisition::with(['warehouse', 'creator'])
            ->withSum('items as total_requested', 'quantity_requested')
            ->withSum('items as total_issued', 'quantity_issued');

        $this->applyWarehouseScope($query, $user, $request->warehouse_id ? (int) $request->warehouse_id : null);

        if ($request->status) {
            $query->where('status', $request->status);
        }
        if ($search = $request->search) {
            $query->where(function ($q) use ($search) {
                $q->where('ris_number', 'like', "%{$search}%")
                  ->orWhere('dr_number', 'like', "%{$search}%")
                  ->orWhere('office', 'like', "%{$search}%")
                  ->orWhere('purpose', 'like', "%{$search}%");
            });
        }
        $requisitions = $query->orderByDesc('date_requested')->paginate(20)->withQueryString();

        return view('requisitions.index', compact('requisitions'));
    }

    public function create()
    {
        $user = Auth::user();

        if ($user->hasAdminAccess()) {
            $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get();
        } else {
            // Only the warehouses explicitly assigned to this user via the pivot
            $warehouses = $user->warehouses()->where('is_active', true)->orderBy('name')->get();

            if ($warehouses->isEmpty()) {
                return redirect()->route('requisitions.index')
                    ->with('error', 'Your account has no warehouse assigned. Please contact the administrator.');
            }
        }

        return view('requisitions.create', compact('warehouses'));
    }

    /**
     * API: return items with stock > 0 for a given warehouse.
     * Used by the RIS create/edit form to filter the item dropdowns.
     * GET /api/requisition-items?warehouse_id=X
     */
    public function getItemsByWarehouse(Request $request)
    {
        $request->validate(['warehouse_id' => 'required|integer|exists:warehouses,id']);

        $user        = Auth::user();
        $warehouseId = (int) $request->warehouse_id;

        // Non-admin users can only query warehouses they are assigned to
        if (! $user->hasAdminAccess() && ! $user->hasWarehouse($warehouseId)) {
            abort(403);
        }

        $items = Item::where('warehouse_id', $request->warehouse_id)
            ->where('is_active', true)
            ->where('quantity', '>', 0)
            ->whereNotNull('stock_number')
            ->orderBy('description')
            ->get(['id', 'description', 'unit', 'quantity', 'stock_number',
                   'expiration_date', 'category', 'unit_cost']);

        return response()->json($items->map(fn ($i) => [
            'id'           => $i->id,
            'description'  => $i->description,
            'unit'         => $i->unit,
            'quantity'     => $i->quantity,
            'stock_number' => $i->stock_number,
            'expiry_date'  => $i->expiration_date?->format('Y-m-d'),
            'category'     => $i->category,
            'unit_cost'    => $i->unit_cost,
        ]));
    }

    public function store(Request $request)
    {
        $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'dr_number'    => 'required|string|max:100',
            'purpose' => 'required|string',
            'date_requested' => 'required|date',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:items,id',
            'items.*.quantity_requested' => 'required|numeric|min:0.01',
        ]);

        $user = Auth::user();
        if (! $user->hasAdminAccess() && ! $user->hasWarehouse((int) $request->warehouse_id)) {
            abort(403);
        }

        DB::transaction(function () use ($request, $user) {
            // Pre-load all item models for this request to avoid N+1 inside the loop
            $requestedItemIds = collect($request->items)->pluck('item_id')->filter()->unique();
            $itemsMap = Item::whereIn('id', $requestedItemIds)->get()->keyBy('id');

            $ris = Requisition::create([
                'ris_number' => Requisition::generateRisNumber(),
                'dr_number' => $request->dr_number,
                'warehouse_id' => $request->warehouse_id,
                'created_by' => $user->id,
                'entity_name' => $request->entity_name,
                'fund_cluster' => $request->fund_cluster,
                'office' => $request->office,
                'division' => $request->division,
                'responsibility_center_code' => $request->responsibility_center_code,
                'purpose' => $request->purpose,
                'date_requested' => $request->date_requested,
                'requested_by_name' => $request->requested_by_name,
                'requested_by_designation' => $request->requested_by_designation,
            ]);

            foreach ($request->items as $line) {
                $item = $itemsMap->get((int) $line['item_id']);
                if (! $item) continue;
                RequisitionItem::create([
                    'requisition_id'     => $ris->id,
                    'item_id'            => $line['item_id'],
                    'quantity_requested' => $line['quantity_requested'],
                    'stock_available'    => $item->quantity >= $line['quantity_requested'],
                    'unit_cost'          => $item->unit_cost ?? 0,
                    'expiration_date'    => $item->expiration_date,
                ]);
            }

            // Notify admins and warehouse approvers (custodians/heads) assigned to this warehouse
            $approvers = User::where('role', 'admin')
                ->orWhere(function ($q) use ($ris) {
                    $q->whereIn('role', ['center_head', 'supply_custodian'])
                      ->where(function ($q2) use ($ris) {
                          // Legacy warehouse_id column
                          $q2->where('warehouse_id', $ris->warehouse_id)
                             // OR assigned via pivot table
                             ->orWhereHas('warehouses', fn ($q3) => $q3->where('warehouses.id', $ris->warehouse_id));
                      });
                })
                ->get();

            foreach ($approvers as $approver) {
                SystemNotification::create([
                    'user_id' => $approver->id,
                    'title' => 'New RIS Submitted',
                    'message' => "RIS #{$ris->ris_number} requires approval.",
                    'type' => 'warning',
                    'link' => route('requisitions.show', $ris->id),
                ]);
            }
        });

        return redirect()->route('requisitions.index')->with('success', 'Requisition created successfully.');
    }

    public function show(Requisition $requisition)
    {
        $user = Auth::user();
        if (! $this->userCanAccessWarehouse($user, $requisition->warehouse_id)) {
            abort(403);
        }
        $requisition->load(['warehouse', 'creator', 'approver', 'items.item']);

        return view('requisitions.show', compact('requisition'));
    }

    public function edit(Requisition $requisition)
    {
        abort_unless(Auth::user()->canWrite(), 403);

        $requisition->load('items.item');

        $warehouses = Warehouse::where('is_active', true)->get();
        $items = Item::where('is_active', true)->with('warehouse')->orderBy('description')->get();

        return view('requisitions.edit', compact('requisition', 'warehouses', 'items'));
    }

    public function update(Request $request, Requisition $requisition)
    {
        abort_unless(Auth::user()->canWrite(), 403);

        $request->validate([
            'entity_name' => 'nullable|string|max:255',
            'fund_cluster' => 'nullable|string|max:255',
            'dr_number'   => 'required|string|max:100',
            'warehouse_id' => 'required|exists:warehouses,id',
            'office' => 'nullable|string|max:255',
            'division' => 'nullable|string|max:255',
            'responsibility_center_code' => 'nullable|string|max:255',
            'purpose' => 'required|string',
            'date_requested' => 'required|date',
            'status' => 'required|string|in:pending,approved,partially_approved,cancelled',
            'requested_by_name' => 'nullable|string|max:255',
            'requested_by_designation' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:items,id',
            'items.*.quantity_requested' => 'required|numeric|min:0.01',
        ]);

        // Ensure no line item exceeds the available stock for that item
        $stockErrors = [];
        foreach ($request->items as $idx => $line) {
            $item = Item::find($line['item_id']);
            if ($item && $line['quantity_requested'] > $item->quantity) {
                $stockErrors["items.{$idx}.quantity_requested"] =
                    "Quantity for \"{$item->description}\" exceeds available stock ({$item->quantity}).";
            }
        }
        if (!empty($stockErrors)) {
            return back()->withErrors($stockErrors)->withInput();
        }

        DB::transaction(function () use ($request, $requisition) {
            $requisition->update([
                'entity_name' => $request->entity_name,
                'fund_cluster' => $request->fund_cluster,
                'dr_number' => $request->dr_number,
                'warehouse_id' => $request->warehouse_id,
                'office' => $request->office,
                'division' => $request->division,
                'responsibility_center_code' => $request->responsibility_center_code,
                'purpose' => $request->purpose,
                'date_requested' => $request->date_requested,
                'requested_by_name' => $request->requested_by_name,
                'requested_by_designation' => $request->requested_by_designation,
                'status' => $request->status,
            ]);

            $existingItems = $requisition->items()->get()->keyBy('item_id');

            $requisition->items()->delete();

            foreach ($request->items as $line) {
                $item = Item::findOrFail($line['item_id']);
                $old = $existingItems->get((int) $line['item_id']);

                RequisitionItem::create([
                    'requisition_id'     => $requisition->id,
                    'item_id'            => $line['item_id'],
                    'quantity_requested' => $line['quantity_requested'],
                    'quantity_issued'    => $old ? $old->quantity_issued : 0,
                    'stock_available'    => $item->quantity >= $line['quantity_requested'],
                    'unit_cost'          => $item->unit_cost ?? 0,
                    'expiration_date'    => $item->expiration_date,
                ]);
            }
        });

        return redirect()->route('requisitions.show', $requisition)
            ->with('success', 'Requisition updated successfully.');
    }

    public function destroy(Requisition $requisition)
    {
        // Only full administrators can delete requisitions
        abort_unless(Auth::user()->isAdmin(), 403, 'Only administrators can delete requisitions.');

        $risNumber = $requisition->ris_number;
        $requisition->load('items.item');

        DB::transaction(function () use ($requisition) {
            // Reverse any stock that was already issued against this RIS
            foreach ($requisition->items as $ri) {
                if ($ri->quantity_issued > 0 && $ri->item) {
                    // Add the issued quantity back to inventory
                    $ri->item->increment('quantity', $ri->quantity_issued);
                }
            }

            // Delete all issuance stock card entries for this RIS
            StockCardEntry::where('reference_type', 'issuance')
                ->where('reference_id', $requisition->id)
                ->delete();

            $requisition->items()->delete();
            $requisition->delete();
        });

        return redirect()->route('requisitions.index')
            ->with('success', "RIS #{$risNumber} deleted and any issued stock has been reversed.");
    }

    public function approve(Requisition $requisition)
    {
        if (! Auth::user()->canApprove()) {
            abort(403);
        }
        $requisition->load('items.item');

        return view('requisitions.approve', compact('requisition'));
    }

    public function processApproval(Request $request, Requisition $requisition)
    {
        if (! Auth::user()->canApprove()) {
            abort(403);
        }

        $request->validate([
            'items' => 'required|array',
            'items.*.quantity_issued' => 'required|numeric|min:0',
            'approved_by_name' => 'required|string',
            'issued_by_name' => 'required|string',
        ]);

        $requisition->load('warehouse', 'items.item');

        DB::transaction(function () use ($request, $requisition) {
            $user      = Auth::user();
            $anyIssued = false;

            foreach ($request->items as $riItemId => $data) {
                $riItem = RequisitionItem::where('id', $riItemId)
                    ->where('requisition_id', $requisition->id)
                    ->firstOrFail();

                $item = $riItem->item;
                if (! $item) {
                    continue;
                }

                // How much is still outstanding for this line
                $alreadyIssued = (float) $riItem->quantity_issued;
                $stillNeeded   = max(0, $riItem->quantity_requested - $alreadyIssued);

                // Cap the new issuance at what's available and what's still needed
                $newIssuance = min(
                    (float) $data['quantity_issued'],
                    $item->quantity,   // can't issue more than stock
                    $stillNeeded       // can't issue more than what's outstanding
                );

                if ($newIssuance > 0) {
                    $anyIssued = true;
                    $newItemQty = $item->quantity - $newIssuance;

                    // Accumulate — add to existing quantity_issued
                    $riItem->update([
                        'quantity_issued' => $alreadyIssued + $newIssuance,
                        'stock_available' => $item->quantity >= $riItem->quantity_requested,
                    ]);

                    $item->update(['quantity' => $newItemQty]);

                    StockCardEntry::create([
                        'item_id'            => $item->id,
                        'entry_date'         => now()->toDateString(),
                        'reference'          => $requisition->ris_number,
                        'reference_type'     => 'issuance',
                        'reference_id'       => $requisition->id,
                        'receipt_qty'        => 0,
                        'receipt_unit_cost'  => 0,
                        'receipt_total_cost' => 0,
                        'issue_qty'          => $newIssuance,
                        'balance_qty'        => $newItemQty,
                        'balance_unit_cost'  => $item->unit_cost,
                        'balance_total_cost' => $newItemQty * $item->unit_cost,
                        'from_to'            => $requisition->office ?? ($requisition->warehouse->name ?? ''),
                    ]);
                }
            }

            // Refresh items to get updated quantity_issued values, then recalculate status
            $requisition->load('items');
            $requisition->updateFulfilmentStatus();

            // Update signatories and approval metadata
            $requisition->update([
                'approved_by'              => $user->id,
                'date_approved'            => now()->toDateString(),
                'approved_by_name'         => $request->approved_by_name,
                'approved_by_designation'  => $request->approved_by_designation,
                'issued_by_name'           => $request->issued_by_name,
                'issued_by_designation'    => $request->issued_by_designation,
                'received_by_name'         => $request->received_by_name,
                'received_by_designation'  => $request->received_by_designation,
            ]);

            $status = $requisition->fresh()->status;

            SystemNotification::create([
                'user_id' => $requisition->created_by,
                'title'   => 'RIS ' . match($status) {
                    'approved'           => 'Fully Fulfilled',
                    'partially_approved' => 'Partially Fulfilled',
                    default              => 'Updated',
                },
                'message' => match($status) {
                    'approved'           => "Your RIS #{$requisition->ris_number} has been fully fulfilled.",
                    'partially_approved' => "Your RIS #{$requisition->ris_number} has been partially fulfilled. Some items are still outstanding.",
                    default              => "Your RIS #{$requisition->ris_number} has been updated.",
                },
                'type' => $status === 'approved' ? 'success' : 'info',
                'link' => route('requisitions.show', $requisition->id),
            ]);
        });

        return redirect()->route('requisitions.show', $requisition)
            ->with('success', 'Requisition processed successfully.');
    }

    public function signatories(Requisition $requisition)
    {
        $user = Auth::user();
        if (! $this->userCanAccessWarehouse($user, $requisition->warehouse_id)) {
            abort(403);
        }

        return view('requisitions.signatories', compact('requisition'));
    }

    public function updateSignatories(Request $request, Requisition $requisition)
    {
        $user = Auth::user();
        if (! $this->userCanAccessWarehouse($user, $requisition->warehouse_id)) {
            abort(403);
        }
        $requisition->update($request->only([
            'requested_by_name', 'requested_by_designation',
            'approved_by_name',  'approved_by_designation',
            'issued_by_name',    'issued_by_designation',
            'received_by_name',  'received_by_designation',
        ]));

        return redirect()->route('requisitions.show', $requisition)->with('success', 'Signatories updated.');
    }

    public function printRis(Requisition $requisition)
    {
        $user = Auth::user();
        if (! $this->userCanAccessWarehouse($user, $requisition->warehouse_id)) {
            abort(403);
        }
        $requisition->load(['warehouse', 'items.item', 'creator', 'approver']);

        return view('requisitions.print', compact('requisition'));
    }
}
