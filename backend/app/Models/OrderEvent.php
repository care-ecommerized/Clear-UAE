<?php

/**
 * ============================================================
 * OrderEvent.php — One entry in an order's activity timeline
 * ============================================================
 * Immutable log rows shown newest-first in the admin order view
 * (like Shopify's order timeline). Created automatically when an
 * order is placed/paid/shipped/cancelled/refunded, and manually
 * when a staff member leaves a comment.
 *
 * Database table: order_events
 * ============================================================
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderEvent extends Model
{
    use HasUuids;

    protected $fillable = ['order_id', 'type', 'title', 'description', 'actor', 'meta'];

    protected $casts = [
        'meta' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Convenience factory for logging an event.
     *
     * @param  array<string,mixed>  $meta
     */
    public static function log(string $orderId, string $type, string $title, ?string $description = null, string $actor = 'System', array $meta = []): self
    {
        return static::create([
            'order_id' => $orderId,
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'actor' => $actor,
            'meta' => $meta ?: null,
        ]);
    }
}
