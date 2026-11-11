<?php

/**
 * ============================================================
 * REGISTER TABBY WEBHOOK — one-shot setup command
 * ============================================================
 * Tabby does NOT send webhooks automatically. The merchant must
 * register a webhook URL once via Tabby's API (POST /api/v1/webhooks).
 * Without this, no payment notifications ever arrive — which means
 * the "corner case" (customer authorizes but never redirects back)
 * is never captured. This command performs that registration.
 *
 * Usage:
 *   php artisan tabby:register-webhook                 # register the prod URL
 *   php artisan tabby:register-webhook --list          # just list existing webhooks
 *   php artisan tabby:register-webhook --url=https://…  # override the URL
 *
 * The header secret comes from TABBY_WEBHOOK_HEADER_VALUE (must be set
 * first, so the same value can verify incoming webhooks).
 * ============================================================
 */

namespace App\Console\Commands;

use App\Services\TabbyService;
use Illuminate\Console\Command;

class RegisterTabbyWebhook extends Command
{
    protected $signature = 'tabby:register-webhook {--url= : Override the webhook URL (defaults to APP_URL/api/webhooks/tabby)} {--list : List existing webhooks instead of registering}';

    protected $description = 'Register (or list) the Tabby webhook URL so payment notifications are delivered';

    public function handle(TabbyService $tabby): int
    {
        if ($this->option('list')) {
            $hooks = $tabby->listWebhooks();
            if ($hooks === null) {
                $this->error('Could not list webhooks (check keys/merchant code).');

                return self::FAILURE;
            }
            if (empty($hooks)) {
                $this->warn('No webhooks registered on this Tabby account.');

                return self::SUCCESS;
            }
            $this->table(['id', 'url'], collect($hooks)->map(fn ($h) => [
                $h['id'] ?? '?',
                $h['url'] ?? '?',
            ])->all());

            return self::SUCCESS;
        }

        $url = $this->option('url') ?: rtrim((string) config('app.url'), '/').'/api/webhooks/tabby';

        if (! str_starts_with($url, 'https://')) {
            $this->error("Webhook URL must be HTTPS and publicly reachable. Got: {$url}");

            return self::FAILURE;
        }

        $this->info("Registering Tabby webhook: {$url}");
        $result = $tabby->registerWebhook($url);

        if (! $result['success']) {
            $this->error('Registration failed: '.$result['error']);

            return self::FAILURE;
        }

        $this->info('✅ Webhook registered. id='.($result['id'] ?? '(none returned)'));

        return self::SUCCESS;
    }
}
