<?php
/**
 * ============================================================
 * SETTINGS CONTROLLER — Site Settings Management
 * ============================================================
 * What this controller does:
 *   Manages key-value site settings stored in the database
 *   (like store name, currency rates, contact info, etc.).
 *   Public users can read non-sensitive settings.
 *   Admins can read all settings and update/delete them.
 *   Sensitive keys (API keys, passwords) are hidden from
 *   the public endpoint for security.
 *
 * API endpoints:
 *   GET    /api/settings               — List public (non-sensitive) settings
 *   GET    /api/admin/settings         — List ALL settings (admin only)
 *   GET    /api/settings/{key}         — Get a single setting by key
 *   PUT    /api/settings/{key}         — Update or create a setting (admin)
 *   POST   /api/settings/batch         — Update multiple settings at once (admin)
 *   DELETE /api/settings/{key}         — Delete a setting (admin)
 *   GET    /api/settings/currency-rates — Get stored currency exchange rates
 * ============================================================
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    // Keys starting with these prefixes are hidden from public users (they contain secrets)
    // KLAVIYO_ is listed defensively: the Klaviyo secrets live in .env, not here,
    // but a KLAVIYO_PRIVATE_KEY row added to site_settings by mistake would
    // otherwise be handed to every visitor by the public index() below.
    // SPIN_ holds the prize odds; SpinController reads and writes it directly.
    private array $sensitiveKeys = ['ARAMEX_', 'TOPEX_', 'PAYMENNT_', 'KLAVIYO_', 'JWT_', 'DATABASE_', 'SPIN_'];

    // Exceptions to the prefix rule above: keys that LOOK sensitive by prefix but
    // are public by design and needed by the storefront. Deny-by-default with an
    // explicit allowlist, rather than loosening the prefix — a future
    // KLAVIYO_WEBHOOK_TOKEN must stay blocked without anyone remembering to act.
    //
    // KLAVIYO_SITE_ID is Klaviyo's public API key. It is embedded in the
    // klaviyo.js URL and visible in page source by design; <KlaviyoLoader> reads
    // it from here so staging and production can point at different accounts
    // despite sharing one built dist/.
    private array $publicOverrides = ['KLAVIYO_SITE_ID'];

    private function isSensitive(string $key): bool
    {
        $key = strtoupper($key);

        if (in_array($key, $this->publicOverrides, true)) {
            return false;
        }

        foreach ($this->sensitiveKeys as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * GET /api/settings
     * Returns all settings EXCEPT sensitive ones (API keys, passwords, etc.).
     * Safe for public/frontend use.
     */
    public function index()
    {
        $settings = SiteSetting::all()
            ->filter(fn ($s) => ! $this->isSensitive($s->key))
            ->pluck('value', 'key');

        return response()->json($settings);
    }

    /**
     * GET /api/admin/settings
     * Returns ALL settings including sensitive ones. Admin only.
     */
    public function all()
    {
        return response()->json(SiteSetting::orderBy('key')->pluck('value', 'key'));
    }

    /**
     * GET /api/settings/{key}
     * Returns a single setting value by its key.
     * Blocks access to sensitive keys (returns 403 Forbidden).
     */
    public function show(string $key)
    {
        if ($this->isSensitive($key)) {
            return response()->json(['error' => 'Access denied'], 403);
        }

        $setting = SiteSetting::where('key', strtoupper($key))->firstOrFail();
        return response()->json(['key' => $setting->key, 'value' => $setting->value]);
    }

    /**
     * PUT /api/settings/{key}
     * Creates or updates a single setting. The key is stored in uppercase.
     */
    public function update(Request $request, string $key)
    {
        $request->validate(['value' => 'nullable|max:5000']);

        $upper = strtoupper($key);
        if ($this->isSensitiveKey($upper)) {
            return response()->json([
                'error' => 'This key is reserved and can only be set via the server .env file.',
            ], 403);
        }

        $setting = SiteSetting::updateOrCreate(
            ['key' => $upper],
            ['value' => $request->value]
        );

        return response()->json($setting);
    }

    /**
     * POST /api/settings/batch
     * Updates multiple settings at once. Send a JSON object like:
     * { "STORE_NAME": "My Store", "STORE_PHONE": "+1..." }
     */
    public function batch(Request $request)
    {
        $count = 0;
        $rejected = [];
        foreach ($request->all() as $key => $value) {
            if (!is_string($key) || strlen($key) > 100) continue;
            // Refuse to write keys with sensitive prefixes — those are
            // gateway / shipping / DB credentials and must come from .env,
            // not from any HTTP endpoint, even an admin one.
            $upper = strtoupper($key);
            if ($this->isSensitiveKey($upper)) {
                $rejected[] = $upper;
                continue;
            }
            $strValue = is_null($value) ? '' : (string) $value;
            SiteSetting::updateOrCreate(['key' => $upper], ['value' => $strValue]);
            $count++;
        }

        return response()->json([
            'success' => true,
            'updated' => $count,
            'rejected' => $rejected,
        ]);
    }

    private function isSensitiveKey(string $upper): bool
    {
        foreach ($this->sensitiveKeys as $prefix) {
            if (str_starts_with($upper, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * DELETE /api/settings/{key}
     * Permanently deletes a setting by its key.
     */
    public function destroy(string $key)
    {
        SiteSetting::where('key', strtoupper($key))->delete();
        \App\Support\Brand::flush();
        return response()->json(['success' => true]);
    }

    /**
     * GET /api/settings/currency-rates
     * Returns the stored currency exchange rates (e.g. AED to USD, EUR, etc.).
     */
    public function fetchCurrencyRates()
    {
        try {
            // Fetch live rates from exchangerate-api.com (free, no API key needed)
            $response = \Illuminate\Support\Facades\Http::get('https://open.er-api.com/v6/latest/AED');
            $data = $response->json();

            if (($data['result'] ?? '') !== 'success') {
                return response()->json(['success' => false, 'error' => 'Failed to fetch rates from provider'], 500);
            }

            $apiRates = $data['rates'] ?? [];

            // Only keep currencies we support
            $supported = ['AED','USD','EUR','GBP','SAR','KWD','QAR','OMR','BHD','JOD','EGP','MAD','IQD','DZD','LYD','THB','INR','NGN','ZAR','ZMK','UZS','RSD','CAD','SEK','AMD'];
            $rates = [];
            foreach ($supported as $currency) {
                if (isset($apiRates[$currency])) {
                    $rates[$currency] = round($apiRates[$currency], 6);
                }
            }
            $rates['AED'] = 1;

            return response()->json([
                'success' => true,
                'rates' => $rates,
                'source' => 'exchangerate-api.com',
                'lastUpdated' => now()->toDateTimeString(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
