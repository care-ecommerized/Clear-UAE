<?php

/**
 * ============================================================
 * RECONCILE PENDING TAMARA — polling backstop for lost orders
 * ============================================================
 * Same corner case as ReconcilePendingTabby: the customer pays on
 * Tamara's hosted page but the redirect-back verify never runs AND
 * the webhook doesn't reach us, so the order is never created — no
 * order, no confirmation email, nothing in the dashboard. Tamara had
 * no polling backstop (unlike Tabby), so these were only found when a
 * customer complained (see the 2026-08 Alanoud recovery).
 *
 * This scans checkouts that reached Tamara (have a tamara_order_id) but
 * were never turned into a real Order (order_number is null), and for
 * any that Tamara now reports paid/captured, creates the Order via the
 * SAME idempotent path as verifyTamara() and the webhook.
 *
 * It deliberately includes 'converted'-with-null-order_number rows too:
 * that is the state left behind when an admin clicks "Mark as recovered"
 * on a checkout that was actually paid on Tamara — this heals it.
 *
 * Schedule: every 2 minutes (routes/console.php).
 * Manual run: php artisan tamara:reconcile-pending
 * ============================================================
 */

namespace App\Console\Commands;

use App\Http\Controllers\Api\PaymentController;
use App\Models\PendingCheckout;
use App\Services\TamaraService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcilePendingTamara extends Command
{
    protected $signature = 'tamara:reconcile-pending {--minutes=360 : Only look back this many minutes}';

    protected $description = 'Poll Tamara for paid-but-unconverted checkouts and create the missing orders (corner-case backstop)';

    public function handle(TamaraService $tamara, PaymentController $payments): int
    {
        // Any checkout that reached Tamara (has a tamara_order_id) but never
        // became a real Order (order_number is null). 'failed' rows are done.
        // This catches pending, abandoned, AND converted-with-no-order (the
        // "Mark as recovered" footgun) — all of which can hide a paid order.
        $checkouts = PendingCheckout::whereNotNull('tamara_order_id')
            ->whereNull('order_number')
            ->where('status', '!=', 'failed')
            ->where('created_at', '>=', now()->subMinutes((int) $this->option('minutes')))
            ->get();

        if ($checkouts->isEmpty()) {
            $this->info('No pending Tamara checkouts to reconcile.');

            return self::SUCCESS;
        }

        $converted = 0;
        $failed = 0;

        foreach ($checkouts as $checkout) {
            $gatewayStatus = $tamara->fetchStatus($checkout->tamara_order_id);
            if ($gatewayStatus === null) {
                continue; // transient — try again next run
            }

            $paymentStatus = $tamara->mapStatus($gatewayStatus);

            if ($paymentStatus === 'paid') {
                // Idempotent — same conversion path as verifyTamara()/webhook.
                $order = $payments->convertCheckoutToOrder($checkout->fresh(), 'tamara', $checkout->tamara_order_id);

                $converted++;
                $this->info("Converted {$checkout->reference} → {$order->order_number} ({$gatewayStatus})");
                Log::warning('Tamara reconcile recovered a lost order', [
                    'reference' => $checkout->reference,
                    'order' => $order->order_number,
                    'gatewayStatus' => $gatewayStatus,
                    'previous_status' => $checkout->status,
                ]);
            } elseif ($paymentStatus === 'failed') {
                $payments->markCheckoutFailed($checkout);
                $failed++;
            }
            // else still 'pending' — leave it for the next run.
        }

        $this->info("Reconcile done: {$converted} converted, {$failed} failed.");

        return self::SUCCESS;
    }
}
