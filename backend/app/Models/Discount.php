<?php

/**
 * ============================================================
 * Discount.php — Discount Codes
 * ============================================================
 * What this file does:
 *   Represents a discount/coupon code customers can apply at
 *   checkout. Supports percentage or fixed-amount discounts,
 *   with optional start/end dates and usage limits.
 *
 * Database table: discounts
 * ============================================================
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'code', 'type', 'value', 'max_discount_amount', 'min_order_amount', 'max_uses',
        'used_count', 'start_date', 'end_date', 'is_active',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'max_discount_amount' => 'decimal:2',
        'min_order_amount' => 'decimal:2',
        'is_active' => 'boolean',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];

    // Query scope to find only currently valid discounts
    // (active, within date range, and not used up)
    public function scopeValid($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('start_date')->orWhere('start_date', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('end_date')->orWhere('end_date', '>', now());
            })
            ->where(function ($q) {
                $q->whereNull('max_uses')->orWhereColumn('used_count', '<', 'max_uses');
            });
    }
}
