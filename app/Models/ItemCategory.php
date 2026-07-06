<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class ItemCategory extends Model
{
    protected $fillable = ['key', 'label', 'account_code', 'is_active', 'sort_order'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    /**
     * Load all active categories as an ordered collection.
     * Cached for 60 seconds to avoid repeated DB hits.
     * Cache is busted whenever a category is saved or deleted.
     */
    public static function allActive(): \Illuminate\Database\Eloquent\Collection
    {
        return Cache::remember('item_categories_all', 60, function () {
            return static::where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('key')
                ->get();
        });
    }

    /**
     * Return categories in the same array shape as the old Item::CATEGORIES constant:
     *   ['key' => ['label' => '...', 'account_code' => '...']]
     * Drop-in replacement for every place that reads Item::CATEGORIES.
     */
    public static function asArray(): array
    {
        return static::allActive()
            ->mapWithKeys(fn ($cat) => [
                $cat->key => [
                    'label'        => $cat->label,
                    'account_code' => $cat->account_code,
                ],
            ])
            ->all();
    }

    public static function clearCache(): void
    {
        Cache::forget('item_categories_all');
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::clearCache());
        static::deleted(fn () => static::clearCache());
    }

    public function items()
    {
        return $this->hasMany(Item::class, 'category', 'key');
    }
}
