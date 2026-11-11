<?php

/**
 * ============================================================
 * EXPIRE ABANDONED CHECKOUTS — Cleanup Job
 * ============================================================
 * What this command does:
 *   Finds pending_checkouts whose reservation window has passed
 *   without payment, releases the reserved stock, and marks them as
 *   "abandoned" so they show up in the admin's Abandoned Checkouts
 *   list (similar to Shopify).
 *
 * Window: config('app.checkout_reservation_minutes'), default 10.
 *
 * A customer who pays AFTER expiry is still honoured — the gateway
 * webhook converts the checkout to an order and re-deducts the stock
 * this command handed back (see PaymentController::convertCheckoutToOrder).
 *
 * Schedule: every minute (configured in routes/console.php).
 * Manual run: php artisan checkouts:expire-abandoned
 * ============================================================
 */

namespace App\Console\Commands;

use App\Services\CheckoutExpirer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireAbandonedCheckouts extends Command
{
    protected $signature = 'checkouts:expire-abandoned';

    protected $description = 'Mark expired pending checkouts as abandoned and restore reserved stock';

    public function handle(CheckoutExpirer $expirer): int
    {
        // The work lives in CheckoutExpirer so the request paths that also
        // care (creating a checkout, loading the admin list) can sweep too —
        // the cron here has been observed to skip for hours on this host.
        $count = $expirer->sweep();

        $this->info($count === 0
            ? 'No expired checkouts.'
            : "Abandoned {$count} checkout(s); stock restored.");

        return self::SUCCESS;
    }
}
