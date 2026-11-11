<?php

/**
 * ============================================================
 * RECONCILE PENDING PAYMENNT — polling backstop for lost orders
 * ============================================================
 * Same corner case as ReconcilePendingTabby/ReconcilePendingTamara,
 * except Paymennt had no backstop at all despite being the primary
 * card gateway — so a paid card order could vanish silently.
 *
 * Observed 2026-09-05 (CHK-ZU6KKIBFND, AED 15,265): Paymennt captured
 * the payment and redirected the customer back correctly, but the
 * confirmation page never fired its verify call. The webhook did not
 * cover for it either — Paymennt has delivered zero webhooks to this
 * site (Tabby and Tamara both deliver normally), so the redirect-back
 * was the ONLY path to creating the order. When it missed, the money
 * was taken with no order, no email, and nothing in the dashboard.
 * It surfaced four days later only because the customer complained.
 *
 * This scans checkouts that reached Paymennt (have a paymennt_checkout_id)
 * but never became a real Order (order_number is null), and for any that
 * Paymennt now reports PAID/AUTHORIZED/CAPTURED, creates the Order via the
 * SAME idempotent path as verifyPaymennt() and the webhook.
 *
 * Like the Tamara command it deliberately includes 'converted'-with-null-
 * order_number rows: that is the state left behind when an admin clicks
 * "Mark as recovered" on a checkout that was actually paid.
 *
 * Rows Paymennt reports as terminally dead are marked failed, which also
 * removes them from this query so the sweep prunes itself instead of
 * re-polling the same expired checkouts every couple of minutes.
 *
 * Schedule: every 2 minutes (routes/console.php).
 * Manual run: php artisan paymennt:reconcile-pending
 * ============================================================
 */

namespace App\Console\Commands;

use App\Http\Controllers\Api\PaymentController;
use App\Models\PendingCheckout;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcilePendingPaymennt extends Command
{
    protected $signature = 'paymennt:reconcile-pending {--minutes=360 : Only look back this many minutes}';

    protected $description = 'Poll Paymennt for paid-but-unconverted checkouts and create the missing orders (corner-case backstop)';

    public function handle(PaymentController $payments): int
    {
        // Any checkout that reached Paymennt (has a paymennt_checkout_id) but
        // never became a real Order (order_number is null). 'failed' rows are
        // done. This catches pending, abandoned, AND converted-with-no-order.
        $checkouts = PendingCheckout::whereNotNull('paymennt_checkout_id')
            ->whereNull('order_number')
            ->where('status', '!=', 'failed')
            ->where('created_at', '>=', now()->subMinutes((int) $this->option('minutes')))
            ->get();

        if ($checkouts->isEmpty()) {
            $this->info('No pending Paymennt checkouts to reconcile.');

            return self::SUCCESS;
        }

        $converted = 0;
        $failed = 0;

        foreach ($checkouts as $checkout) {
            $data = $payments->fetchPaymenntCheckout($checkout->paymennt_checkout_id);
            if ($data === null) {
                continue; // transient (network/API) — try again next run
            }

            $gatewayStatus = strtoupper((string) ($data['status'] ?? ''));
            $paymentStatus = $payments->mapGatewayStatus($gatewayStatus);

            if ($paymentStatus === 'paid') {
                // Never resurrect a payment the customer has already been
                // refunded for — that would create an order for money we
                // no longer hold.
                if (! empty($data['totalRefunded'])) {
                    Log::warning('Paymennt reconcile skipped a refunded checkout', [
                        'reference' => $checkout->reference,
                        'totalRefunded' => $data['totalRefunded'],
                    ]);

                    continue;
                }

                // Idempotent — same conversion path as verifyPaymennt()/webhook.
                // convertCheckoutToOrder() re-deducts stock for an abandoned or
                // failed checkout, so a late payment cannot oversell the item.
                $order = $payments->convertCheckoutToOrder(
                    $checkout->fresh(),
                    'paymennt',
                    $checkout->paymennt_checkout_id,
                    $data['usedPaymentMethod'] ?? null,
                );

                $converted++;
                $this->info("Converted {$checkout->reference} → {$order->order_number} ({$gatewayStatus})");
                Log::warning('Paymennt reconcile recovered a lost order', [
                    'reference' => $checkout->reference,
                    'order' => $order->order_number,
                    'gatewayStatus' => $gatewayStatus,
                    'previous_status' => $checkout->status,
                ]);
            } elseif ($paymentStatus === 'failed') {
                // No-op unless the row is still 'pending' (markCheckoutFailed
                // guards on that), which is what restores the reserved stock.
                $payments->markCheckoutFailed($checkout);
                $failed++;
            }
            // else still 'pending' — leave it for the next run.
        }

        $this->info("Reconcile done: {$converted} converted, {$failed} failed.");

        return self::SUCCESS;
    }
}
