<?php

/**
 * ============================================================
 * PAYMENT CONTROLLER — Payment Processing (Paymennt)
 * ============================================================
 * Handles creating payment sessions with the Paymennt gateway and
 * verifying payment status after redirect-back.
 *
 * Two-stage Shopify-style flow:
 *   1. Frontend POSTs to /api/checkouts → CheckoutController stores
 *      cart in pending_checkouts (no Order yet) and returns a
 *      `reference` (CHK-XXXXXXXX).
 *   2. Frontend POSTs that `reference` to /api/payments/paymennt →
 *      we send Paymennt the cart and return their hosted-page URL.
 *   3. Customer pays → on PAID we convert the PendingCheckout into a
 *      real Order with a real sequential order number (via webhook OR via the
 *      verify endpoint when the customer is redirected back).
 *
 * API endpoints:
 *   POST /api/payments/paymennt          — Create Paymennt hosted checkout
 *   GET  /api/payments/paymennt/verify   — Verify status + convert to Order
 *   POST /api/payments/stripe            — Create Stripe Checkout Session
 *   GET  /api/payments/stripe/verify     — Verify status + convert to Order
 * ============================================================
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\NewOrderAdminNotification;
use App\Mail\OrderConfirmation as OrderConfirmationMail;
use App\Models\IntegrationCredential;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PendingCheckout;
use App\Services\AdminNotifier;
use App\Services\TabbyService;
use App\Services\TamaraService;
use App\Support\Brand;
use App\Support\CountryCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Stripe\StripeClient;

class PaymentController extends Controller
{
    /**
     * Read a Paymennt setting from .env (via config/services.php).
     * Credentials are NOT stored in the DB or admin UI any more — rotate
     * them by editing .env and running `php artisan config:clear`.
     *
     * The $settingKey parameter is kept for callsite compatibility but
     * is no longer consulted; only $configKey matters.
     */
    private function paymenntSetting(string $configKey, ?string $settingKey = null, ?string $default = null): ?string
    {
        unset($settingKey);

        return config("services.paymennt.{$configKey}", $default);
    }

    /**
     * Paymennt API base URL, only ever one of Paymennt's own hosts: the API
     * key/secret are sent there, so an admin-edited or .env value pointing
     * anywhere else falls back to the live host.
     */
    private function paymenntBaseUrl(): string
    {
        $url = IntegrationCredential::safeUrl('paymennt', 'base_url', $this->paymenntSetting('base_url'), 'https://api.paymennt.com');

        return rtrim($url, '/');
    }

    /**
     * Admin on/off switch for a gateway: on unless the setting is exactly
     * 'false' — the same rule the checkout page uses to list it. Only the
     * create-session endpoints check it; verify and webhooks must still
     * complete payments made before the gateway was switched off.
     */
    private function gatewayEnabled(string $gateway): bool
    {
        return Brand::setting("payment_{$gateway}_enabled") !== 'false';
    }

    /**
     * Where the gateway sends the customer back to. Only a URL on this store
     * (APP_URL, FRONTEND_URL or this request's host) is accepted: anything
     * else would make a store-branded payment page end on a stranger's site.
     */
    private function safeReturnUrl(Request $request): string
    {
        $fallback = url('/order-confirmation');
        $candidate = (string) $request->input('returnUrl', '');
        if ($candidate === '') {
            return $fallback;
        }

        $p = parse_url($candidate);
        if (! is_array($p) || ! in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])
            || isset($p['user']) || isset($p['pass'])) {
            return $fallback;
        }

        $allowed = array_filter(array_map(
            fn ($u) => strtolower((string) parse_url((string) $u, PHP_URL_HOST)),
            [config('app.url'), config('app.frontend_url'), $request->getSchemeAndHttpHost()]
        ));

        return in_array(strtolower($p['host']), $allowed, true) ? $candidate : $fallback;
    }

    /**
     * Cash on Delivery, switched on in Admin → Settings → Payment. The order is
     * placed unpaid; the courier collects the cash and the admin marks it paid.
     */
    private function codEnabled(): bool
    {
        return Brand::setting('payment_cod_enabled') === 'true';
    }

    /**
     * GET /api/payments/cod/verify?reference=CHK-XXXXXXXX
     * Places a Cash-on-Delivery order from a staged checkout. UAE addresses only.
     * Mirrors the gateway verify endpoints: idempotently converts the checkout
     * to an Order (payment_status = 'pending', since cash is collected on
     * delivery) and returns the order summary. Convert is race-safe + idempotent.
     */
    public function verifyCod(Request $request)
    {
        if (! $this->codEnabled()) {
            return response()->json(['success' => false, 'error' => 'Cash on Delivery is not available'], 403);
        }

        $reference = $request->query('reference');
        $checkout = PendingCheckout::where('reference', $reference)->first();

        if (! $checkout) {
            return response()->json(['success' => false, 'error' => 'Checkout not found'], 404);
        }

        if (! in_array($checkout->status, ['pending', 'converted'], true)) {
            return response()->json(['success' => false, 'error' => "Checkout is {$checkout->status}, cannot place the order"], 422);
        }
        if (\App\Support\CountryCode::tryToIso2((string) $checkout->shipping_country) !== 'AE') {
            return response()->json(['success' => false, 'error' => 'Cash on Delivery is only available in the UAE'], 422);
        }

        $order = $this->convertCheckoutToOrder($checkout, 'cod', null, null, 'pending');

        return response()->json([
            'success' => true,
            'paymentStatus' => 'pending',
            'orderStatus' => $order->status,
            'order' => $this->orderSummary($order),
        ]);
    }

    /**
     * POST /api/payments/paymennt
     * Creates a Paymennt hosted web checkout session for a PendingCheckout.
     * Body: { reference: "CHK-XXXXXXXX", returnUrl?: "..." }
     *
     * No Order is created here — the order is built on PAID confirmation.
     */
    public function paymennt(Request $request)
    {
        $reference = $request->input('reference') ?: $request->input('orderId'); // accept either
        if (! $reference) {
            return response()->json(['success' => false, 'error' => 'reference is required'], 422);
        }

        $checkout = PendingCheckout::where('reference', $reference)->first();
        if (! $checkout) {
            return response()->json(['success' => false, 'error' => 'Checkout not found'], 404);
        }
        if ($checkout->status !== 'pending') {
            return response()->json(['success' => false, 'error' => "Checkout is {$checkout->status}, cannot pay"], 422);
        }
        if (! $this->gatewayEnabled('paymennt')) {
            return response()->json(['success' => false, 'error' => 'Paymennt is not available'], 422);
        }

        $apiKey = $this->paymenntSetting('api_key', 'PAYMENNT_API_KEY');
        $apiSecret = $this->paymenntSetting('api_secret', 'PAYMENNT_API_SECRET');
        $baseUrl = $this->paymenntBaseUrl();

        if (! $apiKey || ! $apiSecret) {
            return response()->json([
                'success' => false,
                'error' => 'Paymennt credentials not configured',
            ], 500);
        }

        // If we already created a Paymennt checkout for this reference and
        // it's still PENDING, reuse the existing redirect URL instead of
        // creating a duplicate (e.g. customer hit Pay twice).
        if ($checkout->paymennt_checkout_id && $checkout->paymennt_redirect_url) {
            return response()->json([
                'success' => true,
                'checkoutUrl' => $checkout->paymennt_redirect_url,
                'checkoutId' => $checkout->paymennt_checkout_id,
                'reference' => $checkout->reference,
            ]);
        }

        $returnUrl = $this->safeReturnUrl($request);
        $separator = str_contains($returnUrl, '?') ? '&' : '?';
        $returnUrlWithRef = "{$returnUrl}{$separator}reference={$reference}&gateway=paymennt";

        // Paymennt requires ISO-2 country codes (length 2-3). The
        // checkout form sends full country names like "Saudi Arabia",
        // so we normalize here. Already-ISO-2 inputs ("AE") pass
        // through unchanged.
        $shippingCountry = $this->normalizeCountryToIso2(
            $checkout->shipping_country ?: 'AE'
        );

        // Paymennt rejects blank city/state. Fall back to a sensible
        // capital/main city per country if the customer didn't supply one.
        $shippingCity = trim((string) $checkout->shipping_city);
        $shippingState = trim((string) $checkout->shipping_state);
        $cityFallbacks = [
            'AE' => 'Dubai', 'SA' => 'Riyadh', 'KW' => 'Kuwait City',
            'QA' => 'Doha', 'BH' => 'Manama', 'OM' => 'Muscat',
            'JO' => 'Amman', 'EG' => 'Cairo', 'LB' => 'Beirut',
        ];
        $countryFallback = $cityFallbacks[$shippingCountry] ?? 'City';
        if ($shippingCity === '') {
            $shippingCity = $countryFallback;
        }
        if ($shippingState === '') {
            $shippingState = $shippingCity;
        }

        $billingName = $checkout->customer_name ?: 'Customer';
        $address = [
            'name' => $billingName,
            'address1' => $checkout->shipping_address ?? '',
            'city' => $shippingCity,
            'state' => $shippingState,
            'zip' => $checkout->shipping_zip ?? '',
            'country' => $shippingCountry,
        ];

        $nameParts = preg_split('/\s+/', trim($billingName), 2);
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? $firstName;

        $payload = [
            'requestId' => $reference.'.'.substr((string) Str::uuid(), 0, 8),
            'orderId' => $reference,
            // Stored prices (and so the checkout total) are in the store's
            // base currency — charge in that, like Stripe does.
            'currency' => Brand::currency(),
            'amount' => round((float) $checkout->total, 2),
            'returnUrl' => $returnUrlWithRef,
            'customer' => [
                'firstName' => $firstName,
                'lastName' => $lastName,
                'email' => $checkout->customer_email,
                'phone' => $checkout->customer_phone ?? '',
            ],
            'billingAddress' => $address,
            'deliveryAddress' => $address,
            'items' => array_map(static function ($item) {
                $quantity = (int) ($item['quantity'] ?? 1);
                $unit = round((float) ($item['price'] ?? 0), 2);

                $line = [
                    'name' => $item['product_name'] ?? $item['name'] ?? 'Item',
                    'quantity' => $quantity,
                    'unitprice' => $unit,
                    'linetotal' => round($unit * $quantity, 2),
                ];

                // Paymennt's hosted page renders a thumbnail when imageUrl is supplied.
                // Field must be an absolute URL (relative paths are silently ignored).
                $image = $item['image_url'] ?? null;
                if ($image && str_starts_with($image, 'http')) {
                    $line['imageUrl'] = $image;
                }

                return $line;
            }, $checkout->items ?? []),
        ];

        Log::info('Paymennt checkout request', [
            'reference' => $reference,
            'amount' => $payload['amount'],
        ]);

        $response = Http::withHeaders([
            'X-Paymennt-Api-Key' => $apiKey,
            'X-Paymennt-Api-Secret' => $apiSecret,
            'Content-Type' => 'application/json',
        ])->post("{$baseUrl}/mer/v2.0/checkout/web", $payload);

        if (! $response->successful()) {
            Log::error('Paymennt checkout failed', [
                'reference' => $reference,
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to create Paymennt checkout session',
                'details' => $response->json() ?? $response->body(),
            ], 500);
        }

        $body = $response->json();
        $result = $body['result'] ?? $body;
        $checkoutUrl = $result['redirectUrl'] ?? null;
        $checkoutId = $result['id'] ?? null;

        if (! $checkoutUrl) {
            Log::error('Paymennt response missing redirectUrl', ['body' => $body]);

            return response()->json([
                'success' => false,
                'error' => 'Paymennt response missing redirectUrl',
            ], 500);
        }

        $checkout->update([
            'paymennt_checkout_id' => $checkoutId,
            'paymennt_redirect_url' => $checkoutUrl,
        ]);

        return response()->json([
            'success' => true,
            'checkoutUrl' => $checkoutUrl,
            'checkoutId' => $checkoutId,
            'reference' => $reference,
        ]);
    }

    /**
     * GET /api/payments/paymennt/verify?reference=CHK-XXXXXXXX
     * Called by the OrderConfirmation page after the customer is
     * redirected back. We ask Paymennt directly for the canonical
     * status, and on PAID we convert the PendingCheckout into a real
     * Order (if not already done by the webhook).
     *
     * Returns the order summary for the confirmation page.
     */
    public function verifyPaymennt(Request $request)
    {
        $reference = $request->query('reference') ?: $request->query('orderId');
        if (! $reference) {
            return response()->json(['success' => false, 'error' => 'reference is required'], 400);
        }

        $checkout = PendingCheckout::where('reference', $reference)->first();
        if (! $checkout) {
            // Maybe the webhook already converted it — try by order_number
            $order = Order::where('order_number', $reference)->first();
            if ($order) {
                return response()->json([
                    'success' => true,
                    'paymentStatus' => $order->payment_status,
                    'orderStatus' => $order->status,
                    'order' => $this->orderSummary($order),
                ]);
            }

            return response()->json(['success' => false, 'error' => 'Checkout not found'], 404);
        }

        // Already converted — return the resulting Order
        if ($checkout->status === 'converted' && $checkout->order_number) {
            $order = Order::where('order_number', $checkout->order_number)->first();

            return response()->json([
                'success' => true,
                'paymentStatus' => 'paid',
                'orderStatus' => $order?->status,
                'order' => $order ? $this->orderSummary($order) : null,
            ]);
        }

        if (! $checkout->paymennt_checkout_id) {
            return response()->json([
                'success' => true,
                'paymentStatus' => 'pending',
                'orderStatus' => null,
                'order' => null,
            ]);
        }

        $checkoutData = $this->fetchPaymenntCheckout($checkout->paymennt_checkout_id);
        if ($checkoutData === null) {
            return response()->json(['success' => false, 'error' => 'Failed to verify Paymennt checkout'], 500);
        }

        $gatewayStatus = strtoupper((string) ($checkoutData['status'] ?? ''));
        $paymentStatus = $this->mapGatewayStatus($gatewayStatus);

        if ($paymentStatus === 'paid') {
            $order = $this->convertCheckoutToOrder($checkout, 'paymennt', null, $checkoutData['usedPaymentMethod'] ?? null);

            return response()->json([
                'success' => true,
                'paymentStatus' => 'paid',
                'gatewayStatus' => $gatewayStatus,
                'orderStatus' => $order->status,
                'order' => $this->orderSummary($order),
            ]);
        }

        if ($paymentStatus === 'failed') {
            $this->markCheckoutFailed($checkout);
        }

        return response()->json([
            'success' => true,
            'paymentStatus' => $paymentStatus,
            'gatewayStatus' => $gatewayStatus,
            'orderStatus' => null,
            'order' => null,
        ]);
    }

    /**
     * Fetch the canonical checkout status directly from Paymennt's API.
     * Returns the uppercase status string ("PAID", "PENDING", "FAILED",
     * "EXPIRED", etc.) on success, or null if Paymennt couldn't be reached
     * or credentials aren't configured.
     *
     * Used by both verifyPaymennt() (after redirect-back) and the webhook
     * handler (which must re-fetch rather than trust the request body).
     */
    public function fetchPaymenntStatus(string $checkoutId): ?string
    {
        $result = $this->fetchPaymenntCheckout($checkoutId);

        return $result === null ? null : strtoupper((string) ($result['status'] ?? ''));
    }

    /**
     * Fetch the full Paymennt checkout object (the `result` payload), which
     * includes `status` and `usedPaymentMethod` (CARD / APPLE_PAY / GOOGLE_PAY …).
     * Returns null if Paymennt couldn't be reached or credentials are missing.
     *
     * @return array<string,mixed>|null
     */
    public function fetchPaymenntCheckout(string $checkoutId): ?array
    {
        $apiKey = $this->paymenntSetting('api_key', 'PAYMENNT_API_KEY');
        $apiSecret = $this->paymenntSetting('api_secret', 'PAYMENNT_API_SECRET');
        $baseUrl = $this->paymenntBaseUrl();

        if (! $apiKey || ! $apiSecret) {
            Log::error('Paymennt credentials not configured for status fetch');

            return null;
        }

        $response = Http::withHeaders([
            'X-Paymennt-Api-Key' => $apiKey,
            'X-Paymennt-Api-Secret' => $apiSecret,
        ])->get("{$baseUrl}/mer/v2.0/checkout/{$checkoutId}");

        if (! $response->successful()) {
            Log::error('Paymennt status fetch failed', [
                'checkoutId' => $checkoutId,
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            return null;
        }

        $body = $response->json();

        return $body['result'] ?? $body;
    }

    /**
     * Human-friendly label for what the customer paid with, shown in the
     * order timeline/details. BNPL gateways are named directly; Paymennt
     * card/wallet payments are mapped from its `usedPaymentMethod` value.
     */
    public function paymentLabel(string $gateway, ?string $instrument = null): string
    {
        if ($gateway === 'tabby') {
            return 'Tabby';
        }
        if ($gateway === 'tamara') {
            return 'Tamara';
        }
        if ($gateway === 'cod') {
            return 'Cash on Delivery';
        }

        $i = strtoupper((string) $instrument);

        return match (true) {
            str_contains($i, 'APPLE') => 'Apple Pay',
            str_contains($i, 'GOOGLE') => 'Google Pay',
            str_contains($i, 'SAMSUNG') => 'Samsung Pay',
            $i === 'CARD' || str_contains($i, 'CARD') => 'Card',
            $i !== '' => ucwords(strtolower(str_replace('_', ' ', $i))),
            default => 'Card',
        };
    }

    /**
     * Refund a Paymennt checkout. Pass null amount for a full refund;
     * otherwise pass the AED amount to refund (partial refund).
     *
     * Paymennt's request fields (discovered empirically — their public
     * docs don't enumerate them):
     *   - note (REQUIRED): free-text refund reason. Sent as a QUERY
     *     PARAMETER, not a body field. Their backend's error message
     *     "Required request parameter 'note' for method parameter type
     *     String is not present" is the Spring framework signature for
     *     missing @RequestParam — confirming it expects a query string.
     *   - amount (optional): partial refund amount; omit for full refund.
     *
     * Returns ['success' => bool, 'gatewayStatus' => ?string, 'error' => ?string].
     * On any error we return success=false rather than throw, so the caller
     * can decide whether to still mark the order as cancelled locally.
     */
    public function refundPaymennt(string $checkoutId, ?float $amount = null, ?string $note = null): array
    {
        $apiKey = $this->paymenntSetting('api_key', 'PAYMENNT_API_KEY');
        $apiSecret = $this->paymenntSetting('api_secret', 'PAYMENNT_API_SECRET');
        $baseUrl = $this->paymenntBaseUrl();

        if (! $apiKey || ! $apiSecret) {
            Log::error('Paymennt credentials not configured for refund');

            return ['success' => false, 'gatewayStatus' => null, 'error' => 'Paymennt credentials not configured'];
        }

        $query = [
            'note' => $note !== null && $note !== '' ? $note : 'Order cancelled by admin',
        ];
        if ($amount !== null) {
            $query['amount'] = round($amount, 2);
        }

        $url = "{$baseUrl}/mer/v2.0/checkout/{$checkoutId}/refund?".http_build_query($query);

        $response = Http::withHeaders([
            'X-Paymennt-Api-Key' => $apiKey,
            'X-Paymennt-Api-Secret' => $apiSecret,
        ])->post($url);

        $payload = $response->json() ?? [];

        if (! $response->successful()) {
            Log::error('Paymennt refund failed', [
                'checkoutId' => $checkoutId,
                'amount' => $amount,
                'status' => $response->status(),
                'body' => $payload ?: $response->body(),
            ]);

            $error = $payload['error'] ?? $payload['message'] ?? "Paymennt returned HTTP {$response->status()}";

            return ['success' => false, 'gatewayStatus' => null, 'error' => $error];
        }

        Log::info('Paymennt refund accepted', [
            'checkoutId' => $checkoutId,
            'amount' => $amount,
            'response' => $payload,
        ]);

        $result = $payload['result'] ?? $payload;
        $status = strtoupper((string) ($result['status'] ?? ''));

        return ['success' => true, 'gatewayStatus' => $status, 'error' => null];
    }

    /**
     * Map Paymennt's status vocabulary to our internal payment_status values.
     */
    public function mapGatewayStatus(string $gatewayStatus): string
    {
        return match ($gatewayStatus) {
            'PAID', 'AUTHORIZED', 'CAPTURED' => 'paid',
            'CANCELLED', 'CANCELED', 'EXPIRED', 'FAILED', 'DECLINED' => 'failed',
            default => 'pending',
        };
    }

    // ===================== Tabby =====================

    /**
     * POST /api/payments/tabby/prescore
     * Body: { amount, currency?, email, phone, name?, country?, city?, address?, zip? }
     *
     * Tabby "pre-scoring": called by the Checkout page BEFORE Tabby is shown
     * as an option, once the cart total + customer email/phone are known.
     * Returns whether the customer is eligible and, if not, Tabby's rejection
     * reason so the frontend can disable the option and show the right message.
     *
     * This is NOT a payment action (no checkout is staged, nothing is charged),
     * so it lives outside the strict payments throttle — it re-runs whenever the
     * cart or contact details change.
     */
    public function tabbyPrescore(Request $request, TabbyService $tabby)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'email' => 'required|email',
            'phone' => 'nullable|string',
            'name' => 'nullable|string',
            'country' => 'nullable|string',
            'city' => 'nullable|string',
            'address' => 'nullable|string',
            'zip' => 'nullable|string',
            'lang' => 'nullable|string',
        ]);

        $result = $tabby->prescore($validated);

        return response()->json([
            'success' => true,
            'eligible' => $result['eligible'],
            'reason' => $result['reason'],
        ]);
    }

    /**
     * POST /api/payments/tabby
     * Body: { reference: "CHK-XXXXXXXX", returnUrl?: "..." }
     *
     * Creates a Tabby checkout session for a staged PendingCheckout
     * and returns the redirect URL. Mirrors paymennt() exactly.
     */
    public function tabby(Request $request, TabbyService $tabby)
    {
        $reference = $request->input('reference') ?: $request->input('orderId');
        if (! $reference) {
            return response()->json(['success' => false, 'error' => 'reference is required'], 422);
        }

        $checkout = PendingCheckout::where('reference', $reference)->first();
        if (! $checkout) {
            return response()->json(['success' => false, 'error' => 'Checkout not found'], 404);
        }
        if ($checkout->status !== 'pending') {
            return response()->json(['success' => false, 'error' => "Checkout is {$checkout->status}, cannot pay"], 422);
        }
        if (! $this->gatewayEnabled('tabby')) {
            return response()->json(['success' => false, 'error' => 'Tabby is not available'], 422);
        }

        // Idempotency guard — prevent two concurrent /tabby calls for the
        // same checkout creating two Tabby sessions if a customer rapid-
        // clicks the Pay button. The lock is per-checkout, ~10s, atomic.
        $lock = \Illuminate\Support\Facades\Cache::lock("payment:tabby:{$reference}", 10);
        if (! $lock->get()) {
            return response()->json([
                'success' => false,
                'error' => 'A Tabby session is already being created for this checkout. Please wait.',
            ], 409);
        }

        try {
            $returnUrl = $this->safeReturnUrl($request);

            $result = $tabby->createCheckout($checkout, $returnUrl);
            if (! $result['success']) {
                return response()->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Tabby session could not be created',
                    'rejection' => $result['rejection'] ?? null,
                ], $result['rejection'] ? 422 : 500);
            }

            $checkout->update([
                'tabby_payment_id' => $result['id'],
                'gateway_currency' => $result['currency'] ?? null,
                'gateway_amount' => $result['amount'] ?? null,
            ]);

            return response()->json([
                'success' => true,
                'checkoutUrl' => $result['url'],
                'paymentId' => $result['id'],
                'reference' => $checkout->reference,
            ]);
        } finally {
            $lock->release();
        }
    }

    /**
     * GET /api/payments/tabby/verify?reference=CHK-...
     * Polled by OrderConfirmation after redirect-back. Re-fetches
     * Tabby's status; on AUTHORIZED converts the checkout to an Order.
     */
    public function verifyTabby(Request $request, TabbyService $tabby)
    {
        $reference = $request->query('reference');
        if (! $reference) {
            return response()->json(['success' => false, 'error' => 'reference is required'], 422);
        }

        $checkout = PendingCheckout::where('reference', $reference)->first();
        if (! $checkout) {
            return response()->json(['success' => false, 'error' => 'Checkout not found'], 404);
        }

        // Already converted — return the live Order summary
        if ($checkout->status === 'converted' && $checkout->order_number) {
            $order = Order::where('order_number', $checkout->order_number)->first();

            return response()->json([
                'success' => true,
                'paymentStatus' => 'paid',
                'gatewayStatus' => 'AUTHORIZED',
                'orderStatus' => $order?->status,
                'order' => $order ? $this->orderSummary($order) : null,
            ]);
        }

        if (! $checkout->tabby_payment_id) {
            return response()->json(['success' => false, 'error' => 'No Tabby payment id on this checkout'], 422);
        }

        // We stored the Tabby checkout SESSION id (returned by POST /checkout).
        // Tabby exposes the live status via GET /checkout/{id}, where `payment.id`
        // is the actual payment object id used for capture/refund and `payment.status`
        // is the gateway status. Hitting /payments/{sessionId} returns 404.
        $session = $tabby->fetchCheckout($checkout->tabby_payment_id);
        if ($session === null) {
            return response()->json(['success' => false, 'error' => 'Failed to verify Tabby payment'], 500);
        }

        $gatewayStatus = strtoupper((string) ($session['payment']['status'] ?? ''));
        $realPaymentId = (string) ($session['payment']['id'] ?? '');

        $paymentStatus = $gatewayStatus !== '' ? $tabby->mapStatus($gatewayStatus) : 'pending';

        if ($paymentStatus === 'paid' && $realPaymentId !== '') {
            // Persist the real payment id on the checkout so future refunds resolve correctly,
            // even though convertCheckoutToOrder will copy it onto Order.payment_reference.
            $checkout->update(['tabby_payment_id' => $realPaymentId]);

            $order = $this->convertCheckoutToOrder($checkout->fresh(), 'tabby', $realPaymentId);

            // Tabby docs: after AUTHORIZED, capture the payment. captureAndClose()
            // is idempotent (short-circuits if already CLOSED / auto-captured), so
            // this is safe to call from both verify and webhook paths. The order is
            // already placed — a capture hiccup must not fail the request.
            try {
                $tabby->captureAndClose($realPaymentId, (float) ($order->gateway_amount ?? $order->total));
            } catch (\Throwable $e) {
                Log::warning('Tabby capture-after-authorize failed (order already placed)', [
                    'reference' => $checkout->reference,
                    'paymentId' => $realPaymentId,
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'paymentStatus' => 'paid',
                'gatewayStatus' => $gatewayStatus,
                'orderStatus' => $order->status,
                'order' => $this->orderSummary($order),
            ]);
        }

        if ($paymentStatus === 'failed') {
            $this->markCheckoutFailed($checkout);
        }

        return response()->json([
            'success' => true,
            'paymentStatus' => $paymentStatus,
            'gatewayStatus' => $gatewayStatus,
            'orderStatus' => null,
            'order' => null,
        ]);
    }

    // ===================== Tamara =====================

    /**
     * POST /api/payments/tamara
     * Body: { reference: "CHK-XXXXXXXX", returnUrl?: "..." }
     *
     * Creates a Tamara Pay-in-3 session for a staged PendingCheckout.
     */
    public function tamara(Request $request, TamaraService $tamara)
    {
        $reference = $request->input('reference') ?: $request->input('orderId');
        if (! $reference) {
            return response()->json(['success' => false, 'error' => 'reference is required'], 422);
        }

        $checkout = PendingCheckout::where('reference', $reference)->first();
        if (! $checkout) {
            return response()->json(['success' => false, 'error' => 'Checkout not found'], 404);
        }
        if ($checkout->status !== 'pending') {
            return response()->json(['success' => false, 'error' => "Checkout is {$checkout->status}, cannot pay"], 422);
        }
        if (! $this->gatewayEnabled('tamara')) {
            return response()->json(['success' => false, 'error' => 'Tamara is not available'], 422);
        }

        // Idempotency guard — see tabby() above.
        $lock = \Illuminate\Support\Facades\Cache::lock("payment:tamara:{$reference}", 10);
        if (! $lock->get()) {
            return response()->json([
                'success' => false,
                'error' => 'A Tamara session is already being created for this checkout. Please wait.',
            ], 409);
        }

        try {
            $returnUrl = $this->safeReturnUrl($request);
            $notificationUrl = url('/api/webhooks/tamara');

            $result = $tamara->createCheckout($checkout, $returnUrl, $notificationUrl);
            if (! $result['success']) {
                return response()->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Tamara session could not be created',
                ], 500);
            }

            $checkout->update([
                'tamara_order_id' => $result['id'],
                'gateway_currency' => $result['currency'] ?? null,
                'gateway_amount' => $result['amount'] ?? null,
            ]);

            return response()->json([
                'success' => true,
                'checkoutUrl' => $result['url'],
                'orderId' => $result['id'],
                'reference' => $checkout->reference,
            ]);
        } finally {
            $lock->release();
        }
    }

    /**
     * GET /api/payments/tamara/verify?reference=CHK-...
     */
    public function verifyTamara(Request $request, TamaraService $tamara)
    {
        $reference = $request->query('reference');
        if (! $reference) {
            return response()->json(['success' => false, 'error' => 'reference is required'], 422);
        }

        $checkout = PendingCheckout::where('reference', $reference)->first();
        if (! $checkout) {
            return response()->json(['success' => false, 'error' => 'Checkout not found'], 404);
        }

        if ($checkout->status === 'converted' && $checkout->order_number) {
            $order = Order::where('order_number', $checkout->order_number)->first();

            return response()->json([
                'success' => true,
                'paymentStatus' => 'paid',
                'gatewayStatus' => 'APPROVED',
                'orderStatus' => $order?->status,
                'order' => $order ? $this->orderSummary($order) : null,
            ]);
        }

        if (! $checkout->tamara_order_id) {
            return response()->json(['success' => false, 'error' => 'No Tamara order id on this checkout'], 422);
        }

        $gatewayStatus = $tamara->fetchStatus($checkout->tamara_order_id);
        if ($gatewayStatus === null) {
            return response()->json(['success' => false, 'error' => 'Failed to verify Tamara order'], 500);
        }

        $paymentStatus = $tamara->mapStatus($gatewayStatus);

        if ($paymentStatus === 'paid') {
            $order = $this->convertCheckoutToOrder($checkout, 'tamara', $checkout->tamara_order_id);

            return response()->json([
                'success' => true,
                'paymentStatus' => 'paid',
                'gatewayStatus' => $gatewayStatus,
                'orderStatus' => $order->status,
                'order' => $this->orderSummary($order),
            ]);
        }

        if ($paymentStatus === 'failed') {
            $this->markCheckoutFailed($checkout);
        }

        return response()->json([
            'success' => true,
            'paymentStatus' => $paymentStatus,
            'gatewayStatus' => $gatewayStatus,
            'orderStatus' => null,
            'order' => null,
        ]);
    }

    // ===================== Stripe =====================

    /**
     * Currencies Stripe expects in whole units (no minor unit) and the
     * three-decimal ones whose minor amount must be a multiple of 10.
     * https://docs.stripe.com/currencies#special-cases
     */
    private const STRIPE_ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    private const STRIPE_THREE_DECIMAL = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    /**
     * The Stripe API client. Resolved from the container when something has
     * bound one (the tests do, to keep the network out), otherwise built from
     * the configured secret key. Returns null when Stripe isn't configured.
     */
    public function stripeClient(): ?StripeClient
    {
        if (app()->bound(StripeClient::class)) {
            return app(StripeClient::class);
        }

        $secret = config('services.stripe.secret');

        return $secret ? new StripeClient(['api_key' => $secret]) : null;
    }

    /**
     * Admin toggle — Stripe is opt-in, off unless the setting is exactly 'true'.
     */
    private function stripeEnabled(): bool
    {
        return Brand::setting('payment_stripe_enabled') === 'true';
    }

    /**
     * Convert a store-currency amount to Stripe's integer minor units.
     */
    private function stripeMinorUnits(float $amount, string $currency): int
    {
        if (in_array($currency, self::STRIPE_ZERO_DECIMAL, true)) {
            return (int) round($amount);
        }
        if (in_array($currency, self::STRIPE_THREE_DECIMAL, true)) {
            return (int) round(round($amount, 2) * 100) * 10;
        }

        return (int) round($amount * 100);
    }

    /**
     * Build Checkout Session line items for a staged checkout.
     *
     * Itemised lines (products + shipping + gift wrap) are used only when they
     * add up to the staged total to the minor unit. A discount cannot be sent
     * as a negative line, and rounding can drift — in either case we fall back
     * to ONE line for the exact staged total, so the customer is never charged
     * a different amount than the checkout said.
     */
    private function stripeLineItems(PendingCheckout $checkout, string $currency): array
    {
        $total = $this->stripeMinorUnits((float) $checkout->total, $currency);
        $lines = [];
        $sum = 0;

        foreach ($checkout->items ?? [] as $item) {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $unit = $this->stripeMinorUnits((float) ($item['price'] ?? 0), $currency);

            $productData = ['name' => (string) ($item['product_name'] ?? $item['name'] ?? 'Item')];
            $image = $item['image_url'] ?? null;
            if ($image && str_starts_with($image, 'https://')) {
                $productData['images'] = [$image];
            }

            $lines[] = [
                'quantity' => $quantity,
                'price_data' => [
                    'currency' => strtolower($currency),
                    'unit_amount' => $unit,
                    'product_data' => $productData,
                ],
            ];
            $sum += $unit * $quantity;
        }

        $extras = [
            'Shipping' => (float) $checkout->shipping_cost,
            'Gift wrapping' => (float) $checkout->gift_fee,
        ];
        foreach ($extras as $name => $amount) {
            $minor = $this->stripeMinorUnits($amount, $currency);
            if ($minor > 0) {
                $lines[] = [
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($currency),
                        'unit_amount' => $minor,
                        'product_data' => ['name' => $name],
                    ],
                ];
                $sum += $minor;
            }
        }

        $hasDiscount = (float) $checkout->discount > 0;
        if ($lines !== [] && ! $hasDiscount && $sum === $total) {
            return $lines;
        }

        return [[
            'quantity' => 1,
            'price_data' => [
                'currency' => strtolower($currency),
                'unit_amount' => $total,
                'product_data' => ['name' => Brand::name().' order '.$checkout->reference],
            ],
        ]];
    }

    /**
     * Map a Checkout Session to our internal payment status.
     * `no_payment_required` happens for a 100%-discounted order.
     */
    private function mapStripeSessionStatus(object $session): string
    {
        $paymentStatus = (string) ($session->payment_status ?? '');
        if (in_array($paymentStatus, ['paid', 'no_payment_required'], true)) {
            return 'paid';
        }

        return ($session->status ?? null) === 'expired' ? 'failed' : 'pending';
    }

    /**
     * The PaymentIntent id behind a session (string or expanded object).
     */
    public function stripePaymentIntentId(object $session): ?string
    {
        $intent = $session->payment_intent ?? null;
        if (is_object($intent)) {
            $intent = $intent->id ?? null;
        }

        return $intent ? (string) $intent : null;
    }

    /**
     * Refund a Stripe order in full. `$reference` is the order's
     * payment_reference: normally the PaymentIntent id (pi_…), or the
     * Checkout Session id (cs_…) when the session had no intent attached at
     * conversion time — then the intent is looked up from the session.
     *
     * Same contract as refundPaymennt(): never throws, returns
     * ['success' => bool, 'gatewayStatus' => ?string, 'error' => ?string].
     */
    public function refundStripe(string $reference, ?string $note = null): array
    {
        $stripe = $this->stripeClient();
        if (! $stripe) {
            return ['success' => false, 'gatewayStatus' => null, 'error' => 'Stripe is not configured'];
        }

        try {
            $intentId = $reference;
            if (str_starts_with($reference, 'cs_')) {
                $session = $stripe->checkout->sessions->retrieve($reference);
                $intentId = $this->stripePaymentIntentId($session);
                if (! $intentId) {
                    // A fully discounted order never took a payment — nothing to refund.
                    if (($session->payment_status ?? null) === 'no_payment_required') {
                        return ['success' => true, 'gatewayStatus' => 'NO_PAYMENT', 'error' => null];
                    }

                    return ['success' => false, 'gatewayStatus' => null, 'error' => 'Stripe session has no payment to refund'];
                }
            }

            $params = ['payment_intent' => $intentId, 'reason' => 'requested_by_customer'];
            if ($note !== null && $note !== '') {
                $params['metadata'] = ['note' => mb_substr($note, 0, 500)];
            }

            // Keyed on the PaymentIntent: a repeated call (double-clicked
            // cancel) gets Stripe's original refund back instead of an
            // "already refunded" error.
            $refund = $stripe->refunds->create($params, ['idempotency_key' => 'refund-'.$intentId]);
            $status = strtoupper((string) ($refund->status ?? ''));

            Log::info('Stripe refund created', ['reference' => $reference, 'refund' => $refund->id ?? null, 'status' => $status]);

            if (in_array($status, ['FAILED', 'CANCELED'], true)) {
                return ['success' => false, 'gatewayStatus' => $status, 'error' => "Stripe refund {$status}"];
            }

            return ['success' => true, 'gatewayStatus' => $status, 'error' => null];
        } catch (\Throwable $e) {
            Log::error('Stripe refund failed', ['reference' => $reference, 'error' => $e->getMessage()]);

            return ['success' => false, 'gatewayStatus' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * POST /api/payments/stripe
     * Body: { reference: "CHK-XXXXXXXX", returnUrl?: "..." }
     *
     * Creates a Stripe Checkout Session for a staged PendingCheckout and
     * returns the hosted-page URL. Mirrors paymennt()/tabby()/tamara().
     */
    public function stripe(Request $request)
    {
        $reference = $request->input('reference') ?: $request->input('orderId');
        if (! $reference) {
            return response()->json(['success' => false, 'error' => 'reference is required'], 422);
        }

        $checkout = PendingCheckout::where('reference', $reference)->first();
        if (! $checkout) {
            return response()->json(['success' => false, 'error' => 'Checkout not found'], 404);
        }
        if ($checkout->status !== 'pending') {
            return response()->json(['success' => false, 'error' => "Checkout is {$checkout->status}, cannot pay"], 422);
        }
        if (! $this->stripeEnabled()) {
            return response()->json(['success' => false, 'error' => 'Stripe is not available'], 422);
        }

        $stripe = $this->stripeClient();
        if (! $stripe) {
            return response()->json(['success' => false, 'error' => 'Stripe credentials not configured'], 500);
        }

        // Idempotency guard — see tabby() above.
        $lock = \Illuminate\Support\Facades\Cache::lock("payment:stripe:{$reference}", 10);
        if (! $lock->get()) {
            return response()->json([
                'success' => false,
                'error' => 'A Stripe session is already being created for this checkout. Please wait.',
            ], 409);
        }

        try {
            $returnUrl = $this->safeReturnUrl($request);
            $separator = str_contains($returnUrl, '?') ? '&' : '?';
            // {CHECKOUT_SESSION_ID} is substituted by Stripe — keep the braces unencoded.
            $successUrl = "{$returnUrl}{$separator}reference={$reference}&gateway=stripe&session_id={CHECKOUT_SESSION_ID}";

            if ($checkout->stripe_session_id) {
                try {
                    $existing = $stripe->checkout->sessions->retrieve($checkout->stripe_session_id);
                    $existingStatus = $existing->status ?? null;

                    // Already paid (the redirect and/or webhook were lost):
                    // convert now instead of opening a second, chargeable session.
                    if ($this->mapStripeSessionStatus($existing) === 'paid') {
                        $this->convertCheckoutToOrder($checkout, 'stripe', $this->stripePaymentIntentId($existing) ?? $existing->id);

                        return response()->json([
                            'success' => true,
                            // The frontend just redirects; OrderConfirmation → verifyStripe returns the order.
                            'checkoutUrl' => str_replace('{CHECKOUT_SESSION_ID}', (string) $existing->id, $successUrl),
                            'sessionId' => $existing->id,
                            'reference' => $checkout->reference,
                        ]);
                    }

                    // Completed but unpaid = an async payment method is still
                    // processing; never start a second charge alongside it.
                    if ($existingStatus === 'complete') {
                        return response()->json([
                            'success' => false,
                            'error' => 'A payment for this checkout is still processing. Please wait for confirmation before paying again.',
                        ], 409);
                    }

                    // Customer hit Pay twice — reuse the still-open session.
                    if ($existingStatus === 'open' && ! empty($existing->url)) {
                        return response()->json([
                            'success' => true,
                            'checkoutUrl' => $existing->url,
                            'sessionId' => $existing->id,
                            'reference' => $checkout->reference,
                        ]);
                    }

                    // 'expired' → fall through and create a new session.
                    Log::info('Stripe session expired, replacing it', [
                        'reference' => $reference,
                        'previous_session' => $existing->id ?? $checkout->stripe_session_id,
                    ]);
                } catch (\Stripe\Exception\ApiErrorException $e) {
                    Log::info('Stripe session reuse failed, creating a new one', [
                        'reference' => $reference,
                        'previous_session' => $checkout->stripe_session_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $parts = parse_url($returnUrl);
            $origin = isset($parts['scheme'], $parts['host'])
                ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
                : rtrim(url('/'), '/');
            $cancelUrl = "{$origin}/checkout?reference={$reference}&cancelled=stripe";

            $currency = Brand::currency();

            // Stripe's default session lifetime is 24h, far beyond the stock
            // reservation. Keep the hosted page alive only as long as the
            // reservation (Stripe's minimum is 30 minutes) and extend the
            // reservation to cover it, so a late payment can't oversell.
            $sessionMinutes = max(30, (int) config('app.checkout_reservation_minutes', 10));
            $sessionExpiresAt = now()->addMinutes($sessionMinutes);

            $params = [
                'mode' => 'payment',
                'client_reference_id' => $reference,
                'line_items' => $this->stripeLineItems($checkout, $currency),
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'expires_at' => $sessionExpiresAt->timestamp,
                'metadata' => ['reference' => $reference],
                'payment_intent_data' => ['metadata' => ['reference' => $reference]],
            ];
            if ($checkout->customer_email) {
                $params['customer_email'] = $checkout->customer_email;
            }

            Log::info('Stripe checkout request', [
                'reference' => $reference,
                'amount' => (float) $checkout->total,
                'currency' => $currency,
            ]);

            try {
                $session = $stripe->checkout->sessions->create($params);
            } catch (\Stripe\Exception\ApiErrorException $e) {
                Log::error('Stripe checkout failed', [
                    'reference' => $reference,
                    'status' => $e->getHttpStatus(),
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Failed to create Stripe checkout session',
                    'details' => $e->getMessage(),
                ], 500);
            }

            if (empty($session->url)) {
                Log::error('Stripe response missing url', ['reference' => $reference, 'session' => $session->id ?? null]);

                return response()->json(['success' => false, 'error' => 'Stripe response missing url'], 500);
            }

            // One spare minute lets a payment made in the session's last
            // seconds land its webhook before the abandoned sweep runs.
            $reservationEnd = $sessionExpiresAt->copy()->addMinute();
            $checkout->update([
                'stripe_session_id' => $session->id,
                'gateway_currency' => $currency,
                'gateway_amount' => round((float) $checkout->total, 2),
                'expires_at' => $checkout->expires_at && $checkout->expires_at->gt($reservationEnd)
                    ? $checkout->expires_at
                    : $reservationEnd,
            ]);

            return response()->json([
                'success' => true,
                'checkoutUrl' => $session->url,
                'sessionId' => $session->id,
                'reference' => $checkout->reference,
            ]);
        } finally {
            $lock->release();
        }
    }

    /**
     * POST /api/payments/stripe/cancel  Body: { reference: "CHK-…" }
     *
     * The customer pressed "back" on Stripe's page. Expire the still-open
     * session (so browser Back can't pay it later alongside a new checkout)
     * and release the reserved stock straight away. A session that was paid
     * in the meantime is left alone — verify/webhook convert it.
     */
    public function cancelStripe(Request $request)
    {
        $reference = (string) $request->input('reference', '');
        $checkout = $reference !== '' ? PendingCheckout::where('reference', $reference)->first() : null;
        if (! $checkout) {
            return response()->json(['success' => false, 'error' => 'Checkout not found'], 404);
        }
        if ($checkout->status !== 'pending') {
            return response()->json(['success' => true]);
        }

        $stripe = $this->stripeClient();
        if ($checkout->stripe_session_id && $stripe) {
            try {
                $session = $stripe->checkout->sessions->retrieve($checkout->stripe_session_id);
                $status = $session->status ?? null;
                if ($status === 'complete' || $this->mapStripeSessionStatus($session) === 'paid') {
                    return response()->json(['success' => true]);
                }
                if ($status === 'open') {
                    $stripe->checkout->sessions->expire($checkout->stripe_session_id);
                }
            } catch (\Stripe\Exception\ApiErrorException $e) {
                // Most likely paid a moment ago: never fail the checkout then.
                Log::info('Stripe session cancel skipped', ['reference' => $reference, 'error' => $e->getMessage()]);

                return response()->json(['success' => true]);
            }
        }

        // Draft pay links stay payable: only a customer checkout is released.
        if (! $checkout->is_draft) {
            $this->markCheckoutFailed($checkout);
        }

        return response()->json(['success' => true]);
    }

    /**
     * GET /api/payments/stripe/verify?reference=CHK-...
     * Called by OrderConfirmation after redirect-back. Re-fetches the
     * session from Stripe; on payment_status=paid converts to an Order.
     * The session id stored at creation is used — a `session_id` query
     * param is never trusted to point at this checkout.
     */
    public function verifyStripe(Request $request)
    {
        $reference = $request->query('reference');
        if (! $reference) {
            return response()->json(['success' => false, 'error' => 'reference is required'], 422);
        }

        $checkout = PendingCheckout::where('reference', $reference)->first();
        if (! $checkout) {
            return response()->json(['success' => false, 'error' => 'Checkout not found'], 404);
        }

        if ($checkout->status === 'converted' && $checkout->order_number) {
            $order = Order::where('order_number', $checkout->order_number)->first();

            return response()->json([
                'success' => true,
                'paymentStatus' => 'paid',
                'gatewayStatus' => 'PAID',
                'orderStatus' => $order?->status,
                'order' => $order ? $this->orderSummary($order) : null,
            ]);
        }

        if (! $checkout->stripe_session_id) {
            return response()->json(['success' => false, 'error' => 'No Stripe session id on this checkout'], 422);
        }

        $stripe = $this->stripeClient();
        if (! $stripe) {
            return response()->json(['success' => false, 'error' => 'Stripe credentials not configured'], 500);
        }

        try {
            $session = $stripe->checkout->sessions->retrieve($checkout->stripe_session_id);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Stripe verify failed', ['reference' => $reference, 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => 'Failed to verify Stripe payment'], 500);
        }

        $gatewayStatus = strtoupper((string) ($session->payment_status ?? ''));
        $paymentStatus = $this->mapStripeSessionStatus($session);

        if ($paymentStatus === 'paid') {
            $order = $this->convertCheckoutToOrder($checkout, 'stripe', $this->stripePaymentIntentId($session) ?? $session->id);

            return response()->json([
                'success' => true,
                'paymentStatus' => 'paid',
                'gatewayStatus' => $gatewayStatus,
                'orderStatus' => $order->status,
                'order' => $this->orderSummary($order),
            ]);
        }

        if ($paymentStatus === 'failed') {
            $this->markCheckoutFailed($checkout);
        }

        return response()->json([
            'success' => true,
            'paymentStatus' => $paymentStatus,
            'gatewayStatus' => $gatewayStatus,
            'orderStatus' => null,
            'order' => null,
        ]);
    }

    /**
     * Convert a PendingCheckout into a real Order. Idempotent — if
     * already converted, returns the existing Order.
     *
     * Called from both the verify endpoint and the webhook so whichever
     * arrives first wins, and the other one no-ops cleanly.
     */
    public function convertCheckoutToOrder(PendingCheckout $checkout, string $paymentMethod = 'paymennt', ?string $paymentReference = null, ?string $paymentBrand = null, string $paymentStatus = 'paid'): Order
    {
        // Already converted?
        if ($checkout->status === 'converted' && $checkout->order_number) {
            return Order::where('order_number', $checkout->order_number)->firstOrFail();
        }

        // Friendly instrument label ('Apple Pay' / 'Card' / 'Tabby' …).
        $brandLabel = $this->paymentLabel($paymentMethod, $paymentBrand);

        // True only for the caller that actually creates the Order. A webhook
        // and a verify call routinely both pass the pre-check above; the
        // loser gets the existing order from inside the lock and must not
        // repeat the side effects below.
        $created = false;

        $order = DB::transaction(function () use ($checkout, $paymentMethod, $paymentReference, $brandLabel, $paymentStatus, &$created) {
            // Re-check inside the transaction to avoid double-conversion races
            $fresh = PendingCheckout::where('id', $checkout->id)->lockForUpdate()->first();
            if ($fresh->status === 'converted' && $fresh->order_number) {
                return Order::where('order_number', $fresh->order_number)->firstOrFail();
            }

            // Stock is reserved (decremented) when the checkout is created, and
            // put back when it is marked abandoned or failed. A customer can
            // still complete payment after that — Tabby/Tamara app switching and
            // 3-D Secure OTP routinely outlast the reservation window — and the
            // payment is honoured, so the stock has to come back OUT again.
            // Without this the item ships while the count still says it is in
            // stock, and the next customer oversells it.
            if (in_array($fresh->status, ['abandoned', 'failed'], true)) {
                foreach ($fresh->items as $item) {
                    if (! empty($item['product_id'])) {
                        \App\Models\Product::where('id', $item['product_id'])
                            ->whereNotNull('stock_quantity')
                            ->decrement('stock_quantity', (int) ($item['quantity'] ?? 1));
                    }
                }
                Log::info('Re-deducted stock for a late-paid checkout', [
                    'reference' => $fresh->reference,
                    'previous_status' => $fresh->status,
                ]);
            }

            // Resolve payment_reference based on the gateway used.
            // Callers may override; otherwise we read the matching ID column on the checkout.
            $reference = $paymentReference ?? match ($paymentMethod) {
                'tabby' => $fresh->tabby_payment_id,
                'tamara' => $fresh->tamara_order_id,
                'stripe' => $fresh->stripe_session_id,
                default => $fresh->paymennt_checkout_id,
            };

            $order = Order::create([
                // Attach the order to a registered account if one exists with this
                // email — so it shows up under the customer's "My Orders". Guests
                // (no matching account) stay null until they create an account,
                // at which point signup() claims their past orders by email.
                'user_id' => \App\Models\User::where('email', strtolower($fresh->customer_email))->value('id'),
                'customer_email' => $fresh->customer_email,
                'customer_name' => $fresh->customer_name,
                'customer_phone' => $fresh->customer_phone,
                'subtotal' => $fresh->subtotal,
                'discount' => $fresh->discount,
                'shipping_cost' => $fresh->shipping_cost,
                'shipping_method' => $fresh->shipping_method,
                'gift_fee' => $fresh->gift_fee,
                'gift_message' => $fresh->gift_message,
                'total' => $fresh->total,
                // What the gateway actually billed. Differs from `total` for a KSA
                // order (charged in SAR); a later capture/refund must use these.
                'gateway_currency' => $fresh->gateway_currency,
                'gateway_amount' => $fresh->gateway_amount,
                // 'confirmed' = payment received; admin moves to 'processing' once they begin preparing the order.
                'status' => 'confirmed',
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentStatus,
                'payment_reference' => $reference,
                'payment_brand' => $brandLabel,
                'shipping_address' => $fresh->shipping_address,
                'shipping_city' => $fresh->shipping_city,
                'shipping_state' => $fresh->shipping_state,
                'shipping_country' => $fresh->shipping_country,
                'shipping_zip' => $fresh->shipping_zip,
                'notes' => $fresh->notes,
            ]);

            foreach ($fresh->items as $item) {
                // Use the cart-supplied image_url; fall back to the product
                // record if the cart didn't include one, so admin order views
                // always have a thumbnail.
                $productImage = $item['image_url']
                    ?? $item['product_image']
                    ?? optional(\App\Models\Product::find($item['product_id'] ?? null))->image_url;

                // Ensure image URLs are absolute (needed for emails)
                if ($productImage && ! str_starts_with($productImage, 'http')) {
                    $productImage = config('app.url').(str_starts_with($productImage, '/') ? '' : '/').$productImage;
                }

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item['product_id'] ?? null,
                    'product_name' => $item['product_name'] ?? ($item['name'] ?? 'Item'),
                    'product_image' => $productImage,
                    'quantity' => (int) ($item['quantity'] ?? 1),
                    'unit_price' => (float) ($item['price'] ?? 0),
                    'total_price' => (float) ($item['price'] ?? 0) * (int) ($item['quantity'] ?? 1),
                ]);
            }

            $fresh->update([
                'status' => 'converted',
                'order_number' => $order->order_number,
            ]);

            // Discount usage now belongs to a real paid order
            if ($fresh->discount_id) {
                try {
                    \App\Models\Discount::where('id', $fresh->discount_id)->increment('used_count');
                } catch (\Throwable $e) {
                    Log::warning('Failed to increment discount usage: '.$e->getMessage());
                }
            }

            $created = true;

            return $order;
        });

        if (! $created) {
            return $order; // another caller (webhook or verify) converted it and ran the side effects
        }

        // Timeline events (logged once — gated on $created above)
        $order->logEvent('placed', 'Order placed', $order->customer_name ? "Placed by {$order->customer_name}" : null, 'Customer');
        if ($paymentMethod === 'cod') {
            $order->logEvent('payment', 'Cash on Delivery', 'Cash to be collected on delivery', 'System');
            AdminNotifier::orderCashOnDelivery($order);
        } else {
            $order->logEvent(
                'payment',
                'Payment confirmed',
                'Paid via '.$brandLabel.($order->payment_reference ? " · ref {$order->payment_reference}" : ''),
                'System',
            );

            // Bell notification (fires once — gated on $created above)
            AdminNotifier::orderPaid($order);
        }

        // Queue confirmation emails (no SMTP wait for the user; the scheduler's
        // queue worker sends them within ~1 min in the CLI context).
        $this->dispatchPaidNotifications($order);

        // Klaviyo order events. Queued for the same reason as the emails: this
        // sits in the customer's redirect-back path, and Klaviyo's API must not
        // add latency to it. Safe to re-run — the events carry a unique_id, so
        // Klaviyo de-duplicates if this order is ever converted twice.
        $this->dispatchKlaviyoOrderEvents($order);

        return $order;
    }

    /**
     * Mark a checkout as failed and restore reserved stock.
     * Called when Paymennt reports CANCELLED/EXPIRED/FAILED/DECLINED.
     */
    public function markCheckoutFailed(PendingCheckout $checkout): void
    {
        if ($checkout->status !== 'pending') {
            return;
        }

        DB::transaction(function () use ($checkout) {
            $fresh = PendingCheckout::where('id', $checkout->id)->lockForUpdate()->first();
            if ($fresh->status !== 'pending') {
                return;
            }

            foreach ($fresh->items as $item) {
                if (! empty($item['product_id'])) {
                    \App\Models\Product::where('id', $item['product_id'])
                        ->whereNotNull('stock_quantity')
                        ->increment('stock_quantity', (int) ($item['quantity'] ?? 1));
                }
            }
            $fresh->update(['status' => 'failed']);
        });
    }

    /**
     * Public-safe order summary for the confirmation page.
     */
    private function orderSummary(Order $order): array
    {
        return [
            'order_number' => $order->order_number,
            'created_at' => $order->created_at,
            'payment_method' => $order->payment_method,
            'payment_brand' => $order->payment_brand,
            'payment_status' => $order->payment_status,
            'status' => $order->status,
            'total' => $order->total,
            'shipping_city' => $order->shipping_city,
            'shipping_country' => $order->shipping_country,
        ];
    }

    /**
     * Maps a country name (or ISO-2/ISO-3 code) to ISO-2 for Paymennt,
     * which rejects strings outside 2-3 chars. Falls back to AE on any
     * miss so the checkout doesn't 400. See App\Support\CountryCode.
     */
    private function normalizeCountryToIso2(string $input): string
    {
        return CountryCode::toIso2OrUae($input);
    }

    /**
     * Queue the Klaviyo "Placed Order" / "Ordered Product" events.
     *
     * Its own closure rather than sharing the notifications one: a Klaviyo
     * outage must not stop the customer's confirmation email from going out,
     * and a PDF (TCPDF) failure in the invoice must not cost us the revenue event.
     *
     * Every order reaches this, because convertCheckoutToOrder() is the single
     * funnel for all four payment methods, the gateway webhooks, and the
     * Reconcile* commands. (The legacy POST /api/orders endpoint is not hooked
     * — nothing in the storefront calls it.)
     */
    private function dispatchKlaviyoOrderEvents(Order $order): void
    {
        $order->loadMissing('items');

        dispatch(function () use ($order) {
            try {
                app(\App\Services\Klaviyo::class)->trackPlacedOrder($order);
            } catch (\Throwable $e) {
                Log::error('Failed to send Klaviyo order events: '.$e->getMessage(), [
                    'order' => $order->order_number,
                ]);
            }
        });
    }

    /**
     * Send order confirmation + admin notification AFTER the response is
     * sent so the customer doesn't wait on SMTP + the TCPDF invoice.
     */
    private function dispatchPaidNotifications(Order $order): void
    {
        $order->loadMissing('items');

        // Customer confirmation (carries the PDF invoice — queued as its own
        // job so a failure here cannot prevent the admin notification from
        // firing, and vice versa). Processed by the scheduler's queue worker.
        if ($order->customer_email) {
            dispatch(function () use ($order) {
                try {
                    Mail::to($order->customer_email)->send(new OrderConfirmationMail($order));
                } catch (\Throwable $e) {
                    Log::error('Failed to send order confirmation email: '.$e->getMessage(), [
                        'order' => $order->order_number,
                    ]);
                }
            });
        }

        // Admin notification — separate closure so PDF-rendering memory from the
        // customer PDF cannot starve this one. No PDF attached here.
        $adminRecipients = \App\Services\AdminNotifier::adminEmails();
        if (empty($adminRecipients)) {
            Log::warning('Admin order notification skipped: no admin email set (ADMIN_NOTIFICATION_EMAILS or store email)', [
                'order' => $order->order_number,
            ]);

            return;
        }

        dispatch(function () use ($order, $adminRecipients) {
            try {
                Mail::to($adminRecipients)->send(new NewOrderAdminNotification($order));
            } catch (\Throwable $e) {
                Log::error('Failed to send admin payment notification: '.$e->getMessage(), [
                    'order' => $order->order_number,
                    'recipients' => $adminRecipients,
                ]);
            }
        });
    }
}
