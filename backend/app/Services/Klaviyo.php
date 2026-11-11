<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PendingCheckout;
use App\Support\Brand;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ============================================================
 * KLAVIYO — Email marketing API client
 * ============================================================
 * Thin wrapper over Klaviyo's REST API, written with Laravel's
 * Http client for the same reason Paymennt/Tabby/Tamara are: no
 * extra Composer dependency to install on the cPanel servers.
 *
 * Deliberately NOT the Klaviyo CLI. That binary is a developer /
 * QA tool on the dev box; shelling out to it from a queue worker
 * on shared hosting would be slow, fragile, and needs `exec()`,
 * which is frequently disabled there.
 *
 * Every method is a no-op returning false when the integration is
 * unconfigured, so an environment without Klaviyo keys (a fresh
 * clone, or production before the keys are added) behaves exactly
 * as it did before rather than throwing.
 * ============================================================
 */
class Klaviyo
{
    /**
     * Klaviyo has no sandbox — every call hits the one real account.
     * Keep the timeout short so a Klaviyo outage degrades the newsletter
     * form instead of hanging a PHP-FPM worker on a shared host.
     */
    private const TIMEOUT_SECONDS = 8;

    public function enabled(): bool
    {
        return ! empty(config('services.klaviyo.private_key'));
    }

    /**
     * True when a newsletter list has also been configured. Subscribing
     * needs both the key and a list to subscribe *to*.
     */
    public function newsletterEnabled(): bool
    {
        return $this->enabled() && ! empty(config('services.klaviyo.newsletter_list_id'));
    }

