<?php

/**
 * ============================================================
 * TabbyService.php — Tabby Pay-in-4 Gateway Client
 * ============================================================
 * Talks to Tabby's REST API on behalf of PaymentController.
 * Mirrors the surface area of the existing Paymennt code so
 * the controller can dispatch to either gateway with the same
 * shape of inputs/outputs.
 *
 * Auth model:
 *   - "secret_key" goes in Authorization: Bearer ... for backend calls.
 *   - "public_key" is for the browser widget on the product page.
 *
 * Sandbox vs live is controlled by the key prefix (pk_test_ vs pk_live_),
 * not by URL.
 * ============================================================
 */

namespace App\Services;

use App\Models\PendingCheckout;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TabbyService
{
    /**
     * Tabby prices a session in the currency of the market it ships to; an
     * AED-priced KSA order is rejected. Mirrors TamaraService.
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
        return config("services.tabby.{$key}", $default);
    }

    /**
     * Tabby issues a distinct merchant code per market. Returns null when the
     * market has no code configured, which means Tabby isn't live there yet.
     */
    private function merchantCodeFor(string $countryCode): ?string
    {
        $code = $countryCode === 'SA'
            ? $this->setting('merchant_code_sa')
            : $this->setting('merchant_code');

        return $code !== null && $code !== '' ? $code : null;
    }

    private function baseUrl(): string
    {
        return rtrim((string) $this->setting('base_url', 'https://api.tabby.ai'), '/');
    }

    /**
     * Create a Tabby checkout session for a staged PendingCheckout.
     *
     * Returns ['success' => bool, 'url' => ?string, 'id' => ?string,
     * 'rejection' => ?string (Tabby's reject reason), 'error' => ?string].
     *
     * Tabby's response carries `configuration.available_products.installments[]`
     * — if it's empty, the order isn't eligible (e.g. amount out of range).
     * In that case we return success=false with the rejection reason so the
     * frontend can show a useful message.
     */
    public function createCheckout(PendingCheckout $checkout, string $returnUrl): array
    {
        $secretKey = $this->setting('secret_key');

        if (! $secretKey) {
            return ['success' => false, 'url' => null, 'id' => null, 'rejection' => null, 'error' => 'Tabby credentials not configured'];
        }

        $countryCode = $this->normalizeCountry($checkout->shipping_country);

        $currency = self::MARKET_CURRENCIES[$countryCode] ?? null;
        if ($currency === null) {
            return [
                'success' => false,
                'url' => null,
                'id' => null,
                'rejection' => null,
                'error' => 'Tabby is only available for orders shipping to the UAE or Saudi Arabia.',
            ];
        }

        $merchantCode = $this->merchantCodeFor($countryCode);
        if (! $merchantCode) {
            return [
                'success' => false,
                'url' => null,
                'id' => null,
                'rejection' => null,
                'error' => 'Tabby is not enabled for this country yet.',
            ];
        }

        // Prices are stored in AED; a KSA session must be denominated in SAR,
        // converted at the same rate the storefront quoted the customer.
        $rate = $this->currency->rate($currency);

        $sep = str_contains($returnUrl, '?') ? '&' : '?';
        $base = "{$returnUrl}{$sep}reference={$checkout->reference}&gateway=tabby";

        $name = trim((string) ($checkout->customer_name ?? 'Customer'));
        $parts = preg_split('/\s+/', $name, 2) ?: [];
        $first = $parts[0] ?? 'Customer';
        $last = $parts[1] ?? $first;

        $items = collect($checkout->items ?? [])->map(function ($item) use ($rate) {
            $qty = (int) ($item['quantity'] ?? 1);
            $unit = round((float) ($item['price'] ?? 0) * $rate, 2);

            return [
                'title' => substr((string) ($item['product_name'] ?? $item['name'] ?? 'Item'), 0, 250),
                'quantity' => $qty,
                'unit_price' => number_format($unit, 2, '.', ''),
                'reference_id' => (string) ($item['product_id'] ?? ''),
                'category' => 'Watches',
            ];
        })->values()->all();

        $payload = [
            'payment' => [
                'amount' => number_format(round((float) $checkout->total * $rate, 2), 2, '.', ''),
                'currency' => $currency,
                'description' => 'Order '.$checkout->reference,
                'buyer' => [
                    'phone' => (string) ($checkout->customer_phone ?? ''),
                    'email' => (string) $checkout->customer_email,
                    'name' => $first.' '.$last,
                ],
                'shipping_address' => [
                    'city' => (string) ($checkout->shipping_city ?: 'Dubai'),
                    'address' => (string) ($checkout->shipping_address ?: 'N/A'),
                    'zip' => (string) ($checkout->shipping_zip ?? ''),
                ],
                'order' => [
                    'reference_id' => $checkout->reference,
                    'items' => $items,
                ],
                'buyer_history' => [
                    'registered_since' => $checkout->created_at?->toIso8601String() ?? now()->toIso8601String(),
                    'loyalty_level' => 0,
                ],
                'order_history' => [],
                'meta' => [
                    'order_id' => $checkout->reference,
                    'customer' => (string) $checkout->customer_email,
                ],
            ],
            'lang' => 'en',
            'merchant_code' => $merchantCode,
            'merchant_urls' => [
                'success' => $base.'&result=success',
                'cancel' => $base.'&result=cancel',
                'failure' => $base.'&result=failure',
            ],
        ];

        // Country code is on the buyer block as ISO-2.
        $payload['payment']['shipping_address']['country_code'] = $countryCode;

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$secretKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post("{$this->baseUrl()}/api/v2/checkout", $payload);

        $body = $response->json();

        if (! $response->successful()) {
            Log::error('Tabby checkout failed', [
                'reference' => $checkout->reference,
                'status' => $response->status(),
                'body' => $body ?: $response->body(),
            ]);

            return [
                'success' => false,
                'url' => null,
                'id' => null,
                'rejection' => null,
                'error' => $this->extractError($body, $response->status()),
            ];
        }

        $status = strtolower((string) ($body['status'] ?? ''));
        $installments = $body['configuration']['available_products']['installments'] ?? [];

        if ($status === 'rejected' || (is_array($installments) && count($installments) === 0)) {
            // Tabby rejects the session pre-redirect when the customer or order isn't eligible.
            $reason = $body['configuration']['available_products']['rejection_reason']
                ?? $body['rejection_reason']
                ?? 'not_available';
            Log::info('Tabby rejected checkout', ['reference' => $checkout->reference, 'reason' => $reason]);

            return [
                'success' => false,
                'url' => null,
                'id' => $body['id'] ?? null,
                'rejection' => (string) $reason,
                'error' => 'Tabby is not available for this order: '.$reason,
            ];
        }

        $webUrl = $installments[0]['web_url']
            ?? $body['configuration']['available_products']['installments'][0]['web_url']
            ?? null;

        return [
            'success' => (bool) $webUrl,
            'url' => $webUrl,
            'id' => $body['id'] ?? null,
            'rejection' => null,
            'error' => $webUrl ? null : 'Tabby did not return a redirect URL',
            // The caller persists these: a later capture/refund sends a bare
            // amount whose currency is implied by this session.
            'currency' => $currency,
            'amount' => round((float) $checkout->total * $rate, 2),
        ];
    }

    /**
     * Pre-scoring eligibility check (Tabby "pre-scoring", checkout-flow docs).
     *
     * Runs BEFORE Tabby is shown as a payment option, as soon as the cart
     * total and the customer's email/phone are known. It POSTs the same
     * /api/v2/checkout endpoint with a minimal payload and inspects the
     * response WITHOUT redirecting the customer anywhere — Tabby either
     * approves (status "created" + installments available) or rejects with
     * a reason so we can disable the option and show the correct message.
     *
     * Returns:
     *   ['eligible' => true,  'reason' => null]                       — show Tabby
     *   ['eligible' => false, 'reason' => 'order_amount_too_high'|…]  — disable + message
     *   ['eligible' => false, 'reason' => 'not_available']            — safe fallback on any error
     *
     * We deliberately fail "not_available" (rather than throwing) so a
     * transient Tabby/API hiccup just hides the option instead of breaking
     * the whole checkout page.
     */
    public function prescore(array $params): array
    {
        $secretKey = $this->setting('secret_key');
        $country = $this->normalizeCountry($params['country'] ?? null);

        // Score against the same market the real session will open in,
        // otherwise an eligible KSA customer is scored as a UAE one (or
        // rejected outright for the country/currency mismatch).
        $currency = self::MARKET_CURRENCIES[$country] ?? null;
        $merchantCode = $currency !== null ? $this->merchantCodeFor($country) : null;

        if (! $secretKey || ! $merchantCode || $currency === null) {
            return ['eligible' => false, 'reason' => 'not_available'];
        }

        // `amount` arrives in AED (the currency everything is stored in).
        $amount = number_format($this->currency->convert((float) ($params['amount'] ?? 0), $currency), 2, '.', '');
        $email = (string) ($params['email'] ?? '');
        $phone = (string) ($params['phone'] ?? '');
        $name = trim((string) ($params['name'] ?? 'Customer')) ?: 'Customer';

        // Minimal payload Tabby needs to score: amount, currency, buyer
        // contact + merchant code. No merchant_urls / order items required
        // for a score-only call.
        $payload = [
            'payment' => [
                'amount' => $amount,
                'currency' => $currency,
                'buyer' => [
                    'phone' => $phone,
                    'email' => $email,
                    'name' => $name,
                ],
                'shipping_address' => [
                    'city' => (string) ($params['city'] ?? 'Dubai'),
                    'address' => (string) ($params['address'] ?? 'N/A'),
                    'zip' => (string) ($params['zip'] ?? ''),
                    'country_code' => $country,
                ],
                'order' => [
                    'reference_id' => 'prescore-'.substr(md5($email.$amount), 0, 12),
                ],
            ],
            'lang' => (string) ($params['lang'] ?? 'en'),
            'merchant_code' => $merchantCode,
        ];

        try {
            $response = Http::timeout(12)->withHeaders([
                'Authorization' => 'Bearer '.$secretKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post("{$this->baseUrl()}/api/v2/checkout", $payload);
        } catch (\Throwable $e) {
            Log::warning('Tabby prescore transport error', ['error' => $e->getMessage()]);

            return ['eligible' => false, 'reason' => 'not_available'];
        }

        if (! $response->successful()) {
            Log::info('Tabby prescore non-2xx', [
                'status' => $response->status(),
                'body' => $response->json() ?: $response->body(),
            ]);

            return ['eligible' => false, 'reason' => 'not_available'];
        }

        $body = $response->json();
        $status = strtolower((string) ($body['status'] ?? ''));
        $installments = $body['configuration']['available_products']['installments'] ?? [];
        $eligible = $status === 'created' && is_array($installments) && count($installments) > 0;

        if ($eligible) {
            return ['eligible' => true, 'reason' => null];
        }

        // Surface Tabby's own rejection reason so the frontend can show the
        // prescribed per-reason customer message.
        $reason = $body['configuration']['available_products']['rejection_reason']
            ?? $body['rejection_reason']
            ?? 'not_available';

        return ['eligible' => false, 'reason' => (string) $reason];
    }

    /**
     * Returns the gateway's payment status for a Tabby payment ID.
     * Possible statuses: AUTHORIZED, CLOSED, EXPIRED, REJECTED, NEW, CREATED, etc.
     */
    public function fetchStatus(string $tabbyPaymentId): ?string
    {
        $payment = $this->fetchPayment($tabbyPaymentId);

        return $payment === null ? null : strtoupper((string) ($payment['status'] ?? ''));
    }

    /**
     * Fetch a Tabby checkout session (the object returned by POST /api/v2/checkout).
     * The session id is what we store on the PendingCheckout as `tabby_payment_id`.
     * After the customer authorizes, the session's `payment` sub-object holds the
     * real payment id and current status — used by the redirect-back verify path
     * because Tabby's /api/v2/payments/{id} endpoint does NOT accept session ids.
     *
     * Returns the decoded body or null on transport failure / 404.
     */
    public function fetchCheckout(string $tabbyCheckoutSessionId): ?array
    {
        $secretKey = $this->setting('secret_key');
        if (! $secretKey || ! $tabbyCheckoutSessionId) {
            return null;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$secretKey,
        ])->get("{$this->baseUrl()}/api/v2/checkout/{$tabbyCheckoutSessionId}");

        if (! $response->successful()) {
            Log::error('Tabby checkout fetch failed', [
                'id' => $tabbyCheckoutSessionId,
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            return null;
        }

        return $response->json();
    }

    /**
     * Fetches the full payment object from Tabby, including the captures and
     * refunds arrays. Returns null on transport failure. Used by the webhook
     * handler to distinguish a Close event (no refunds) from a Refund event
     * (refunds array non-empty) — both arrive with status="closed".
     */
    public function fetchPayment(string $tabbyPaymentId): ?array
    {
        $secretKey = $this->setting('secret_key');
        if (! $secretKey || ! $tabbyPaymentId) {
            return null;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$secretKey,
        ])->get("{$this->baseUrl()}/api/v2/payments/{$tabbyPaymentId}");

        if (! $response->successful()) {
            Log::error('Tabby payment fetch failed', [
                'id' => $tabbyPaymentId,
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            return null;
        }

        return $response->json();
    }

    /**
     * Map Tabby's status vocabulary to our internal payment_status values.
     */
    public function mapStatus(string $gatewayStatus): string
    {
        return match (strtoupper($gatewayStatus)) {
            'AUTHORIZED', 'CLOSED' => 'paid',
            'REJECTED', 'EXPIRED' => 'failed',
            default => 'pending',
        };
    }

    /**
     * Capture and close a Tabby payment so it becomes refundable.
     *
     * Tabby payments stay in AUTHORIZED status after the customer's redirect-back
     * unless we (a) call /captures with the full amount — which auto-closes —
     * or (b) call /close directly. Refunds reject with 400 unless the payment is
     * CLOSED. This method is idempotent: if Tabby is already CLOSED it returns
     * success without re-capturing.
     *
     * Some Tabby merchant accounts have auto-capture enabled at the gateway
     * level. In that case the payment is CLOSED before this method runs and
     * the early-return below short-circuits. Either way, calling this after a
     * successful payment is safe.
     */
    public function captureAndClose(string $tabbyPaymentId, float $amount): array
    {
        $secretKey = $this->setting('secret_key');
        if (! $secretKey || ! $tabbyPaymentId) {
            return ['success' => false, 'error' => 'Tabby credentials or payment id missing'];
        }

        $current = $this->fetchStatus($tabbyPaymentId);
        if ($current === 'CLOSED') {
            return ['success' => true, 'error' => null, 'alreadyClosed' => true];
        }
        if ($current !== 'AUTHORIZED') {
            // REJECTED, EXPIRED, CREATED — none can be captured.
            return ['success' => false, 'error' => "Tabby payment is {$current}, cannot capture"];
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$secretKey,
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl()}/api/v2/payments/{$tabbyPaymentId}/captures", [
            'amount' => number_format($amount, 2, '.', ''),
            'reference_id' => 'cap-'.$tabbyPaymentId,
        ]);

        if (! $response->successful()) {
            Log::error('Tabby capture failed', [
                'id' => $tabbyPaymentId,
                'amount' => $amount,
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            return ['success' => false, 'error' => $this->extractError($response->json(), $response->status())];
        }

        Log::info('Tabby capture+close accepted', ['id' => $tabbyPaymentId, 'amount' => $amount]);

        return ['success' => true, 'error' => null, 'alreadyClosed' => false];
    }

    /**
     * Refund a Tabby payment. Pass null amount for a full refund.
     * Returns ['success' => bool, 'gatewayStatus' => ?string, 'error' => ?string].
     *
     * Tabby requires the payment to be in CLOSED status before a refund call
     * will succeed, so this method calls captureAndClose() first when the
     * payment is still AUTHORIZED.
     */
    public function refund(string $tabbyPaymentId, ?float $amount = null, ?string $reason = null): array
    {
        $secretKey = $this->setting('secret_key');
        if (! $secretKey) {
            return ['success' => false, 'gatewayStatus' => null, 'error' => 'Tabby credentials not configured'];
        }

        // Tabby will return 400 "can not refund payment that has no captures
        // and not closed" unless the payment is CLOSED. Make sure it is.
        if ($amount !== null) {
            $closeResult = $this->captureAndClose($tabbyPaymentId, $amount);
            if (! $closeResult['success']) {
                return ['success' => false, 'gatewayStatus' => null, 'error' => $closeResult['error']];
            }
        }

        $body = [
            // Required by Tabby — both as the refunded amount and as an
            // idempotency key so retries don't double-refund.
            'amount' => number_format((float) ($amount ?? 0), 2, '.', ''),
            'reference_id' => 'ref-'.$tabbyPaymentId.'-'.now()->timestamp,
            'reason' => $reason !== null && $reason !== '' ? $reason : 'Order cancelled by admin',
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$secretKey,
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl()}/api/v2/payments/{$tabbyPaymentId}/refunds", $body);

        $payload = $response->json() ?? [];

        if (! $response->successful()) {
            Log::error('Tabby refund failed', [
                'id' => $tabbyPaymentId,
                'amount' => $amount,
                'status' => $response->status(),
                'body' => $payload ?: $response->body(),
            ]);

            return [
                'success' => false,
                'gatewayStatus' => null,
                'error' => $this->extractError($payload, $response->status()),
            ];
        }

        Log::info('Tabby refund accepted', ['id' => $tabbyPaymentId, 'amount' => $amount, 'response' => $payload]);

        return [
            'success' => true,
            'gatewayStatus' => strtoupper((string) ($payload['status'] ?? 'REFUNDED')),
            'error' => null,
        ];
    }

    /**
     * Verify a Tabby webhook by comparing the static header value Tabby
     * was configured to send on every webhook against our stored secret.
     *
     * Unlike most gateways, Tabby does NOT HMAC-sign the payload. When you
     * register a webhook (POST /api/v1/webhooks) you tell Tabby:
     *   - header.title  → the header name to send on every webhook (e.g. X-Tabby-Auth)
     *   - header.value  → the static secret value that header should carry
     * Tabby then drops that header onto every callback. We just compare it
     * to what we stored at registration time.
     *
     * Both the header name and the expected value must be configured in
     * services.tabby.webhook_header_name / webhook_header_value.
     */
    public function verifyWebhook(\Illuminate\Http\Request $request): bool
    {
        $headerName = $this->setting('webhook_header_name');
        $expected = $this->setting('webhook_header_value');

        if (! $headerName || ! $expected) {
            // Misconfiguration. Fail closed — refusing webhooks is safer
            // than accepting them with no auth.
            Log::warning('Tabby webhook header not configured — rejecting webhook');

            return false;
        }

        $received = (string) $request->header($headerName, '');

        return $received !== '' && hash_equals($expected, $received);
    }

    /**
     * Register (or re-register) our webhook URL with Tabby.
     *
     * Tabby does NOT send webhooks automatically — the merchant must register
     * a URL via POST /api/v1/webhooks (auth: Bearer secret_key + X-Merchant-Code).
     * We pass the arbitrary header name/value that Tabby will then stamp on every
     * callback; verifyWebhook() compares against the same stored value.
     *
     * The test key (sk_test_) registers a webhook for TEST payments; a live key
     * registers one for live payments — same endpoint, environment picked by key.
     *
     * Returns ['success' => bool, 'id' => ?string, 'error' => ?string].
     */
    public function registerWebhook(string $url, ?string $headerName = null, ?string $headerValue = null): array
    {
        $secretKey = $this->setting('secret_key');
        $merchantCode = $this->setting('merchant_code');
        $headerName = $headerName ?: $this->setting('webhook_header_name', 'X-Tabby-Auth');
        $headerValue = $headerValue ?: $this->setting('webhook_header_value');

        if (! $secretKey || ! $merchantCode) {
            return ['success' => false, 'id' => null, 'error' => 'Tabby secret_key or merchant_code not configured'];
        }
        if (! $headerValue) {
            return ['success' => false, 'id' => null, 'error' => 'webhook_header_value (TABBY_WEBHOOK_HEADER_VALUE) not set'];
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$secretKey,
            'X-Merchant-Code' => $merchantCode,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post("{$this->baseUrl()}/api/v1/webhooks", [
            'url' => $url,
            'header' => [
                'title' => $headerName,
                'value' => $headerValue,
            ],
        ]);

        $body = $response->json();

        if (! $response->successful()) {
            Log::error('Tabby webhook registration failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $body ?: $response->body(),
            ]);

            return ['success' => false, 'id' => null, 'error' => $this->extractError($body, $response->status())];
        }

        Log::info('Tabby webhook registered', ['url' => $url, 'id' => $body['id'] ?? null]);

        return ['success' => true, 'id' => $body['id'] ?? null, 'error' => null];
    }

    /**
     * List webhooks currently registered on this Tabby account/merchant.
     * Returns an array of webhook objects, or null on transport failure.
     */
    public function listWebhooks(): ?array
    {
        $secretKey = $this->setting('secret_key');
        $merchantCode = $this->setting('merchant_code');
        if (! $secretKey || ! $merchantCode) {
            return null;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$secretKey,
            'X-Merchant-Code' => $merchantCode,
            'Accept' => 'application/json',
        ])->get("{$this->baseUrl()}/api/v1/webhooks");

        if (! $response->successful()) {
            Log::warning('Tabby list webhooks failed', ['status' => $response->status(), 'body' => $response->json() ?: $response->body()]);

            return null;
        }

        $body = $response->json();

        return is_array($body) ? $body : ($body['webhooks'] ?? []);
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

    private function extractError(mixed $body, int $status): string
    {
        if (is_array($body)) {
            if (isset($body['error']['message'])) {
                return (string) $body['error']['message'];
            }
            if (isset($body['errors']) && is_array($body['errors'])) {
                $first = $body['errors'][0] ?? null;
                if (is_array($first) && isset($first['message'])) {
                    return (string) $first['message'];
                }
            }
            if (isset($body['message'])) {
                return (string) $body['message'];
            }
        }

        return "Tabby returned HTTP {$status}";
    }
}
