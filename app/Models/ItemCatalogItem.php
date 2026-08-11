<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A pre-defined item name configured under an ItemCategory. Each entry has its
 * own account code. Used as the searchable source for item descriptions in the
 * New Delivery/Subsidy form.
 */
class ItemCatalogItem extends Model
{
    protected $fillable = ['item_category_id', 'name', 'account_code', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function category()
    {
        return $this->belongsTo(ItemCategory::class, 'item_category_id');
    }

    public function deliverySubsidyItems()
    {
        return $this->hasMany(DeliverySubsidyItem::class, 'catalog_item_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
