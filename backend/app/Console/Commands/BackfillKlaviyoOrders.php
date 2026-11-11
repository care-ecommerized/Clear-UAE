<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Klaviyo;
use Illuminate\Console\Command;

/**
 * ============================================================
 * klaviyo:backfill-orders — send historical orders to Klaviyo
 * ============================================================
 * Replays existing orders into Klaviyo so revenue reporting and
 * purchase-based segments cover the store's whole history, not
 * only orders placed after the integration went live.
 *
 * Safe to re-run: every event carries Klaviyo's `unique_id`
 * (the order number, and order-number + line id per item), which
 * is its idempotency key — a second pass updates rather than
 * duplicates, so revenue cannot be double counted.
 *
 * Events keep their ORIGINAL timestamps (the order's created_at),
 * so Klaviyo's revenue charts show the real trading history
 * rather than a spike on the day of the import.
 *
 * ⚠️ Check for live flows before running. A Placed Order event
 * can trigger a post-purchase flow, and a backfill would fire it
 * for every historical customer at once. Verify with
 * `klaviyo api GET /api/flows/` — an empty list is safe.
 *
 * This creates/updates PROFILES but grants NO marketing consent:
 * having bought something is not the same as subscribing, and
 * these customers never opted in. They will not become mailable
 * as a result of this import.
 *
 * Usage:
 *   php artisan klaviyo:backfill-orders --dry-run
 *   php artisan klaviyo:backfill-orders
 *   php artisan klaviyo:backfill-orders --since=2026-08-01 --limit=25
 * ============================================================
 */
class BackfillKlaviyoOrders extends Command
{
    protected $signature = 'klaviyo:backfill-orders
                            {--dry-run : List what would be sent without calling Klaviyo}
                            {--limit= : Only process this many orders (oldest first)}
                            {--since= : Only orders created on/after this date (Y-m-d)}
                            {--sleep=200 : Milliseconds to pause between orders}';

    protected $description = 'Replay historical orders into Klaviyo (idempotent; keeps original timestamps)';

    public function handle(Klaviyo $klaviyo): int
    {
        if (! $klaviyo->enabled()) {
            $this->error('Klaviyo is not configured (KLAVIYO_PRIVATE_KEY missing) — nothing to do.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $sleepMs = (int) $this->option('sleep');

        $query = Order::with(['items.product.brand', 'items.product.category'])
            // Oldest first so a partial run leaves a contiguous history rather
            // than a hole in the middle.
            ->oldest();

        if ($since = $this->option('since')) {
            $query->where('created_at', '>=', $since);
        }
        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('No orders matched.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '').'Backfilling '.$total.' orders into Klaviyo');
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $sent = 0;
        $skipped = 0;
        $cancelled = 0;
        $revenue = 0.0;

        foreach ($query->cursor() as $order) {
            // Without an email Klaviyo has nothing to attach the event to.
            if (empty($order->customer_email)) {
                $skipped++;
                $bar->advance();

                continue;
            }

            if (! $dryRun) {
                try {
                    $klaviyo->trackPlacedOrder($order);

                    // Mirror the live behaviour: a cancelled order also gets its
                    // cancellation event, so Klaviyo's view matches ours rather
                    // than showing every historical order as still good.
                    if ($order->status === 'cancelled') {
                        $klaviyo->trackOrderCancelled($order);
                        $cancelled++;
                    }
                } catch (\Throwable $e) {
                    $this->newLine();
                    $this->warn("  {$order->order_number}: {$e->getMessage()}");
                    $skipped++;
                    $bar->advance();

                    continue;
                }

                // Klaviyo's events endpoint allows a generous burst, but this
                // runs on shared hosting against a live account — pace it.
                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            } elseif ($order->status === 'cancelled') {
                $cancelled++;
            }

            $sent++;
            $revenue += (float) $order->total;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Orders sent      : '.$sent);
        $this->info('Cancellations    : '.$cancelled);
        $this->info('Skipped          : '.$skipped);
        $this->info('Revenue replayed : '.number_format($revenue, 2).' '.config('services.klaviyo.currency'));

        if ($dryRun) {
            $this->comment('Dry run — nothing was sent to Klaviyo.');
        } else {
            $this->comment('Klaviyo processes events asynchronously; allow a few minutes before checking the dashboard.');
        }

        return self::SUCCESS;
    }
}
