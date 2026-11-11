<?php
/**
 * ============================================================
 * INTEGRATIONS CONTROLLER — Payment / shipping credentials
 * ============================================================
 * Lets the admin enter gateway and courier credentials (Stripe,
 * Paymennt, Tabby, Tamara, Aramex, Topex) from the dashboard
 * instead of editing .env. Values are stored encrypted in
 * integration_credentials and overlaid onto
 * config('services.<provider>.<field>') by
 * IntegrationConfigServiceProvider; .env stays the fallback.
 *
 * Secret fields are NEVER returned — only whether they are set
 * and a mask with their last 4 characters.
 *
 * API endpoints (admin only):
 *   GET /api/admin/integrations            — every provider and field
 *   PUT /api/admin/integrations/{provider} — update one provider
 *       body {"<field>": "value"}: absent or "" keeps the current
 *       value, null clears it.
 * ============================================================
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IntegrationCredential;
use App\Providers\IntegrationConfigServiceProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IntegrationsController extends Controller
{
    private const MAX_LENGTH = 4000;

    public function index(): JsonResponse
    {
        $providers = [];
        foreach (array_keys(IntegrationCredential::PROVIDERS) as $provider) {
            $providers[$provider] = $this->describe($provider);
        }

        return response()->json(['providers' => $providers]);
    }

    public function update(Request $request, string $provider): JsonResponse
    {
        $definition = IntegrationCredential::PROVIDERS[$provider] ?? null;
        if ($definition === null) {
            return response()->json(['error' => 'Unknown integration provider'], 404);
        }

        // Read the raw body: the global ConvertEmptyStringsToNull middleware
        // would otherwise turn "" (keep) into null (clear).
        $input = json_decode($request->getContent(), true);
        if (! is_array($input)) {
            $input = $request->all();
        }

        $errors = [];
        $changes = [];
        foreach ($input as $field => $value) {
            if (! isset($definition['fields'][$field])) {
                $errors[$field][] = 'Unknown field for '.$definition['label'].'.';

                continue;
            }

            if ($value === null) {
                $changes[$field] = null;

                continue;
            }

            if (is_int($value) || is_float($value)) {
                $value = (string) $value;
            }
            if (! is_string($value)) {
                $errors[$field][] = 'The value must be text or null.';

                continue;
            }

            // Pasted keys often carry a BOM or stray whitespace/newlines.
            $value = trim(preg_replace('/^\x{FEFF}/u', '', $value) ?? $value);
            if ($value === '') {
                continue; // keep the current value
            }
            if (mb_strlen($value) > self::MAX_LENGTH) {
                $errors[$field][] = 'The value may not be longer than '.self::MAX_LENGTH.' characters.';

                continue;
            }
            if (! IntegrationCredential::isAllowedUrl($provider, $field, $value)) {
                $errors[$field][] = 'Must be an official https host: '.implode(', ', $definition['fields'][$field]['allowed_hosts']).'.';

                continue;
            }

            $changes[$field] = $value;
        }

        if ($errors !== []) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $errors,
            ], 422);
        }

        DB::transaction(function () use ($provider, $changes) {
            foreach ($changes as $field => $value) {
                IntegrationCredential::updateOrCreate(
                    ['provider' => $provider, 'field' => $field],
                    ['value' => $value],
                );
            }
        });

        if ($changes !== []) {
            IntegrationConfigServiceProvider::forget();
            // Apply to this request too, so the response shows what was saved.
            IntegrationConfigServiceProvider::apply();
        }

        return response()->json(['provider' => $this->describe($provider)]);
    }

    /**
     * One provider as the admin sees it, read from the effective config
     * (admin-saved values overlaid on .env).
     */
    private function describe(string $provider): array
    {
        $definition = IntegrationCredential::PROVIDERS[$provider];

        $fields = [];
        foreach ($definition['fields'] as $field => $meta) {
            $current = config("services.{$provider}.{$field}");
            $current = is_scalar($current) ? (string) $current : '';
            $set = $current !== '';

            $fields[$field] = [
                'label' => $meta['label'],
                'secret' => $meta['secret'],
                'set' => $set,
                'value' => $meta['secret'] ? null : ($set ? $current : null),
                'masked' => $meta['secret'] && $set ? $this->mask($current) : null,
                'allowedHosts' => $meta['allowed_hosts'] ?? null,
            ];
        }

        return ['label' => $definition['label'], 'fields' => $fields];
    }

    /**
     * "••••" plus the last 4 characters. Short secrets (PINs, passwords)
     * would be mostly revealed by their last 4, so they get the dots only.
     */
    private function mask(string $value): string
    {
        return mb_strlen($value) >= 12 ? '••••'.mb_substr($value, -4) : '••••';
    }
}
