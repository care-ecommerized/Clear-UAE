<?php

/**
 * ============================================================
 * Order.php — Customer Orders
 * ============================================================
 * What this file does:
 *   Represents a customer's order. Stores the order number,
 *   customer info, shipping address, payment method, totals,
 *   and status (pending, processing, shipped, delivered, etc.).
 *   Each order contains one or more order items.
 *
 * Database table: orders
 * ============================================================
 */

namespace App\Models;

use App\Support\Brand;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'order_number', 'user_id', 'customer_email', 'customer_name', 'customer_phone',
        'subtotal', 'discount', 'shipping_cost', 'shipping_method', 'gift_fee', 'gift_message', 'total',
        'gateway_currency', 'gateway_amount',
        'status', 'payment_method', 'payment_status', 'payment_reference', 'payment_brand',
        'shipping_address', 'shipping_city', 'shipping_state',
        'shipping_country', 'shipping_zip', 'tracking_number',
        'shipping_label_url', 'shipping_provider', 'notes',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'gift_fee' => 'decimal:2',
        'total' => 'decimal:2',
        'gateway_amount' => 'decimal:2',
    ];

    // All the products/items in this order
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    // Activity timeline entries (newest first), shown in the admin order view.
    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->latest();
    }

    /**
     * Append an entry to this order's timeline.
     *
     * @param  array<string,mixed>  $meta
     */
    public function logEvent(string $type, string $title, ?string $description = null, string $actor = 'System', array $meta = []): OrderEvent
    {
        return OrderEvent::log($this->id, $type, $title, $description, $actor, $meta);
    }

    // The customer who placed this order (if they were logged in)
    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    /**
     * Shipping option + estimated delivery time for the order emails.
     *
     * Label/days come from Admin → Settings delivery types, matched on
     * shipping_method (the delivery type chosen at checkout). Orders placed
     * before shipping_method existed fall back to "standard" inside the UAE.
     *
     * @return array{label: string, days: ?string, domestic: bool}|null
     */
    public function shippingSummary(): ?array
    {
        $country = $this->shipping_country;
        $domestic = $country === null || $country === ''
            || \App\Support\CountryCode::tryToIso2($country) === 'AE';

        $method = $this->shipping_method ?: ($domestic ? 'standard' : null);
        if (! $method) {
            return null;
        }

        $type = DeliveryType::where('name', $method)->first();
        if (! $type) {
            return null;
        }

        $days = trim((string) $type->estimated_days) ?: null;

        return [
            'label' => (string) ($type->label ?: $type->name),
            'days' => $days,
            'domestic' => $domestic,
        ];
    }

    // Auto-generate sequential order number from the store's configured
    // prefix (Brand::orderPrefix(), e.g. ORD-1001). The sequence is per
    // prefix: changing the prefix in the admin restarts it at 1001.
    //
    // The max is computed in PHP, not with an SQL CAST ordering: when the new
    // prefix is a leading part of an old one (ORD- -> ORD), the LIKE still
    // matches the old rows and MySQL casts '-1050' AS UNSIGNED to ~1.8e19, so
    // an old row sorted first and every new order got the same number. Only
    // numbers that match the current prefix exactly count.
    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            if (empty($order->order_number)) {
                $prefix = Brand::orderPrefix();
                $like = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix).'%';
                $re = '/^'.preg_quote($prefix, '/').'(\d+)$/';
                $lastNumber = 1000;
                $numbers = Order::whereRaw("order_number LIKE ? ESCAPE '!'", [$like])
                    ->pluck('order_number');
                foreach ($numbers as $num) {
                    if (preg_match($re, (string) $num, $m)) {
                        $lastNumber = max($lastNumber, (int) $m[1]);
                    }
                }
                $order->order_number = $prefix.($lastNumber + 1);
            }
        });
    }
}
