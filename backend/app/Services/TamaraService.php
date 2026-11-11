<?php

/**
 * ============================================================
 * TamaraService.php — Tamara Pay-in-3 Gateway Client
 * ============================================================
 * Talks to Tamara's REST API on behalf of PaymentController.
 * Mirrors TabbyService and the existing Paymennt code so the
 * controller can dispatch to either gateway with the same
 * shape of inputs/outputs.
 *
 * Auth model:
 *   - "api_token" goes in Authorization: Bearer ... for backend calls.
 *   - "public_key" is for the browser widget on the product page.
 *   - "notification_token" is used to verify webhook JWT signatures.
 *
 * Sandbox: https://api-sandbox.tamara.co
 * Live:    https://api.tamara.co
 * ============================================================
 */

namespace App\Services;

use App\Models\PendingCheckout;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TamaraService
{
    /**
     * Tamara prices an order in the currency of the market it ships to, and
     * rejects any other pairing with `not_supported_country_currency`. A KSA
     * order priced in AED is refused outright.
     *
     * @var array<string, string>
     */
    private const MARKET_CURRENCIES = [
        'AE' => 'AED',
        'SA' => 'SAR',
    ];

    public function __construct(private CurrencyConverter $currency) {}

    private function setting(string $key, ?string $default = null): ?string
    {
        return config("services.tamara.{$key}", $default);
    }

    private function baseUrl(): string
    {
        return rtrim((string) $this->setting('base_url', 'https://api-sandbox.tamara.co'), '/');
    }

    /**
     * Create a Tamara checkout session for a staged PendingCheckout.
     * Default product is PAY_BY_INSTALMENTS with 3 instalments (Pay-in-3).
     *
     * Returns ['success' => bool, 'url' => ?string, 'id' => ?string,
     * 'error' => ?string, 'currency' => ?string, 'amount' => ?float].
     *
     * `currency`/`amount` are what Tamara is actually billing — the caller
     * must persist them, because a later capture or refund has to move money
     * in the same currency.
     */
    public function createCheckout(PendingCheckout $checkout, string $returnUrl, string $notificationUrl): array
    {
        $token = $this->setting('api_token');
        if (! $token) {
            return ['success' => false, 'url' => null, 'id' => null, 'error' => 'Tamara credentials not configured'];
        }

        $countryCode = $this->normalizeCountry($checkout->shipping_country);

        $currency = self::MARKET_CURRENCIES[$countryCode] ?? null;
        if ($currency === null) {
            return [
                'success' => false,
                'url' => null,
                'id' => null,
                'error' => 'Tamara is only available for orders shipping to the UAE or Saudi Arabia.',
            ];
        }

        // Our prices are AED; a KSA order has to reach Tamara denominated in
        // SAR. Convert with the same rate the storefront displayed, so the
        // customer is billed the figure they were quoted.
        $rate = $this->currency->rate($currency);

        $sep = str_contains($returnUrl, '?') ? '&' : '?';
        $base = "{$returnUrl}{$sep}reference={$checkout->reference}&gateway=tamara";

        $name = trim((string) ($checkout->customer_name ?? 'Customer'));
        $parts = preg_split('/\s+/', $name, 2) ?: [];
        $first = $parts[0] ?? 'Customer';
        $last = $parts[1] ?? $first;

        // Tamara expects all Money amounts as decimal strings ("500.00") and
        // every line item to carry tax_amount + discount_amount + image_url
        // even when zero/empty. Missing fields cause a generic HTTP 500
        // ("Something went wrong with us. We are notified.") with no detail.
        $money = static fn (float $amount): array => [
            'amount' => number_format(round($amount * $rate, 2), 2, '.', ''),
            'currency' => $currency,
        ];

        $items = collect($checkout->items ?? [])->map(function ($item) use ($money) {
            $qty = (int) ($item['quantity'] ?? 1);
            $unit = round((float) ($item['price'] ?? 0), 2);

            return [
                'reference_id' => (string) ($item['product_id'] ?? ''),
                'type' => 'Watches',
                'name' => substr((string) ($item['product_name'] ?? $item['name'] ?? 'Item'), 0, 250),
                'sku' => (string) ($item['product_id'] ?? ''),
                'quantity' => $qty,
                'unit_price' => $money($unit),
                'total_amount' => $money($unit * $qty),
                'tax_amount' => $money(0),
                'discount_amount' => $money(0),
                'image_url' => (string) ($item['image_url'] ?? ''),
            ];
        })->values()->all();

        $shippingAddress = [
            'first_name' => $first,
            'last_name' => $last,
            'line1' => (string) ($checkout->shipping_address ?: 'N/A'),
            'city' => (string) ($checkout->shipping_city ?: $this->cityFallback($countryCode)),
            'country_code' => $countryCode,
            'phone_number' => (string) ($checkout->customer_phone ?? ''),
        ];

        $payload = [
            'order_reference_id' => $checkout->reference,
            'order_number' => $checkout->reference,
            'total_amount' => $money((float) $checkout->total),
            'description' => 'Order '.$checkout->reference,
            'country_code' => $countryCode,
            'payment_type' => 'PAY_BY_INSTALMENTS',
            'instalments' => 3,
            'locale' => 'en_US',
            'items' => $items,
            'consumer' => [
                'first_name' => $first,
                'last_name' => $last,
                'phone_number' => (string) ($checkout->customer_phone ?? ''),
                'email' => (string) $checkout->customer_email,
            ],
            'shipping_address' => $shippingAddress,
            'billing_address' => $shippingAddress,
            'shipping_amount' => $money((float) ($checkout->shipping_cost ?? 0)),
            'tax_amount' => $money(0),
            'discount' => null,
            'merchant_url' => [
                'success' => $base.'&result=success',
                'failure' => $base.'&result=failure',
                'cancel' => $base.'&result=cancel',
                'notification' => $notificationUrl,
            ],
            'platform' => 'web',
            'is_mobile' => false,
        ];

        // Tamara requires positive amounts and items totalling the order value.
        if ((float) $checkout->total <= 0) {
            return ['success' => false, 'url' => null, 'id' => null, 'error' => 'Order total must be greater than zero'];
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post("{$this->baseUrl()}/checkout", $payload);

        $body = $response->json();

        if (! $response->successful()) {
            Log::error('Tamara checkout failed', [
                'reference' => $checkout->reference,
                'status' => $response->status(),
                'body' => $body ?: $response->body(),
            ]);

            return [
                'success' => false,
                'url' => null,
                'id' => null,
                'error' => $this->extractError($body, $response->status()),
            ];
        }

        $url = $body['checkout_url'] ?? null;
        $id = $body['order_id'] ?? null;
        if (! $url || ! $id) {
            return [
                'success' => false,
                'url' => null,
                'id' => $id,
                'error' => 'Tamara did not return a checkout URL',
            ];
        }

        return [
            'success' => true,
            'url' => $url,
            'id' => $id,
            'error' => null,
            'currency' => $currency,
            'amount' => round((float) $checkout->total * $rate, 2),
        ];
    }

    /**
     * Returns Tamara's order status, lowercased: new, approved, authorised,
     * declined, canceled, expired, fully_captured, partially_captured, etc.
     */
    public function fetchStatus(string $tamaraOrderId): ?string
    {
        $token = $this->setting('api_token');
        if (! $token || ! $tamaraOrderId) {
            return null;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->get("{$this->baseUrl()}/orders/{$tamaraOrderId}");

        if (! $response->successful()) {
            Log::error('Tamara status fetch failed', [
                'id' => $tamaraOrderId,
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            return null;
        }

        return strtolower((string) ($response->json()['status'] ?? ''));
    }

    /**
     * Map Tamara's status vocabulary to our internal payment_status values.
     */
    public function mapStatus(string $gatewayStatus): string
    {
        return match (strtolower($gatewayStatus)) {
            'approved', 'authorised', 'fully_captured' => 'paid',
            'declined', 'canceled', 'expired' => 'failed',
            default => 'pending',
        };
    }

    /**
     * Reverse a Tamara order. Tamara's state machine doesn't have a single
     * "refund" verb that works in every state — the correct call depends on
     * the order's current status:
     *
     *   approved        → /orders/{id}/authorise → /orders/{id}/cancel  (no money moved)
     *   authorised      → /orders/{id}/cancel                            (no money moved)
     *   fully_captured  → /payments/simplified-refund/{id}               (real refund)
     *   *_captured      → /payments/simplified-refund/{id}               (partial refund)
     *
     * Pass null amount for a full refund/cancel. $amount must already be in
     * $currency — i.e. the gateway_amount/gateway_currency stored when the
     * order was placed, NOT the AED total. Refunding an AED figure against a
     * SAR payment would return the wrong amount of money.
     *
     * Returns ['success' => bool, 'gatewayStatus' => ?string, 'error' => ?string].
     */
    public function refund(string $tamaraOrderId, ?float $amount = null, ?string $reason = null, string $currency = 'AED'): array
    {
        $token = $this->setting('api_token');
        if (! $token) {
            return ['success' => false, 'gatewayStatus' => null, 'error' => 'Tamara credentials not configured'];
        }

        $current = $this->fetchStatus($tamaraOrderId);
        if ($current === null) {
            return ['success' => false, 'gatewayStatus' => null, 'error' => 'Could not read Tamara order status'];
        }
        $current = strtolower($current);

        // States that need cancel-not-refund (no money was captured).
        // From `approved` we have to authorise first — Tamara won't let `approved` jump straight to `canceled`.
        if ($current === 'approved') {
            $auth = $this->callAuthorise($tamaraOrderId);
            if (! $auth['success']) {
                return ['success' => false, 'gatewayStatus' => null, 'error' => $auth['error']];
            }
            $current = 'authorised';
        }

        if ($current === 'authorised') {
            $cancel = $this->callCancel($tamaraOrderId, $amount, $reason, $currency);
            if ($cancel['success']) {
                return $cancel;
            }

            // Tamara can auto-capture the payment moments after authorisation,
            // so the status read above may already be stale: the cancel then
            // 409s with transition_not_allowed from a *_captured state. Re-read
            // the status and fall through to a real refund when that happened.
            $fresh = $this->fetchStatus($tamaraOrderId);
            if ($fresh !== null && str_contains($fresh, 'captured')) {
                return $this->callSimplifiedRefund($tamaraOrderId, $amount, $reason, $currency);
            }

            return $cancel;
        }

        // Already terminal states — nothing to do.
        if (in_array($current, ['canceled', 'cancelled', 'declined', 'expired', 'refunded'], true)) {
            return [
                'success' => true,
                'gatewayStatus' => strtoupper($current),
                'error' => null,
            ];
        }

        // Captured states — issue a real refund via simplified-refund.
        return $this->callSimplifiedRefund($tamaraOrderId, $amount, $reason, $currency);
    }

    private function callAuthorise(string $tamaraOrderId): array
    {
        $token = $this->setting('api_token');
        $resp = Http::withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl()}/orders/{$tamaraOrderId}/authorise", new \stdClass);

        if (! $resp->successful()) {
            Log::error('Tamara authorise failed', ['id' => $tamaraOrderId, 'status' => $resp->status(), 'body' => $resp->json() ?? $resp->body()]);

            return ['success' => false, 'error' => $this->extractError($resp->json(), $resp->status())];
        }

        return ['success' => true, 'error' => null];
    }

    private function callCancel(string $tamaraOrderId, ?float $amount, ?string $reason, string $currency = 'AED'): array
    {
        $token = $this->setting('api_token');
        $body = [
            'total_amount' => [
                'amount' => number_format((float) ($amount ?? 0), 2, '.', ''),
                'currency' => $currency,
            ],
            'comment' => $reason !== null && $reason !== '' ? $reason : 'Order cancelled by admin',
        ];

        $resp = Http::withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl()}/orders/{$tamaraOrderId}/cancel", $body);

        $payload = $resp->json() ?? [];
        if (! $resp->successful()) {
            Log::error('Tamara cancel failed', ['id' => $tamaraOrderId, 'status' => $resp->status(), 'body' => $payload ?: $resp->body()]);

            return [
                'success' => false,
                'gatewayStatus' => null,
                'error' => $this->extractError($payload, $resp->status()),
            ];
        }

        Log::info('Tamara cancel accepted', ['id' => $tamaraOrderId, 'response' => $payload]);

        return [
            'success' => true,
            'gatewayStatus' => strtoupper((string) ($payload['status'] ?? 'CANCELED')),
            'error' => null,
        ];
    }

    private function callSimplifiedRefund(string $tamaraOrderId, ?float $amount, ?string $reason, string $currency = 'AED'): array
    {
        $token = $this->setting('api_token');
        $body = [
            'total_amount' => [
                'amount' => number_format((float) ($amount ?? 0), 2, '.', ''),
                'currency' => $currency,
            ],
            'comment' => $reason !== null && $reason !== '' ? $reason : 'Order cancelled by admin',
        ];

        $resp = Http::withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl()}/payments/simplified-refund/{$tamaraOrderId}", $body);

        $payload = $resp->json() ?? [];
        if (! $resp->successful()) {
            Log::error('Tamara refund failed', ['id' => $tamaraOrderId, 'amount' => $amount, 'status' => $resp->status(), 'body' => $payload ?: $resp->body()]);

            return [
                'success' => false,
                'gatewayStatus' => null,
                'error' => $this->extractError($payload, $resp->status()),
            ];
        }

        Log::info('Tamara refund accepted', ['id' => $tamaraOrderId, 'amount' => $amount, 'response' => $payload]);

        return [
            'success' => true,
            'gatewayStatus' => strtoupper((string) ($payload['status'] ?? 'REFUNDED')),
            'error' => null,
        ];
    }

    /**
     * Verify the JWT Tamara attaches to every notification — the
     * `tamaraToken` query parameter (also sent as "Authorization: Bearer").
     * It is an HS256 token signed with the merchant notification_token, with
     * iss "Tamara" and a short exp. Every live Tamara webhook used to be
     * rejected 401 because only the (unused) tamara-signature header was
     * checked; the redirect-back verify was the only thing creating orders.
     */
    public function verifyWebhookToken(?string $jwt): bool
    {
        $secret = $this->setting('notification_token');
        if (! $secret || ! $jwt || substr_count($jwt, '.') !== 2) {
            return false;
        }

        [$h, $p, $s] = explode('.', $jwt);
        $decode = fn (string $part) => json_decode((string) base64_decode(strtr($part, '-_', '+/'), true), true);
        $header = $decode($h);
        $payload = $decode($p);
        if (! is_array($header) || ($header['alg'] ?? null) !== 'HS256' || ! is_array($payload)) {
            return false;
        }

        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "$h.$p", $secret, true)), '+/', '-_'), '=');
        if (! hash_equals($expected, $s)) {
            return false;
        }
        if (($payload['iss'] ?? null) !== 'Tamara') {
            return false;
        }
        // 5-minute leeway for clock skew between Tamara and this host.
        if (isset($payload['exp']) && (int) $payload['exp'] < time() - 300) {
            return false;
        }

        return true;
    }

    /**
     * Legacy check: HMAC-SHA256 of the raw body in a tamara-signature header.
     * Kept as a fallback; Tamara's live notifications use the JWT above.
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signatureHeader): bool
    {
        $secret = $this->setting('notification_token');
        if (! $secret || ! $signatureHeader) {
            return false;
        }
        $computed = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

        return hash_equals($signatureHeader, $computed);
    }

    private function normalizeCountry(?string $country): string
    {
        $upper = strtoupper((string) $country);
        if ($upper === '' || $upper === 'AE' || str_contains($upper, 'UNITED ARAB') || $upper === 'UAE') {
            return 'AE';
        }
        if ($upper === 'SA' || str_contains($upper, 'SAUDI')) {
            return 'SA';
        }
        if ($upper === 'KW' || str_contains($upper, 'KUWAIT')) {
            return 'KW';
        }
        if (strlen($upper) === 2) {
            return $upper;
        }

        return 'AE';
    }

    private function cityFallback(string $countryCode): string
    {
        return match ($countryCode) {
            'SA' => 'Riyadh',
            'KW' => 'Kuwait City',
            default => 'Dubai',
        };
    }

    private function extractError(mixed $body, int $status): string
    {
        if (is_array($body)) {
            if (isset($body['errors']) && is_array($body['errors'])) {
                $first = $body['errors'][0] ?? null;
                if (is_array($first) && isset($first['error_code'])) {
                    return (string) ($first['description'] ?? $first['error_code']);
                }
            }
            if (isset($body['message'])) {
                return (string) $body['message'];
            }
            if (isset($body['error'])) {
                return is_string($body['error']) ? $body['error'] : json_encode($body['error']);
            }
        }

        return "Tamara returned HTTP {$status}";
    }
}
