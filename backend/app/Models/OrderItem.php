<?php
/**
 * ============================================================
 * OrderItem.php — Items in Each Order
 * ============================================================
 * What this file does:
 *   Represents a single product line inside an order. For
 *   example, if a customer buys 2 watches, there will be
 *   2 order items. Stores the product name, quantity,
 *   unit price, and total price for that line.
 *
 * Database table: order_items
 * ============================================================
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'order_id', 'product_id', 'product_name', 'product_image',
        'quantity', 'unit_price', 'total_price',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
    ];

    // The order this item belongs to
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    // The product that was ordered
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
