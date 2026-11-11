<?php

/**
 * ============================================================
 * console.php — Artisan Console Commands
 * ============================================================
 * What this file does:
 *   Registers custom terminal/command-line commands that you
 *   can run with "php artisan <command>". These are useful
 *   for maintenance tasks, testing, or automation that you
 *   run from the terminal (not from the browser).
 *
 * Example: php artisan inspire
 * ============================================================
 */

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Release reserved stock from abandoned checkouts. Runs every minute so the
// reservation window (config app.checkout_reservation_minutes) is honoured
// closely — on the old five-minute cadence a 30-minute window could take 35.
// withoutOverlapping so a slow sweep can't stack on the next tick.
// On cPanel, set up one cron job: * * * * * php /path/to/artisan schedule:run
// withoutOverlapping(2): the lock MUST have a short expiry. Laravel's default
// is 24 hours, so if this host kills the process mid-run (it enforces resource
// limits) the stale lock would silently block every later run for a day.
Schedule::command('checkouts:expire-abandoned')->everyMinute()->withoutOverlapping(2);

// Drain the database queue (customer/admin emails) every minute. Order emails
// are dispatched as queued closures rather than run after the HTTP response,
// because LiteSpeed/LSAPI does not reliably execute Laravel's deferred
// after-response callbacks — so those emails were silently never sent. The
// queue worker runs in the CLI context (where SMTP + the TCPDF invoice work),
// exits as soon as the queue is empty, and never overlaps a prior run.
// withoutOverlapping(2): CRITICAL — same reason as expire-abandoned above. The
// worker runs up to 55s doing SMTP + TCPDF, so it is the command most likely to
// be killed mid-run by the shared host's resource limits. With the default
// 24-hour lock a single killed run would freeze the queue for a day and every
// order email would silently stop sending (observed: ~6 confirmation emails
// piled up overnight). A 2-minute expiry (> the 55s max run) self-heals it.
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=3 --no-interaction')
    ->everyMinute()
    ->withoutOverlapping(2);

// Auto-flip shipped orders to delivered when Topex/Aramex says so.
// Polls live tracking data; emails the customer on delivery confirmation.
// withoutOverlapping(20): short expiry so a worker killed mid-run by the host
// can't leave a stale lock that freezes this for 24h (the default). See the
// queue:work note above — same failure mode across every scheduled command.
Schedule::command('orders:sync-delivery-status')->everyThirtyMinutes()->withoutOverlapping(20);

// Tabby corner-case backstop: catch payments the customer authorized on
// Tabby's page but never redirected back for (and where the webhook didn't
// arrive). Polls Tabby for pending checkouts and captures any now-AUTHORIZED.
// Idempotent with verifyTabby()/webhook. Tabby explicitly recommends this poll.
Schedule::command('tabby:reconcile-pending')->everyTwoMinutes()->withoutOverlapping(2);

// Tamara corner-case backstop: same as Tabby above — catch orders paid on
// Tamara that never converted (redirect-back + webhook both missed), and heal
// the "Mark as recovered" footgun. Idempotent with verifyTamara()/webhook.
Schedule::command('tamara:reconcile-pending')->everyTwoMinutes()->withoutOverlapping(2);

// Paymennt corner-case backstop: the same failure as the two above, and the
// one gateway that had no safety net despite taking most of the card volume.
// Paymennt has never delivered a webhook to this site, so before this command
// the customer's redirect-back was the ONLY path that created the order — when
// it missed (CHK-ZU6KKIBFND, AED 15,265, 2026-09-05) the payment was captured
// with no order and nobody found out for four days.
// Idempotent with verifyPaymennt()/webhook.
Schedule::command('paymennt:reconcile-pending')->everyTwoMinutes()->withoutOverlapping(2);

// Turn silent background failures (stuck email queue, failed jobs) into an
// email alert to the admins, so we hear about them in minutes, not from a
// customer days later. Throttled to once an hour while a problem persists.
Schedule::command('system:health-check')->everyTenMinutes()->withoutOverlapping(2);

// Backstop for link previews: make the small _og.jpg share image for any
// product/category photo that lacks one. Uploads create it, but a failed
// resize is never retried, and without it shared links on WhatsApp/Instagram
// show only a spinner. Skips images that already have one, so a quiet run
// is cheap.
Schedule::command('images:generate-og')->hourly()->withoutOverlapping(30);

// Prune admin bell notifications older than 30 days.
Schedule::call(function () {
    \App\Models\AdminNotification::where('created_at', '<', now()->subDays(30))->delete();
})->dailyAt('03:00')->name('prune-admin-notifications');
