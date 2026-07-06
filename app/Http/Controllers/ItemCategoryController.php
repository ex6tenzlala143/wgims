<?php

namespace App\Http\Controllers;

use App\Models\ItemCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ItemCategoryController extends Controller
{
    public function index()
    {
        $categories = ItemCategory::withCount('items')
            ->orderBy('sort_order')
            ->orderBy('key')
            ->get();

        return view('item_categories.index', compact('categories'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'label'        => 'required|string|max:255',
            'account_code' => 'required|string|max:50',
            'key'          => [
                'nullable', 'string', 'max:50',
                'regex:/^[a-z0-9\-_]+$/',
                Rule::unique('item_categories', 'key'),
            ],
        ], [
            'key.regex' => 'The key may only contain lowercase letters, numbers, hyphens and underscores.',
        ]);

        // Auto-generate key from label if not provided
        $key = $request->filled('key')
            ? strtolower(trim($request->key))
            : Str::slug($request->label, '-');

        // Ensure uniqueness of auto-generated key
        $base    = $key;
        $attempt = 1;
        while (ItemCategory::where('key', $key)->exists()) {
            $key = $base . '-' . $attempt++;
        }

        $maxSort = ItemCategory::max('sort_order') ?? 0;

        ItemCategory::create([
            'key'          => $key,
            'label'        => $request->label,
            'account_code' => $request->account_code,
            'is_active'    => true,
            'sort_order'   => $maxSort + 1,
        ]);

        return redirect()->route('item_categories.index')
            ->with('success', "Category \"{$request->label}\" created successfully.");
    }

    public function update(Request $request, ItemCategory $itemCategory)
    {
        $request->validate([
            'label'        => 'required|string|max:255',
            'account_code' => 'required|string|max:50',
            'is_active'    => 'nullable|boolean',
            'sort_order'   => 'nullable|integer|min:0',
        ]);

        $itemCategory->update([
            'label'        => $request->label,
            'account_code' => $request->account_code,
            'is_active'    => $request->boolean('is_active', true),
            'sort_order'   => $request->input('sort_order', $itemCategory->sort_order),
        ]);

        return redirect()->route('item_categories.index')
            ->with('success', "Category \"{$itemCategory->label}\" updated.");
    }

    public function destroy(ItemCategory $itemCategory)
    {
        // Prevent deleting a category that has items linked to it
        if ($itemCategory->items()->exists()) {
            return redirect()->route('item_categories.index')
                ->with('error', "Cannot delete \"{$itemCategory->label}\" — it has items assigned to it. Deactivate it instead.");
        }

        $label = $itemCategory->label;
        $itemCategory->delete();

        return redirect()->route('item_categories.index')
            ->with('success', "Category \"{$label}\" deleted.");
    }

    public function toggleActive(ItemCategory $itemCategory)
    {
        $itemCategory->update(['is_active' => ! $itemCategory->is_active]);

        $state = $itemCategory->is_active ? 'activated' : 'deactivated';
        return redirect()->route('item_categories.index')
            ->with('success', "Category \"{$itemCategory->label}\" {$state}.");
    }
}
