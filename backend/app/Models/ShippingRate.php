<?php
/**
 * ============================================================
 * ShippingRate.php — Shipping Price Rules
 * ============================================================
 * What this file does:
 *   Defines how much shipping costs based on the destination
 *   country, delivery type, and package weight. The admin
 *   can set different rates for different countries and
 *   delivery speeds (e.g., standard vs express).
 *
 * Database table: shipping_rates
 * ============================================================
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShippingRate extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'origin_country', 'destination_country', 'delivery_type',
        'cost', 'currency', 'min_weight', 'max_weight', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'is_active' => 'boolean',
    ];
}
