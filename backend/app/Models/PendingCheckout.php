<?php

/**
 * ============================================================
 * PendingCheckout.php — Shopify-style Checkout Stage
 * ============================================================
 * What this model represents:
 *   A customer's checkout attempt while they're at the payment
 *   gateway, BEFORE any Order row exists. We only create a real
 *   Order when payment is confirmed PAID (via webhook or verify).
 *
 *   This keeps unpaid/abandoned checkouts out of the Orders table —
 *   they sit here as "abandoned checkouts" instead, exactly like
 *   Shopify does it.
 *
 * Database table: pending_checkouts
 * ============================================================
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PendingCheckout extends Model
{
    use HasUuids;

    protected $fillable = [
        'reference', 'paymennt_checkout_id', 'paymennt_redirect_url',
        'tabby_payment_id', 'tamara_order_id', 'stripe_session_id',
        'status', 'is_draft', 'order_number',
        'customer_email', 'customer_name', 'customer_phone',
        'shipping_address', 'shipping_city', 'shipping_state',
        'shipping_country', 'shipping_zip',
        'subtotal', 'discount', 'shipping_cost', 'shipping_method', 'gift_fee', 'gift_message', 'total',
        'gateway_currency', 'gateway_amount',
        'discount_id', 'items', 'notes', 'expires_at',
        'recovery_email_sent_at', 'recovery_email_count', 'recovered_at',
    ];

    protected $casts = [
        'items' => 'array',
        'is_draft' => 'boolean',
        'expires_at' => 'datetime',
        'recovery_email_sent_at' => 'datetime',
        'recovered_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'gift_fee' => 'decimal:2',
        'total' => 'decimal:2',
        'gateway_amount' => 'decimal:2',
    ];

    /**
     * Serialised alongside the columns so the admin abandoned-checkouts list
     * can show which gateway the customer tried.
     */
    protected $appends = ['payment_method'];

    /**
     * Which gateway the customer was sent to, derived from whichever
     * gateway-specific id got filled in.
     *
     * There is no payment_method column on pending_checkouts: the row is
     * created by POST /checkouts BEFORE a gateway is chosen, and the id is
     * written later when the customer is redirected. So the presence of an id
     * IS the record of which method was attempted.
     *
     * null means the customer never reached a payment page — they abandoned at
     * the address/shipping step. That is a genuinely different failure from
     * "tried Tabby and it declined", so it must not be flattened into a
     * default gateway name.
     */
    public function getPaymentMethodAttribute(): ?string
    {
        return match (true) {
            filled($this->paymennt_checkout_id) => 'paymennt',
            filled($this->tabby_payment_id) => 'tabby',
            filled($this->tamara_order_id) => 'tamara',
            filled($this->stripe_session_id) => 'stripe',
            default => null,
        };
    }
}
