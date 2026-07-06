<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $query = Supplier::query();
        if ($request->search) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }
        $query->orderBy('is_active', 'desc')->orderBy('name');
        $suppliers = $query->paginate(20)->withQueryString();
        return view('suppliers.index', compact('suppliers'));
    }

    public function create()
    {
        return view('suppliers.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'address'        => 'nullable|string',
            'tin'            => 'nullable|string|max:50',
            'contact_person' => 'nullable|string|max:100',
            'phone'          => 'nullable|string|max:50',
            'email'          => 'nullable|email|max:100',
        ]);

        Supplier::create($validated); // validated only — not $request->all()
        return redirect()->route('suppliers.index')->with('success', 'Supplier added successfully.');
    }

    public function edit(Supplier $supplier)
    {
        return view('suppliers.edit', compact('supplier'));
    }

    public function update(Request $request, Supplier $supplier)
    {
        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'address'        => 'nullable|string',
            'tin'            => 'nullable|string|max:50',
            'contact_person' => 'nullable|string|max:100',
            'phone'          => 'nullable|string|max:50',
            'email'          => 'nullable|email|max:100',
        ]);

        $supplier->update($validated);
        return redirect()->route('suppliers.index')->with('success', 'Supplier updated.');
    }

    public function toggleActive(Supplier $supplier)
    {
        $supplier->update(['is_active' => ! $supplier->is_active]);
        $state = $supplier->is_active ? 'activated' : 'deactivated';
        return redirect()->route('suppliers.index')
            ->with('success', "Supplier \"{$supplier->name}\" {$state}.");
    }
}
