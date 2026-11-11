<?php

namespace App\Providers;

use App\Models\IntegrationCredential;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Overlays the credentials saved in Admin → Integrations onto
 * config('services.<provider>.<field>'), so buyers on shared hosting never
 * have to edit .env. Anything not saved in the admin keeps its .env value.
 *
 * The rows are cached still encrypted (never as plaintext in the cache
 * store) and decrypted on each boot. Saving a credential forgets the cache.
 * Before install (no database, no table) this is a silent no-op.
 */
class IntegrationConfigServiceProvider extends ServiceProvider
{
    public const CACHE_KEY = 'integration_credentials.rows';

    public function boot(): void
    {
        self::apply();
    }

    /** Write every stored credential onto config(). */
    public static function apply(): void
    {
        foreach (self::rows() as $row) {
            $provider = $row['provider'];
            $field = $row['field'];

            // Only fields the admin may edit are ever overlaid.
            if (! isset(IntegrationCredential::PROVIDERS[$provider]['fields'][$field])) {
                continue;
            }

            $value = null;
            if ($row['value'] !== null) {
                try {
                    $value = Crypt::decryptString($row['value']);
                } catch (DecryptException) {
                    // APP_KEY changed since it was saved — keep the .env value.
                    Log::warning("Integration credential {$provider}.{$field} could not be decrypted (APP_KEY changed?)");

                    continue;
                }
            }

            config(["services.{$provider}.{$field}" => $value]);
        }
    }

    /** Forget the cached rows. Called whenever a credential is saved. */
    public static function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // Cache store unavailable — nothing cached.
        }
    }

    /**
     * Stored rows with their values still encrypted.
     *
     * @return array<int, array{provider: string, field: string, value: string|null}>
     */
    private static function rows(): array
    {
        try {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (Throwable) {
            // Cache store not ready (e.g. database cache before install).
        }

        try {
            if (! Schema::hasTable('integration_credentials')) {
                // Not cached, so it is picked up as soon as the table exists.
                return [];
            }

            $rows = DB::table('integration_credentials')
                ->get(['provider', 'field', 'value'])
                ->map(fn ($r) => [
                    'provider' => (string) $r->provider,
                    'field' => (string) $r->field,
                    'value' => $r->value === null ? null : (string) $r->value,
                ])
                ->all();
        } catch (Throwable) {
            // No database configured yet.
            return [];
        }

        try {
            Cache::forever(self::CACHE_KEY, $rows);
        } catch (Throwable) {
            // Fine — read from the table again next boot.
        }

        return $rows;
    }
}