    /**
     * Whether the configured list uses double opt-in. Mirrors the setting on
     * the Klaviyo list itself — see config/services.php.
     */
    public function newsletterUsesDoubleOptIn(): bool
    {
        return filter_var(
            config('services.klaviyo.newsletter_double_opt_in'),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * Subscribe an email address to the configured newsletter list.
     *
     * Uses the bulk *subscription* job rather than plain profile-create on
     * purpose: it records marketing consent against the profile, which is
     * what makes the contact legally mailable and what triggers the list's
     * double opt-in email when that is switched on. Creating a profile and
     * adding it to a list does NOT grant consent, and Klaviyo will silently
     * skip those contacts when a campaign sends.
     *
     * Returns true when Klaviyo accepted the job (HTTP 202).
     */
    public function subscribeToNewsletter(string $email, ?string $source = null): bool
    {
        if (! $this->newsletterEnabled()) {
            return false;
        }

        $listId = config('services.klaviyo.newsletter_list_id');

        $payload = [
            'data' => [
                'type' => 'profile-subscription-bulk-create-job',
                'attributes' => [
                    // Shown in Klaviyo as the profile's subscription source, so
                    // signups from the site are distinguishable from imports.
                    'custom_source' => $source ?: 'Website Newsletter',
                    'profiles' => [
                        'data' => [
                            [
                                'type' => 'profile',
                                'attributes' => [
                                    'email' => $email,
                                    'subscriptions' => [
                                        'email' => [
                                            'marketing' => [
                                                'consent' => 'SUBSCRIBED',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'relationships' => [
                    'list' => [
                        'data' => [
                            'type' => 'list',
                            'id' => $listId,
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->post('/api/profile-subscription-bulk-create-jobs/', $payload);

        if ($response === null) {
            return false;
        }

        // Klaviyo queues the subscription and answers 202 with an empty body.
        if ($response->status() === 202) {
            return true;
        }

        Log::error('Klaviyo newsletter subscribe rejected', [
            'status' => $response->status(),
            'body' => $response->body(),
            'list_id' => $listId,
        ]);

        return false;
    }

    /**
     * Send one event to Klaviyo.
     *
     * `$uniqueId` becomes Klaviyo's `unique_id`, which is its idempotency key:
     * re-sending the same metric with the same unique_id does NOT create a
     * second event. That matters a great deal here — an order can be converted
     * from several directions at once (the gateway webhook, the customer
     * landing back on /order-confirmation, and the nightly Reconcile* commands
     * all reach convertCheckoutToOrder), and a duplicate "Placed Order" would
     * double-count revenue in Klaviyo's reporting.
     */
    public function trackEvent(
        string $metricName,
        array $profile,
        array $properties = [],
        ?float $value = null,
        ?string $uniqueId = null,
        ?string $time = null,
    ): bool {
        if (! $this->enabled()) {
            return false;
        }

        $attributes = [
            // Cast to object so an empty property bag serialises as {} rather
            // than [], which Klaviyo rejects.
            'properties' => (object) $properties,
            'metric' => ['data' => ['type' => 'metric', 'attributes' => ['name' => $metricName]]],
            'profile' => ['data' => ['type' => 'profile', 'attributes' => $profile]],
        ];

        if ($value !== null) {
            $attributes['value'] = $value;
            // KLAVIYO_CURRENCY wins when set; otherwise the store currency.
            $attributes['value_currency'] = config('services.klaviyo.currency') ?: Brand::currency();
        }
        if ($uniqueId !== null) {
            // Namespaced per environment — see services.klaviyo.event_prefix.
            // Order numbers restart per database, so an un-prefixed key lets a
            // staging test order overwrite the real order sharing its number.
            $attributes['unique_id'] = config('services.klaviyo.event_prefix').$uniqueId;
        }
        if ($time !== null) {
            $attributes['time'] = $time;
        }

        $response = $this->post('/api/events/', ['data' => ['type' => 'event', 'attributes' => $attributes]]);

        if ($response !== null && $response->status() === 202) {
            return true;
        }

        Log::error('Klaviyo event rejected', [
            'metric' => $metricName,
            'unique_id' => $uniqueId,
            'status' => $response?->status(),
            'body' => $response?->body(),
        ]);

        return false;
    }

    /**
     * Fire "Placed Order" plus one "Ordered Product" per line item.
     *
     * These two metric names are Klaviyo's conventional e-commerce ones — its
     * built-in flows, segments and revenue reporting look for exactly these, so
     * renaming them would leave the dashboards empty.
     */
    public function trackPlacedOrder(Order $order): void
    {
        if (! $this->enabled()) {
            return;
        }

        $order->loadMissing('items.product.brand', 'items.product.category');

        $profile = $this->orderProfile($order);
        $items = $this->orderItems($order);
        $time = optional($order->created_at)->toIso8601String();

        $names = [];
        $brands = [];
        $categories = [];
        foreach ($items as $item) {
            $names[] = $item['ProductName'];
            if ($item['Brand'] !== null) {
                $brands[] = $item['Brand'];
            }
            foreach ($item['Categories'] as $category) {
                $categories[] = $category;
            }
        }

        $this->trackEvent(
            'Placed Order',
            $profile,
            [
                'OrderId' => $order->order_number,
                'Items' => $items,
                'ItemNames' => $names,
                'Brands' => array_values(array_unique($brands)),
                'Categories' => array_values(array_unique($categories)),
                'ItemCount' => count($items),
                'Subtotal' => (float) $order->subtotal,
                'ShippingCost' => (float) $order->shipping_cost,
                'DiscountValue' => (float) $order->discount,
                'GiftFee' => (float) $order->gift_fee,
                'PaymentMethod' => $order->payment_method,
                'PaymentBrand' => $order->payment_brand,
                'ShippingCountry' => $order->shipping_country,
                'ShippingCity' => $order->shipping_city,
                // What the gateway actually billed, which differs from `value`
                // for a KSA order charged in SAR. See services.klaviyo.currency.
                'GatewayAmount' => $order->gateway_amount !== null ? (float) $order->gateway_amount : null,
                'GatewayCurrency' => $order->gateway_currency,
            ],
            (float) $order->total,
            $order->order_number,
            $time,
        );

        foreach ($order->items as $item) {
            $line = $this->lineItem($item);

            $this->trackEvent(
                'Ordered Product',
                $profile,
                $line + ['OrderId' => $order->order_number],
                (float) $item->total_price,
                // One event per order line, so a re-converted order cannot
                // duplicate individual product rows either.
                $order->order_number.'-'.$item->id,
                $time,
            );
        }
    }

    /**
     * Fire "Started Checkout" for a staged PendingCheckout.
     *
     * Sent server-side rather than from the browser on purpose: this is the
     * first point where we actually know the customer's email address, and it
     * is the same row the abandoned-checkout sweeper later acts on, so Klaviyo's
     * view of an abandoned cart matches ours exactly. A browser-side version
     * would also be lost to ad-blockers precisely for the customers most worth
     * recovering.
     */
    public function trackStartedCheckout(PendingCheckout $checkout): void
    {
        if (! $this->enabled()) {
            return;
        }

        $items = [];
        $names = [];
        foreach ($checkout->items as $item) {
            $productId = $item['product_id'] ?? null;
            $name = $item['product_name'] ?? ($item['name'] ?? 'Item');
            $quantity = (int) ($item['quantity'] ?? 1);
            $price = (float) ($item['price'] ?? 0);

            $names[] = $name;
            $items[] = [
                'ProductID' => $productId,
                'ProductName' => $name,
                'Quantity' => $quantity,
                'ItemPrice' => $price,
                'RowTotal' => $price * $quantity,
                'ImageURL' => $item['image_url'] ?? ($item['product_image'] ?? null),
            ];
        }

        $name = trim((string) $checkout->customer_name);
        $space = strpos($name, ' ');
        $profile = ['email' => strtolower(trim((string) $checkout->customer_email))];
        if ($name !== '') {
            $profile['first_name'] = $space === false ? $name : substr($name, 0, $space);
            if ($space !== false) {
                $profile['last_name'] = trim(substr($name, $space + 1));
            }
        }
        if ($checkout->customer_phone) {
            $profile['phone_number'] = $checkout->customer_phone;
        }

        $this->trackEvent(
            'Started Checkout',
            $profile,
            [
                'CheckoutReference' => $checkout->reference,
                'Items' => $items,
                'ItemNames' => $names,
                'ItemCount' => count($items),
                'Subtotal' => (float) $checkout->subtotal,
                'ShippingCost' => (float) $checkout->shipping_cost,
                'DiscountValue' => (float) $checkout->discount,
                // The link the recovery email sends people back to. Matches the
                // route the existing AbandonedCheckoutRecovery mailable uses, so
                // a Klaviyo flow and our own mailable recover the same cart.
                'RecoveryURL' => rtrim((string) config('app.url'), '/').'/recover-checkout?reference='.$checkout->reference,
            ],
            (float) $checkout->total,
            // One event per staged checkout. A customer who retries payment
            // reuses the same reference, so Klaviyo will not double-count it.
            'checkout-'.$checkout->reference,
            optional($checkout->created_at)->toIso8601String(),
        );
    }

    /**
     * Fire "Cancelled Order", and "Refunded Order" as well when the money
     * actually went back. A cancellation where the gateway refund failed
     * (payment_status 'pending_refund') deliberately does NOT report a refund —
     * Klaviyo would otherwise show money returned that the customer never got.
     */
    public function trackOrderCancelled(Order $order): void
    {
        if (! $this->enabled()) {
            return;
        }

        $order->loadMissing('items.product.brand', 'items.product.category');

        $profile = $this->orderProfile($order);
        $properties = [
            'OrderId' => $order->order_number,
            'Items' => $this->orderItems($order),
            'PaymentMethod' => $order->payment_method,
            'PaymentStatus' => $order->payment_status,
        ];
        $time = now()->toIso8601String();

        $this->trackEvent('Cancelled Order', $profile, $properties, (float) $order->total, 'cancel-'.$order->order_number, $time);

        if ($order->payment_status === 'refunded') {
            $this->trackEvent('Refunded Order', $profile, $properties, (float) $order->total, 'refund-'.$order->order_number, $time);
        }
    }

    /**
     * Profile identity sent alongside an order event. Klaviyo matches on email
     * and creates the profile if it does not exist yet — note this does NOT
     * grant marketing consent, which is correct: buying something is not the
     * same as subscribing to a newsletter.
     */
    private function orderProfile(Order $order): array
    {
        $name = trim((string) $order->customer_name);
        $space = strpos($name, ' ');

        $profile = ['email' => strtolower(trim((string) $order->customer_email))];

        if ($name !== '') {
            $profile['first_name'] = $space === false ? $name : substr($name, 0, $space);
            if ($space !== false) {
                $profile['last_name'] = trim(substr($name, $space + 1));
            }
        }
        if ($order->customer_phone) {
            $profile['phone_number'] = $order->customer_phone;
        }

        $location = array_filter([
            'address1' => $order->shipping_address,
            'city' => $order->shipping_city,
            'region' => $order->shipping_state,
            'country' => $order->shipping_country,
            'zip' => $order->shipping_zip,
        ]);
        if ($location !== []) {
            $profile['location'] = $location;
        }

        return $profile;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function orderItems(Order $order): array
    {
        $items = [];
        foreach ($order->items as $item) {
            $items[] = $this->lineItem($item);
        }

        return $items;
    }

    /**
     * @return array<string,mixed>
     */
    private function lineItem(OrderItem $item): array
    {
        $product = $item->product;
        $category = $product?->category?->name;

        return [
            'ProductID' => $item->product_id,
            'SKU' => $product?->sku,
            'ProductName' => $item->product_name,
            'Quantity' => (int) $item->quantity,
            'ItemPrice' => (float) $item->unit_price,
            'RowTotal' => (float) $item->total_price,
            'ProductURL' => $product?->slug
                ? rtrim((string) config('app.url'), '/').'/product/'.$product->slug
                : null,
            'ImageURL' => $item->product_image,
            'Brand' => $product?->brand?->name,
            'Categories' => $category ? [$category] : [],
        ];
    }

    /**
     * POST to a Klaviyo endpoint with the standard auth + revision headers.
     * Returns null when the request could not be made at all (network error,
     * timeout) — callers treat that the same as a rejection but the log line
     * distinguishes them.
     */
    private function post(string $path, array $payload): ?Response
    {
        try {
            return Http::withHeaders([
                'Authorization' => 'Klaviyo-API-Key '.config('services.klaviyo.private_key'),
                'revision' => config('services.klaviyo.revision'),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(rtrim(config('services.klaviyo.base_url'), '/').$path, $payload);
        } catch (\Throwable $e) {
            Log::error('Klaviyo request failed: '.$e->getMessage(), ['path' => $path]);

            return null;
        }
    }
}
