<?php

/**
 * ============================================================
 * Category.php — Product Categories
 * ============================================================
 * What this file does:
 *   Represents a product category (e.g., "Men's Watches",
 *   "Women's Watches"). Categories can be nested — a category
 *   can have a parent category and child sub-categories.
 *   Each category has many products.
 *
 * Database table: categories
 * ============================================================
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name', 'slug', 'name_ar', 'description', 'description_ar',
        'image_url', 'parent_id', 'is_active', 'exclude_from_shop', 'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'exclude_from_shop' => 'boolean',
    ];

    // The parent category (for sub-categories)
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    // Sub-categories under this category
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    // All products that have this as their PRIMARY category (single belongsTo).
    // Kept for legacy callers; the pivot relation below is the source of truth
    // for storefront category browsing.
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    // All products that belong to this category via the product_categories pivot
    // (many-to-many). A product can show up under multiple categories at once.
    public function productMembers(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_categories');
    }
}
