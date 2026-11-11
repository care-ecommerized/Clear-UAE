<?php
/**
 * ============================================================
 * ProductVariation.php — Product Variants (Size, Color, etc.)
 * ============================================================
 * What this file does:
 *   Represents a specific variant of a product, such as a
 *   different size or color. Each variation can have its own
 *   price, SKU, image, and stock quantity.
 *
 * Database table: product_variations
 * ============================================================
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariation extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'product_id', 'variation_type', 'variation_value',
        'price', 'sku', 'image_url', 'stock_quantity', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    // The parent product this variation belongs to
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
