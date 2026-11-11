<?php

/**
 * ============================================================
 * Product.php — Watch Products
 * ============================================================
 * What this file does:
 *   Represents a single watch product in the store. Holds all
 *   product info: name, price, images, description, stock, etc.
 *   Each product belongs to a category and a brand, and can
 *   have multiple variations (sizes) and collections.
 *
 * Database table: products
 * ============================================================
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name', 'slug', 'name_ar', 'description', 'description_ar',
        'short_description', 'short_description_ar', 'price', 'original_price',
        'sku', 'image_url', 'images', 'video_url', 'badge', 'size',
        'notes_top', 'notes_middle', 'notes_base', 'stock_quantity',
        'category_id', 'brand_id', 'is_active', 'is_featured', 'sort_order',
        'published_at', 'inquiry_only',
    ];

    // Tell Laravel how to handle special field types
    protected $casts = [
        'images' => 'array',   // JSON string in DB becomes a PHP array
        'price' => 'decimal:2',
        'original_price' => 'decimal:2',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'inquiry_only' => 'boolean',
        'published_at' => 'datetime',
    ];

    /**
     * Public-facing scope: only products that are active AND either have
     * no scheduled publish date OR their publish moment has already
     * passed. Used by every customer-facing query (catalog, search,
     * sitemap, related products) so admins can stage upcoming watches
     * without leaking them early.
     *
     * Out-of-stock products (stock_quantity = 0) are visible but not purchasable.
     */
    public function scopeVisibleToCustomers(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(function (Builder $q) {
                $q->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            });
    }

    /**
     * Hides products belonging to any category flagged `exclude_from_shop`.
     *
     * Deliberately NOT folded into scopeVisibleToCustomers(): that scope also
     * guards the single-product endpoint, and an excluded category still has
     * its own browsable page, so its product pages must keep resolving. This
     * scope is therefore applied only to *browsing* surfaces — the catalogue
     * listing, search, related products and the sitemap — never to show().
     *
     * A product is hidden if ANY of its categories is excluded, so a watch
     * cannot leak back into the shop by also sitting in a visible category.
     * Matching on the pivot is sufficient: it is maintained as a superset of
     * products.category_id (see ProductController::syncCategoryMemberships()).
     */
    public function scopeExcludingHiddenCategories(Builder $query): Builder
    {
        return $query->whereDoesntHave('categoryMemberships', function (Builder $q) {
            $q->where('exclude_from_shop', true);
        });
    }

    /** True if this row is staged for a future publish moment. */
    public function isScheduled(): bool
    {
        return $this->published_at !== null && $this->published_at->isFuture();
    }

    // The category this product belongs to (e.g., "Men's Watches")
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    // The brand this product belongs to (e.g., "Rolex")
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    // The different size/color variants of this product
    public function variations(): HasMany
    {
        return $this->hasMany(ProductVariation::class);
    }

    // The collections this product appears in (many-to-many relationship)
    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class, 'product_collections');
    }

    // All categories this product belongs to via the product_categories pivot
    // (many-to-many — a product can sit in "Gold toned" AND "Branded" at once).
    // The single belongsTo `category()` above is still used as the "primary"
    // category for display purposes; this relation is what the customer-facing
    // listing filter uses.
    public function categoryMemberships(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'product_categories');
    }
}
