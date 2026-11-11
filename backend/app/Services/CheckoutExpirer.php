<?php

/**
 * ============================================================
 * CheckoutExpirer — Release stock from expired checkouts
 * ============================================================
 * A pending checkout reserves stock. Once its window passes
 * without payment the reservation must be handed back, and the
 * checkout marked "abandoned" for the admin list.
 *
 * This lives in a service rather than the console command because
 * the cron on shared cPanel hosting does not fire reliably — it
 * has been observed to skip for hours — and a checkout that stays
 * "pending" holds stock a real customer could be buying. Callers
 * that care (a new checkout, the admin list) sweep on demand, so
 * correctness no longer depends on the scheduler alone.
 *
 * Cheap to call: one indexed query that almost always finds
 * nothing and returns immediately.
 * ============================================================
 */

namespace App\Services;

use App\Models\PendingCheckout;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckoutExpirer
{
    /**
     * Mark every overdue pending checkout as abandoned and restore its stock.
     *
     * @return int How many checkouts were expired.
     */
    public function sweep(): int
    {
        $expired = PendingCheckout::query()
            ->where('status', 'pending')
            ->where('expires_at', '<', now())
            ->get();

        if ($expired->isEmpty()) {
            return 0;
        }

        $count = 0;
        $notify = [];

        foreach ($expired as $checkout) {
            $released = DB::transaction(function () use ($checkout) {
                // Re-read under a lock: a webhook may be converting this very
                // checkout to a paid order right now.
                $fresh = PendingCheckout::where('id', $checkout->id)->lockForUpdate()->first();
                if (! $fresh || $fresh->status !== 'pending') {
                    return null;
                }

                foreach ($fresh->items as $item) {
                    if (! empty($item['product_id'])) {
                        Product::where('id', $item['product_id'])
                            ->whereNotNull('stock_quantity')
                            ->increment('stock_quantity', (int) ($item['quantity'] ?? 1));
                    }
                }

                $fresh->update(['status' => 'abandoned']);

                return $fresh;
            });

            if ($released) {
                $count++;
                $notify[] = $released;
            }
        }

        // Notify AFTER the transactions commit, and never let a notification
        // failure matter. This used to run INSIDE the transaction: when the
        // admin_notifications table was missing, the insert threw, the whole
        // transaction rolled back, and the checkout stayed pending with its
        // stock still reserved — the sweep silently undid itself.
        foreach ($notify as $checkout) {
            try {
                AdminNotifier::checkoutAbandoned($checkout);
            } catch (\Throwable $e) {
                Log::warning('CheckoutExpirer: abandoned-checkout notification failed', [
                    'reference' => $checkout->reference,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($count > 0) {
            Log::info("CheckoutExpirer: abandoned {$count} checkout(s); stock restored.");
        }

        return $count;
    }

    /**
     * Release every still-pending checkout this customer started earlier.
     *
     * A customer who leaves Tamara/Tabby/Paymennt without completing (back
     * button, closing the in-app browser) never hits our cancel redirect, so
     * their earlier checkout keeps the one-of-a-kind watch reserved for up to
     * 10 minutes — and every retry was refused "out of stock" by their OWN
     * reservation (2026-10-03: 15 refusals in 9 minutes, then rate-limited).
     * Starting a new checkout supersedes the old one. If the old one is paid
     * after all, convertCheckoutToOrder() re-deducts the stock.
     *
     * @return int How many checkouts were released.
     */
    public function releaseSupersededBy(string $email): int
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return 0;
        }

        $pending = PendingCheckout::query()
            ->where('status', 'pending')
            ->whereRaw('LOWER(customer_email) = ?', [$email])
            ->get();

        $count = 0;
        foreach ($pending as $checkout) {
            $released = DB::transaction(function () use ($checkout) {
                $fresh = PendingCheckout::where('id', $checkout->id)->lockForUpdate()->first();
                if (! $fresh || $fresh->status !== 'pending') {
                    return false;
                }
                foreach ($fresh->items as $item) {
                    if (! empty($item['product_id'])) {
                        Product::where('id', $item['product_id'])
                            ->whereNotNull('stock_quantity')
                            ->increment('stock_quantity', (int) ($item['quantity'] ?? 1));
                    }
                }
                $fresh->update(['status' => 'abandoned']);

                return true;
            });
            if ($released) {
                $count++;
                Log::info('CheckoutExpirer: released a superseded checkout', ['reference' => $checkout->reference]);
            }
        }

        return $count;
    }

    /** releaseSupersededBy() that never disturbs the caller. */
    public function releaseSupersededQuietly(string $email): int
    {
        try {
            return $this->releaseSupersededBy($email);
        } catch (\Throwable $e) {
            Log::warning('CheckoutExpirer: releasing superseded checkouts failed', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Sweep without ever disturbing the caller.
     *
     * Used on request paths (creating a checkout, loading the admin list)
     * where freeing stock is desirable but must never turn into a customer
     * seeing an error because a background tidy-up failed.
     */
    public function sweepQuietly(): int
    {
        try {
            return $this->sweep();
        } catch (\Throwable $e) {
            Log::warning('CheckoutExpirer: opportunistic sweep failed', ['error' => $e->getMessage()]);

            return 0;
        }
    }
}
