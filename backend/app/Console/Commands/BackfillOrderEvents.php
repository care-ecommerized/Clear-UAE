<?php

/**
 * ============================================================
 * BACKFILL ORDER EVENTS — seed timelines for existing orders
 * ============================================================
 * Orders created before the timeline feature have no events.
 * This seeds a basic history (placed / paid / status / refund)
 * from each order's stored fields + timestamps, so their
 * timeline isn't blank. New orders log full detail automatically.
 *
 * Idempotent: only touches orders that currently have NO events,
 * so it's safe to run more than once.
 *
 *   php artisan orders:backfill-events
 * ============================================================
 */

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackfillOrderEvents extends Command
{
    protected $signature = 'orders:backfill-events';

    protected $description = 'Seed basic timeline events (placed/paid/status/refund) for orders that have none';

    public function handle(): int
    {
        $orders = Order::doesntHave('events')->get();
        $this->info("Orders without a timeline: {$orders->count()}");

        $rows = [];
        foreach ($orders as $o) {
            $placedAt = $o->created_at ?? now();
            $updatedAt = $o->updated_at ?? $placedAt;

            $rows[] = $this->row($o->id, 'placed', 'Order placed', $o->customer_name ? "Placed by {$o->customer_name}" : null, 'Customer', $placedAt);

            // 'pending_refund' means it WAS paid (the later refund just failed/awaits).
            if (in_array($o->payment_status, ['paid', 'refunded', 'pending_refund'], true)) {
                $brand = $o->payment_brand ?: ucfirst($o->payment_method ?: 'online');
                $rows[] = $this->row($o->id, 'payment', 'Payment confirmed', 'Paid via '.$brand, 'System', $placedAt);
            }

            // Anything past the initial paid state ('confirmed') is an admin action.
            if (! in_array($o->status, ['pending', 'confirmed'], true)) {
                $isCancel = $o->status === 'cancelled';
                $rows[] = $this->row(
                    $o->id,
                    'status',
                    $isCancel ? 'Order cancelled' : 'Status changed to '.ucfirst((string) $o->status),
                    null,
                    'Admin',
                    $updatedAt,
                );
            }

            if (in_array($o->payment_status, ['refunded', 'pending_refund'], true)) {
                $title = $o->payment_status === 'pending_refund' ? 'Refund pending' : 'Payment refunded';
                $rows[] = $this->row($o->id, 'refund', $title, null, 'Admin', $updatedAt);
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('order_events')->insert($chunk);
        }

        $this->info('Inserted '.count($rows).' event(s).');

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function row(string $orderId, string $type, string $title, ?string $description, string $actor, mixed $when): array
    {
        return [
            'id' => (string) Str::uuid(),
            'order_id' => $orderId,
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'actor' => $actor,
            'meta' => null,
            'created_at' => $when,
            'updated_at' => $when,
        ];
    }
}
