<?php

/**
 * ============================================================
 * SYSTEM HEALTH CHECK — turns silent failures into an alert
 * ============================================================
 * Every incident this project has had with orders/emails was SILENT:
 * the order data was fine, but a background step (the email queue) had
 * stalled, and nobody knew until a customer complained days later.
 *
 * This runs on a schedule and emails the admins the moment it sees:
 *   - the email queue backed up (jobs available >10 min and unprocessed)
 *     — usually means the queue worker isn't draining (see the 24h
 *     withoutOverlapping lock incident, fixed 2026-08), so order
 *     confirmation emails are silently piling up;
 *   - any failed queue job.
 *
 * It throttles itself so a persistent problem alerts once an hour, not
 * every run. When healthy it clears the throttle so the next problem
 * alerts immediately.
 *
 * NOTE: this is driven by the same scheduler cron it can't watch — if
 * the cron itself stops firing, this stops too. Detecting a dead cron
 * needs an EXTERNAL heartbeat monitor (e.g. healthchecks.io) hitting a
 * URL; that's a separate, optional safeguard.
 *
 * Schedule: every 10 minutes (routes/console.php).
 * Manual run: php artisan system:health-check
 * ============================================================
 */

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SystemHealthCheck extends Command
{
    protected $signature = 'system:health-check {--stuck-minutes=10 : Flag jobs available longer than this}';

    protected $description = 'Alert admins if the email queue is stuck or jobs have failed';

    private const THROTTLE_KEY = 'system_health_alert_active';

    public function handle(): int
    {
        $problems = [];

        // Queue backed up: jobs that became available > N minutes ago and are
        // still sitting there unprocessed = the worker isn't draining.
        $stuckMinutes = (int) $this->option('stuck-minutes');
        $stuckCutoff = now()->subMinutes($stuckMinutes)->timestamp;
        $stuck = DB::table('jobs')->where('available_at', '<=', $stuckCutoff)->count();
        if ($stuck > 0) {
            $problems[] = "{$stuck} email job(s) stuck in the queue for over {$stuckMinutes} min — order confirmation emails are delayed. Run: php artisan queue:work --stop-when-empty";
        }

        // Any failed job at all is worth a look.
        $failed = DB::table('failed_jobs')->count();
        if ($failed > 0) {
            $problems[] = "{$failed} failed queue job(s) — some emails/notifications did not send. Inspect: php artisan queue:failed";
        }

        if (empty($problems)) {
            // Healthy — clear the throttle so the next real problem alerts at once.
            Cache::forget(self::THROTTLE_KEY);
            $this->info('Health check OK.');

            return self::SUCCESS;
        }

        // Something is wrong. Log always; email at most once an hour.
        Log::warning('System health check found problems', ['problems' => $problems]);

        // Alert emails are OFF unless HEALTH_ALERT_EMAILS=true is set in .env
        // (the client asked not to receive them, 2026-10-02). Problems are
        // still written to the Laravel log above. Never emailed outside
        // production either: staging's leftover test jobs used to send these
        // to the client's inbox, indistinguishable from real alerts.
        if (! config('services.health_alert_emails') || ! app()->environment('production')) {
            $this->warn('Problems found (logged, alert emails off): '.implode(' | ', $problems));

            return self::SUCCESS;
        }

        if (Cache::get(self::THROTTLE_KEY)) {
            $this->warn('Problems found (alert already sent within the last hour): '.implode(' | ', $problems));

            return self::SUCCESS;
        }
        Cache::put(self::THROTTLE_KEY, true, now()->addHour());

        $recipients = \App\Services\AdminNotifier::adminEmails();
        if (empty($recipients)) {
            $this->error('Problems found but no admin email set (ADMIN_NOTIFICATION_EMAILS or store email) — no alert sent.');

            return self::SUCCESS;
        }

        $body = \App\Support\Brand::name()." — automated system health alert.\n\n"
            ."The following need attention:\n\n- ".implode("\n- ", $problems)
            ."\n\nThis is sent at most once per hour while the problem persists. "
            ."Reply to this email if you need help.";

        try {
            Mail::raw($body, function ($m) use ($recipients) {
                $m->to($recipients)->subject('⚠️ '.\App\Support\Brand::name().' — system health alert');
            });
            $this->warn('Health alert emailed to admins.');
        } catch (\Throwable $e) {
            Log::error('Failed to send health alert email: '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
