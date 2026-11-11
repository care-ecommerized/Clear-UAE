<?php

/**
 * ============================================================
 * RECONCILE PENDING TABBY — polling backstop for the corner case
 * ============================================================
 * Tabby's "corner case": the customer authorizes on Tabby's hosted
 * page but never returns to our site (closed browser / lost network),
 * so the redirect-back verify never runs. Webhooks are the primary
 * safety net, but Tabby explicitly recommends polling as a backup:
 * "call the Retrieve Request every couple of minutes until the payment
 * reaches AUTHORIZED, REJECTED, or EXPIRED."
 *
 * This command scans pending Tabby checkouts and, for any that Tabby
 * now reports AUTHORIZED/CLOSED, creates the Order and captures the
 * payment — the SAME idempotent path as verifyTabby() and the webhook,
 * so whichever fires first wins and the rest no-op.
 *
 * Schedule: every 2 minutes (routes/console.php).
 * Manual run: php artisan tabby:reconcile-pending
 * ============================================================
 */

namespace App\Console\Commands;

use App\Http\Controllers\Api\PaymentController;
use App\Models\PendingCheckout;
use App\Services\TabbyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcilePendingTabby extends Command
{
    protected $signature = 'tabby:reconcile-pending {--minutes=180 : Only look back this many minutes}';

    protected $description = 'Poll Tabby for authorized-but-unconverted checkouts and capture them (corner-case backstop)';

    public function handle(TabbyService $tabby, PaymentController $payments): int
    {
        // Only recent, still-pending checkouts that actually reached Tabby
        // (have a tabby_payment_id). Older ones are handled by the abandoned
        // cleanup; Tabby sessions expire ~30 min anyway, but we look back a
        // bit further to be safe.
        $checkouts = PendingCheckout::where('status', 'pending')
            ->whereNotNull('tabby_payment_id')
            ->where('created_at', '>=', now()->subMinutes((int) $this->option('minutes')))
            ->get();

        if ($checkouts->isEmpty()) {
            $this->info('No pending Tabby checkouts to reconcile.');

            return self::SUCCESS;
        }

        $converted = 0;
        $failed = 0;

        foreach ($checkouts as $checkout) {
            // We stored the checkout SESSION id; the live status lives at
            // GET /checkout/{id} under payment.status / payment.id.
            $session = $tabby->fetchCheckout($checkout->tabby_payment_id);
            if ($session === null) {
                continue; // transient — try again next run
            }

            $gatewayStatus = strtoupper((string) ($session['payment']['status'] ?? ''));
            $realPaymentId = (string) ($session['payment']['id'] ?? '');
            $paymentStatus = $gatewayStatus !== '' ? $tabby->mapStatus($gatewayStatus) : 'pending';

            if ($paymentStatus === 'paid' && $realPaymentId !== '') {
                $checkout->update(['tabby_payment_id' => $realPaymentId]);
                $order = $payments->convertCheckoutToOrder($checkout->fresh(), 'tabby', $realPaymentId);

                try {
                    $tabby->captureAndClose($realPaymentId, (float) $order->total);
                } catch (\Throwable $e) {
                    Log::warning('Tabby reconcile: capture failed (order placed)', [
                        'reference' => $checkout->reference,
                        'paymentId' => $realPaymentId,
                        'error' => $e->getMessage(),
                    ]);
                }

                $converted++;
                $this->info("Converted {$checkout->reference} → {$order->order_number} ({$gatewayStatus})");
                Log::info('Tabby reconcile converted checkout', [
                    'reference' => $checkout->reference,
                    'order' => $order->order_number,
                    'gatewayStatus' => $gatewayStatus,
                ]);
            } elseif ($paymentStatus === 'failed') {
                $payments->markCheckoutFailed($checkout);
                $failed++;
            }
            // else still 'pending' (CREATED) — leave it for the next run.
        }

        $this->info("Reconcile done: {$converted} converted, {$failed} failed.");

        return self::SUCCESS;
    }
}
