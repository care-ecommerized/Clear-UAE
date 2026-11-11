<?php

/**
 * ============================================================
 * SYNC PAYMENT BRANDS — backfill orders.payment_brand
 * ============================================================
 * Sets each order's payment_brand ('Apple Pay' / 'Card' / 'Tabby'
 * / 'Tamara') and rewrites its 'payment' timeline event to match.
 * For Paymennt orders it reads `usedPaymentMethod` from the gateway;
 * BNPL orders are labelled by gateway without an API call.
 *
 * Needs the LIVE Paymennt API to be reachable for Paymennt orders
 * (run on production). Idempotent: skips orders that already have a
 * brand unless --all is passed.
 *
 *   php artisan orders:sync-payment-brands
 *   php artisan orders:sync-payment-brands --all
 * ============================================================
 */

namespace App\Console\Commands;

use App\Http\Controllers\Api\PaymentController;
use App\Models\Order;
use Illuminate\Console\Command;

class SyncPaymentBrands extends Command
{
    protected $signature = 'orders:sync-payment-brands {--all : re-sync orders that already have a brand}';

    protected $description = 'Backfill orders.payment_brand from the gateway and update the payment timeline event';

    public function handle(PaymentController $pc): int
    {
        $query = Order::query();
        if (! $this->option('all')) {
            $query->whereNull('payment_brand');
        }
        $orders = $query->get();
        $this->info("Orders to check: {$orders->count()}");

        $updated = 0;
        foreach ($orders as $order) {
            if ($order->payment_method === 'paymennt' && $order->payment_reference) {
                $data = $pc->fetchPaymenntCheckout($order->payment_reference);
                if ($data === null) {
                    $this->warn("  skip {$order->order_number} (Paymennt unreachable)");
                    continue;
                }
                $brand = $pc->paymentLabel('paymennt', $data['usedPaymentMethod'] ?? null);
            } elseif (in_array($order->payment_method, ['tabby', 'tamara'], true)) {
                $brand = $pc->paymentLabel((string) $order->payment_method);
            } else {
                continue; // legacy / COD / unknown — leave as-is
            }

            $order->update(['payment_brand' => $brand]);

            // Keep the existing 'payment' timeline entry in sync.
            $event = $order->events()->where('type', 'payment')->first();
            if ($event) {
                $event->update([
                    'description' => 'Paid via '.$brand.($order->payment_reference ? " · ref {$order->payment_reference}" : ''),
                ]);
            }

            $updated++;
            $this->line("  {$order->order_number} → {$brand}");
        }

        $this->info("Updated {$updated} order(s).");

        return self::SUCCESS;
    }
}
