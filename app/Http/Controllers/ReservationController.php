<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Reservation;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReservationController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        $query = Reservation::with(['warehouse', 'item', 'creator', 'intendedRequisition']);

        if (!$user->hasAdminAccess()) {
            $assignedIds = $user->warehouses()->pluck('warehouses.id')->map(fn($id) => (int) $id);
            if ($user->warehouse_id && !$assignedIds->contains((int) $user->warehouse_id)) {
                $assignedIds->push((int) $user->warehouse_id);
            }
            $query->whereIn('warehouse_id', $assignedIds->unique()->values());
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        $reservations = $query->orderBy('created_at', 'desc')->paginate(20);

        $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get();

        return view('reservations.index', compact('reservations', 'warehouses'));
    }

    public function create(Request $request)
    {
        $user = Auth::user();

        $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get();

        if (!$user->hasAdminAccess()) {
            $assignedIds = $user->warehouses()->pluck('warehouses.id')->map(fn($id) => (int) $id);
            if ($user->warehouse_id && !$assignedIds->contains((int) $user->warehouse_id)) {
                $assignedIds->push((int) $user->warehouse_id);
            }
            $warehouses = $warehouses->whereIn('id', $assignedIds->unique()->values());
        }

        $selectedWarehouse = $request->filled('warehouse_id')
            ? $warehouses->find($request->warehouse_id)
            : null;

        $items = collect();
        if ($selectedWarehouse) {
            $items = Item::where('warehouse_id', $selectedWarehouse->id)
                ->where('is_active', true)
                ->where('quantity', '>', 0)
                ->whereNotNull('stock_number')
                ->orderBy('description')
                ->get();
        }

        return view('reservations.create', compact('warehouses', 'items', 'selectedWarehouse'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'item_id' => 'required|exists:items,id',
            'reserved_quantity' => 'required|numeric|min:0.0001',
            'purpose' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'expires_at' => 'nullable|date|after:today',
        ]);

        $user = Auth::user();

        if (!$user->hasAdminAccess()) {
            $assignedIds = $user->warehouses()->pluck('warehouses.id')->map(fn($id) => (int) $id);
            if ($user->warehouse_id && !$assignedIds->contains((int) $user->warehouse_id)) {
                $assignedIds->push((int) $user->warehouse_id);
            }
            if (!$assignedIds->contains((int) $request->warehouse_id)) {
                abort(403, 'You can only create reservations for warehouses assigned to you.');
            }
        }

        try {
            $reservation = DB::transaction(function () use ($request, $user) {
                $item = Item::whereKey($request->item_id)
                    ->where('warehouse_id', $request->warehouse_id)
                    ->lockForUpdate()
                    ->first();

                if (!$item) {
                    throw ValidationException::withMessages([
                        'item_id' => 'Selected item not found in this warehouse.',
                    ]);
                }

                $availableQty = $item->quantity - Reservation::reservedQuantityForItem($item->id);

                if ($request->reserved_quantity > $availableQty + 0.0001) {
                    throw ValidationException::withMessages([
                        'reserved_quantity' => 'Cannot reserve more than available quantity. Available: ' . number_format($availableQty),
                    ]);
                }

                return Reservation::create([
                    'warehouse_id' => $request->warehouse_id,
                    'item_id' => $request->item_id,
                    'reserved_quantity' => $request->reserved_quantity,
                    'status' => 'PENDING',
                    'purpose' => $request->purpose,
                    'notes' => $request->notes,
                    'expires_at' => $request->expires_at,
                    'created_by' => $user->id,
                ]);
            });

            return redirect()->route('reservations.show', $reservation)
                ->with('success', 'Reservation created successfully.');
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', 'Failed to create reservation: ' . $e->getMessage());
        }
    }

    public function show(Reservation $reservation)
    {
        $user = Auth::user();

        if (!$user->hasAdminAccess()) {
            $assignedIds = $user->warehouses()->pluck('warehouses.id')->map(fn($id) => (int) $id);
            if ($user->warehouse_id && !$assignedIds->contains((int) $user->warehouse_id)) {
                $assignedIds->push((int) $user->warehouse_id);
            }
            if (!$assignedIds->contains((int) $reservation->warehouse_id)) {
                abort(403);
            }
        }

        $reservation->load(['warehouse', 'item', 'item.sourceSubsidy', 'creator', 'approver', 'intendedRequisition']);

        return view('reservations.show', compact('reservation'));
    }

    public function approve(Reservation $reservation)
    {
        $user = Auth::user();

        if (!$user->canWrite()) {
            abort(403);
        }

        if ($reservation->status !== 'PENDING') {
            return back()->with('error', 'Only PENDING reservations can be approved.');
        }

        $reservation->approve($user->id);

        return redirect()->route('reservations.show', $reservation)
            ->with('success', 'Reservation approved.');
    }

    public function markReady(Reservation $reservation)
    {
        $user = Auth::user();

        if (!$user->canWrite()) {
            abort(403);
        }

        if ($reservation->status !== 'RESERVED') {
            return back()->with('error', 'Only RESERVED reservations can be marked as ready.');
        }

        $reservation->markReady();

        return redirect()->route('reservations.show', $reservation)
            ->with('success', 'Reservation marked as ready for requisition.');
    }

    public function cancel(Reservation $reservation)
    {
        $user = Auth::user();

        if (!$user->canWrite()) {
            abort(403);
        }

        if (in_array($reservation->status, ['FULFILLED', 'CANCELLED', 'EXPIRED'])) {
            return back()->with('error', 'This reservation cannot be cancelled.');
        }

        $reservation->cancel();

        return redirect()->route('reservations.show', $reservation)
            ->with('success', 'Reservation cancelled.');
    }

    public function getItemsByWarehouse(Request $request)
    {
        $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
        ]);

        $user = Auth::user();
        $warehouseId = (int) $request->warehouse_id;

        // Non-admin users can only query warehouses they are assigned to
        if (!$user->hasAdminAccess()) {
            $assignedIds = $user->warehouses()->pluck('warehouses.id')->map(fn($id) => (int) $id);
            if ($user->warehouse_id && !$assignedIds->contains($user->warehouse_id)) {
                $assignedIds->push($user->warehouse_id);
            }
            if (!$assignedIds->contains($warehouseId)) {
                abort(403, 'You do not have access to this warehouse.');
            }
        }

        $items = Item::where('warehouse_id', $warehouseId)
            ->where('is_active', true)
            ->where('quantity', '>', 0)
            ->whereNotNull('stock_number')
            ->orderBy('description')
            ->orderBy('unit_cost')
            ->get()
            ->map(function ($item) {
                $reserved = Reservation::reservedQuantityForItem($item->id);
                return [
                    'id' => $item->id,
                    'description' => $item->description,
                    'stock_number' => $item->stock_number,
                    'unit' => $item->unit,
                    'category' => $item->getCategoryLabel(),
                    'physical_qty' => (float) $item->quantity,
                    'reserved_qty' => $reserved,
                    'available_qty' => max(0, (float) $item->quantity - $reserved),
                    'unit_cost' => (float) $item->unit_cost,
                    'engas_unit_cost' => $item->engas_unit_cost !== null ? (float) $item->engas_unit_cost : null,
                    'expiration_date' => $item->expiration_date?->format('M d, Y'),
                    'source_subsidy_code' => $item->source_subsidy_code,
                ];
            })
            ->filter(function ($item) {
                return $item['available_qty'] > 0;
            })
            ->values();

        return response()->json($items);
    }
}
