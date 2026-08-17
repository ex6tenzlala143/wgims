<?php

namespace App\Http\Controllers;

use App\Models\ItemCatalogItem;
use App\Models\ItemCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ItemCatalogItemController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'item_category_id' => 'required|exists:item_categories,id',
            'name'             => 'required|string|max:255',
        ]);

        $category = ItemCategory::findOrFail($request->item_category_id);

        // Item names must be unique within a category
        $request->validate([
            'name' => Rule::unique('item_catalog_items', 'name')
                ->where('item_category_id', $category->id),
        ], ['name.unique' => 'That item name already exists under this category.']);

        // Automatically inherit account code from the parent category
        ItemCatalogItem::create([
            'item_category_id' => $category->id,
            'name'             => $request->name,
            'account_code'     => $category->account_code,
            'is_active'        => true,
        ]);

        return back()->with('success', "Item name \"{$request->name}\" added to \"{$category->label}\".");
    }

    public function update(Request $request, ItemCatalogItem $catalogItem)
    {
        $request->validate([
            'name'         => 'required|string|max:255',
            'is_active'    => 'nullable|boolean',
        ]);

        $request->validate([
            'name' => Rule::unique('item_catalog_items', 'name')
                ->where('item_category_id', $catalogItem->item_category_id)
                ->ignore($catalogItem->id),
        ], ['name.unique' => 'That item name already exists under this category.']);

        // Automatically inherit account code from the parent category
        $catalogItem->update([
            'name'         => $request->name,
            'account_code' => $catalogItem->category->account_code,
            'is_active'    => $request->boolean('is_active', $catalogItem->is_active),
        ]);

        return back()->with('success', "Item name \"{$catalogItem->name}\" updated.");
    }

    public function destroy(ItemCatalogItem $catalogItem)
    {
        // Prevent deleting an item name that is referenced by subsidy/delivery lines
        if ($catalogItem->deliverySubsidyItems()->exists()) {
            return back()->with('error', "Cannot delete \"{$catalogItem->name}\" — it is already used by delivery/subsidy records. Deactivate it instead.");
        }

        $name = $catalogItem->name;
        $catalogItem->delete();

        return back()->with('success', "Item name \"{$name}\" deleted.");
    }
}
