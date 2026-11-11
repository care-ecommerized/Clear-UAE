<?php

/**
 * ============================================================
 * SYNC DELIVERY STATUS — Pull tracking events into the order
 * timeline + auto-flip Shipped → Delivered
 * ============================================================
 * For every active order with a tracking number we:
 *   1. Pull the full event history from Topex/Aramex.
 *   2. Append any NEW event to the order timeline (OrderEvent
 *      rows with type='shipping') — dedup'd by (date|description)
 *      so re-runs are idempotent.
 *   3. If the latest carrier status reads "delivered", flip the
 *      order to delivered and email the customer.
 *
 * Schedule: every 30 minutes (routes/console.php).
 * Manual run: php artisan orders:sync-delivery-status
 * ============================================================
 */

namespace App\Console\Commands;

use App\Http\Controllers\Api\ShippingController;
use App\Mail\OrderStatusUpdate;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Services\AdminNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SyncDeliveryStatus extends Command
{
    protected $signature = 'orders:sync-delivery-status';

    protected $description = 'Pull Topex/Aramex tracking events into each order timeline and auto-flip delivered orders.';

    /**
     * Substrings that indicate the parcel was delivered. Topex returns
     * "Delivered" / "Delivered to Consignee"; we match case-insensitively
     * and substring so minor wording differences don't break us.
     */
    private array $deliveredPhrases = ['delivered'];

    public function handle(ShippingController $shipping): int
    {
        // Scope: every order with a tracking number that isn't already
        // closed out. We used to only check 'shipped' orders, but that
        // missed early events (Order Placed, To be Picked Up) that admins
        // want to see on the timeline before they manually flip Shipped.
        $orders = Order::whereNotNull('tracking_number')
            ->whereNotNull('shipping_provider')
            ->whereNotIn('status', ['delivered', 'cancelled', 'refunded'])
            ->get();

        if ($orders->isEmpty()) {
            $this->info('No orders to sync.');

            return self::SUCCESS;
        }

        $checked = 0;
        $newEvents = 0;
        $flipped = 0;

        foreach ($orders as $order) {
            $checked++;

            try {
                $result = $shipping->fetchTracking($order->tracking_number, $order->shipping_provider);
            } catch (\Throwable $e) {
                Log::warning("Tracking fetch failed for {$order->order_number}: ".$e->getMessage());

                continue;
            }

            if (! $result || empty($result['updates'])) {
                continue;
            }

            $newEvents += $this->syncTimeline($order, $result['updates']);

            $latestStatus = strtolower((string) ($result['status'] ?? ''));
            if ($this->isDelivered($latestStatus) && $order->status !== 'delivered') {
                $this->markDelivered($order);
                $flipped++;
            }
        }

        $this->info("Checked {$checked} order(s); appended {$newEvents} tracking event(s); flipped {$flipped} to delivered.");

        return self::SUCCESS;
    }

    /**
     * Append any tracking events we don't already have on this order's
     * timeline. Dedup key = '<date>|<description>' stored in meta so
     * subsequent runs skip events we already logged.
     *
     * @param  array<int, array{date:string, location:string, description:string}>  $updates
     */
    private function syncTimeline(Order $order, array $updates): int
    {
        // Load every signature we've already stored for this order so we
        // can dedup in PHP and avoid N queries per event.
        $seen = OrderEvent::where('order_id', $order->id)
            ->where('type', 'shipping')
            ->whereNotNull('meta')
            ->pluck('meta')
            ->map(fn ($m) => is_array($m) ? ($m['signature'] ?? null) : null)
            ->filter()
            ->all();
        $seenSet = array_flip($seen);

        $appended = 0;

        // Topex returns newest-first; flip to chronological so the timeline
        // reads in the right order when we insert.
        foreach (array_reverse($updates) as $u) {
            $description = trim((string) ($u['description'] ?? ''));
            $date = trim((string) ($u['date'] ?? ''));
            if ($description === '') {
                continue;
            }
            $signature = $date.'|'.$description;
            if (isset($seenSet[$signature])) {
                continue;
            }

            $location = trim((string) ($u['location'] ?? ''));
            $subtitle = $location !== '' ? "{$location}".($date ? " · {$date}" : '') : ($date ?: null);

            OrderEvent::log(
                orderId: $order->id,
                type: 'shipping',
                title: $description,
                description: $subtitle,
                actor: ucfirst((string) $order->shipping_provider),
                meta: [
                    'signature' => $signature,
                    'provider' => $order->shipping_provider,
                    'tracking_number' => $order->tracking_number,
                    'raw_date' => $date,
                    'raw_location' => $location,
                ],
            );
            $seenSet[$signature] = true;
            $appended++;
        }

        return $appended;
    }

    private function markDelivered(Order $order): void
    {
        $order->status = 'delivered';
        $order->save();

        OrderEvent::log(
            orderId: $order->id,
            type: 'status',
            title: 'Status changed to Delivered',
            description: 'Marked delivered by carrier tracking',
            actor: 'System',
        );

        try {
            $order->load('items');
            Mail::to($order->customer_email)->send(new OrderStatusUpdate($order, 'delivered'));
        } catch (\Throwable $e) {
            Log::error('Failed to send delivery email for '.$order->order_number.': '.$e->getMessage());
            AdminNotifier::customerEmailFailed($order, 'Delivered update', $e->getMessage());
        }

        Log::info("Order {$order->order_number} auto-flipped to delivered.");
        $this->info("✓ {$order->order_number} → delivered");
    }

    private function isDelivered(string $status): bool
    {
        foreach ($this->deliveredPhrases as $phrase) {
            if (str_contains($status, $phrase)) {
                return true;
            }
        }

        return false;
    }
}
