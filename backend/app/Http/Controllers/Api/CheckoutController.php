<?php

/**
 * ============================================================
 * CHECKOUT CONTROLLER — Shopify-style Checkout Stage
 * ============================================================
 * What this controller does:
 *   Stage 1 of the two-stage checkout flow. Validates the cart,
 *   reserves stock, and stores the customer/cart data in
 *   `pending_checkouts`. The customer is then sent to Paymennt's
 *   hosted page. The actual Order row is only created when
 *   payment succeeds (see PaymentController::convertToOrder).
 *
 * API endpoints:
 *   POST /api/checkouts → Start a checkout (no Order created yet)
 * ============================================================
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\AbandonedCheckoutRecovery;
use App\Models\PendingCheckout;
use App\Models\Product;
use App\Services\CheckoutExpirer;
use App\Services\ShippingQuote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    /**
     * POST /api/checkouts
     * Validates the cart, soft-reserves stock, stores the cart in
     * pending_checkouts and returns a reference id. The frontend then
     * calls POST /api/payments/paymennt with this reference to start
     * the hosted checkout.
     */
    public function start(Request $request)
    {
        // Hand back stock from checkouts that have already expired, before we
        // reserve any for this one. The cron sweeper skips for hours on this
        // host, and this is the moment it matters most: an expired hold must
        // not make an item look out of stock to a customer buying it now.
        app(CheckoutExpirer::class)->sweepQuietly();

        $validated = $request->validate([
            'customer_name' => 'required|string|max:255',
            'customer_email' => 'required|email|max:255',
            'customer_phone' => 'required|string|max:50',
            'shipping_address' => 'nullable|string|max:500',
            'shipping_city' => 'nullable|string|max:100',
            'shipping_country' => 'nullable|string|max:100',
            'shipping_state' => 'nullable|string|max:100',
            'shipping_postal_code' => 'nullable|string|max:20',
            'shipping_country_code' => 'nullable|string|size:2',
            'delivery_type' => 'nullable|string|max:100',
            'subtotal' => 'required|numeric|min:0',
            // Ignored — shipping is priced server-side (see ShippingQuote).
            'shipping_cost' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'discount_id' => 'nullable|string',
            'total' => 'required|numeric|min:0',
            'gift_enabled' => 'nullable|boolean',
            'gift_message' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|uuid|exists:products,id',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.image_url' => 'nullable|string|max:2048',
        ]);

        // Saudi deliveries need the National Address short code: 4 letters +
        // 4 digits (e.g. RRRD2929). Mirrors the checkout form's check.
        if (\App\Support\CountryCode::tryToIso2($validated['shipping_country'] ?? null) === 'SA'
            && ! preg_match('/^[A-Z]{4}[0-9]{4}$/', strtoupper(trim((string) ($validated['shipping_postal_code'] ?? ''))))) {
            return response()->json([
                'success' => false,
                'error' => 'invalid_national_address',
                'message' => 'Please enter your Saudi National Address code: 4 letters followed by 4 numbers (e.g. RRRD2929).',
            ], 422);
        }

        // Price shipping from the admin rate table — never from the amount the
        // browser sent. Done before the transaction so a refusal reserves no stock.
        $shipping = app(ShippingQuote::class)->quote(
            $validated['shipping_country'] ?? 'United Arab Emirates',
            $validated['shipping_country_code'] ?? null,
            $validated['items'],
            $validated['delivery_type'] ?? null,
        );
        if ($shipping === null) {
            return response()->json([
                'success' => false,
                'error' => 'shipping_unavailable',
                'message' => 'Online shipping to this country isn\'t available yet. Please contact us and we\'ll arrange delivery for you.',
            ], 422);
        }

        // Hand back stock this customer is still holding from an earlier,
        // unfinished attempt (e.g. they left Tamara without the cancel
        // redirect) — otherwise their own reservation blocks the retry
        // with "out of stock". See CheckoutExpirer::releaseSupersededBy().
        app(CheckoutExpirer::class)->releaseSupersededQuietly($validated['customer_email']);

        $checkout = DB::transaction(function () use ($validated, $shipping) {
            $items = $validated['items'];

            // Server-side price validation: recalculate from real product prices.
            // Also reserve (decrement) stock so the customer holds inventory while
            // they pay. Stock is restored if the checkout is abandoned/failed.
            $serverSubtotal = 0;
            $productIds = array_column($items, 'product_id');
            $products = Product::whereIn('id', $productIds)->lockForUpdate()->get()->keyBy('id');

            foreach ($items as &$item) {
                $product = $products[$item['product_id']] ?? null;
                if (! $product) {
                    abort(422, 'Product no longer available');
                }

                // "Price on Request" watches are not sold from the site — their
                // price is hidden, so selling one here would bill the customer a
                // figure they were never shown. Refuse before anything is
                // reserved; the surrounding transaction rolls back any stock
                // already decremented for earlier items in this cart.
                if ($product->inquiry_only) {
                    abort(422, "{$product->name} is available by enquiry only");
                }

                // Stock check — abort the whole checkout if any item is short
                if ($product->stock_quantity !== null && $product->stock_quantity < $item['quantity']) {
                    abort(422, "{$product->name} is out of stock");
                }

                $item['price'] = (float) $product->price;
                $serverSubtotal += $item['price'] * $item['quantity'];

                // Capture the canonical image url from the product so it survives
                // into the staged checkout and gets sent to Paymennt's hosted page.
                // Prefer image_url, then first images[] entry. Make absolute if it's a
                // relative storage path so external gateways can fetch it.
                $rawImage = $product->image_url
                    ?: (is_array($product->images) ? ($product->images[0] ?? null) : null);
                $item['image_url'] = $rawImage
                    ? (str_starts_with($rawImage, 'http') ? $rawImage : url($rawImage))
                    : null;

                if ($product->stock_quantity !== null) {
                    $product->decrement('stock_quantity', $item['quantity']);
                }
            }
            unset($item);

            // Re-validate the discount server-side. The frontend sends
            // `discount_id` and `discount_amount`, but we DON'T trust either
            // — we re-fetch the discount, confirm it's still valid (active,
            // within date range, under max_uses, meets min_order_amount), and
            // recompute the discount value from the real product subtotal.
            // Without this, an attacker could send discount_amount=999 and
            // get a near-free order.
            $shippingCost = $shipping['cost'];

            $discount = 0;
            $discountId = null;
            $rawDiscountId = $validated['discount_id'] ?? null;
            if ($rawDiscountId) {
                $d = \App\Models\Discount::valid()->find($rawDiscountId);
                if ($d && $serverSubtotal >= (float) ($d->min_order_amount ?? 0)) {
                    $discount = $d->type === 'percentage'
                        ? round($serverSubtotal * ((float) $d->value) / 100, 2)
                        : round((float) $d->value, 2);
                    // Optional maximum cap (mainly for % discounts), e.g. 20% up to AED 100.
                    if ($d->max_discount_amount !== null && (float) $d->max_discount_amount > 0) {
                        $discount = min($discount, (float) $d->max_discount_amount);
                    }
                    // Cap at subtotal + shipping so a fixed discount can also cover
                    // the shipping fee (e.g. an AED 30 "FREE" code wipes the AED 30
                    // shipping). This never lets the order total go negative.
                    $discount = min($discount, $serverSubtotal + $shippingCost);
                    $discountId = $d->id;
                }
            }

            // Gift message card — fee is taken from the admin setting (never
            // trusted from the client), and only charged when enabled.
            $giftEnabled = (bool) ($validated['gift_enabled'] ?? false);
            $giftFee = 0.0;
            $giftMessage = null;
            if ($giftEnabled) {
                $giftFee = (float) (\App\Support\Brand::setting('gift_card_price') ?? 10);
                $giftMessage = $validated['gift_message'] ?? null;
            }

            $total = round($serverSubtotal - $discount + $shippingCost + $giftFee, 2);

            // Generate a Shopify-style temporary reference. The customer's
            // bank statement will show this until the order number is allocated.
            $reference = 'CHK-'.strtoupper(Str::random(10));

            return PendingCheckout::create([
                'reference' => $reference,
                'status' => 'pending',
                'customer_name' => $validated['customer_name'],
                'customer_email' => $validated['customer_email'],
                'customer_phone' => $validated['customer_phone'],
                'shipping_address' => $validated['shipping_address'] ?? null,
                'shipping_city' => $validated['shipping_city'] ?? null,
                'shipping_state' => $validated['shipping_state'] ?? null,
                'shipping_country' => $validated['shipping_country'] ?? 'UAE',
                'shipping_zip' => $validated['shipping_postal_code'] ?? null,
                'subtotal' => round($serverSubtotal, 2),
                'discount' => $discount,
                'shipping_cost' => $shippingCost,
                // Which option was priced (e.g. standard / international_special)
                // — shown with its estimated days in the order emails.
                'shipping_method' => $shipping['delivery_type'] ?? null,
                'gift_fee' => $giftFee,
                'gift_message' => $giftMessage,
                'total' => $total,
                'discount_id' => $discountId,
                'items' => $items,
                'notes' => $validated['notes'] ?? null,
                // Stock reservation window; the sweeper releases it after this.
                // Tunable via CHECKOUT_RESERVATION_MINUTES (see config/app.php).
                'expires_at' => now()->addMinutes(config('app.checkout_reservation_minutes', 10)),
            ]);
        });

        // Klaviyo "Started Checkout". Queued like the order events so Klaviyo's
        // API never delays the customer's redirect to the payment gateway.
        dispatch(function () use ($checkout) {
            try {
                app(\App\Services\Klaviyo::class)->trackStartedCheckout($checkout);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Failed to send Klaviyo Started Checkout: '.$e->getMessage(), [
                    'reference' => $checkout->reference,
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'reference' => $checkout->reference,
            'total' => $checkout->total,
            'expires_at' => $checkout->expires_at,
        ], 201);
    }

    /**
     * GET /api/admin/abandoned-checkouts
     * Admin-only: list pending+abandoned+failed checkouts so the team
     * can see who started a checkout but didn't pay (Shopify-style).
     * Supports optional status, search (reference/email/phone), date
     * range, and pagination. Always returns counts per status so the
     * admin UI can render tab badges without a second request.
     */
    public function adminList(Request $request)
    {
        // Sweep first so the list never shows a checkout as "in progress" long
        // after its window closed — which is exactly how this page looked when
        // the cron stalled.
        app(CheckoutExpirer::class)->sweepQuietly();

        $status = $request->query('status', 'abandoned');
        $perPage = min((int) $request->query('per_page', 25), 100);
        $search = trim((string) $request->query('search', ''));
        $since = $request->query('since'); // ISO date or null
        $until = $request->query('until');

        $query = PendingCheckout::query();

        if ($status === 'all') {
            $query->whereIn('status', ['pending', 'abandoned', 'failed']);
        } elseif (in_array($status, ['pending', 'abandoned', 'failed', 'converted'], true)) {
            $query->where('status', $status);
        } else {
            $query->where('status', 'abandoned');
        }

        if ($search !== '') {
            $term = '%'.$search.'%';
            $query->where(function ($q) use ($term) {
                $q->where('reference', 'like', $term)
                    ->orWhere('customer_email', 'like', $term)
                    ->orWhere('customer_name', 'like', $term)
                    ->orWhere('customer_phone', 'like', $term);
            });
        }

        if ($since) {
            $query->where('created_at', '>=', $since);
        }
        if ($until) {
            $query->where('created_at', '<=', $until);
        }

        $page = $query->orderByDesc('created_at')->paginate($perPage);

        // Status counts so the UI can render tab badges and the dashboard
        // widget can show conversion stats without another round-trip.
        $statusCounts = PendingCheckout::query()
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'counts' => [
                'abandoned' => (int) ($statusCounts['abandoned'] ?? 0),
                'pending' => (int) ($statusCounts['pending'] ?? 0),
                'failed' => (int) ($statusCounts['failed'] ?? 0),
                'converted' => (int) ($statusCounts['converted'] ?? 0),
                'all' => (int) (($statusCounts['abandoned'] ?? 0) + ($statusCounts['pending'] ?? 0) + ($statusCounts['failed'] ?? 0)),
            ],
        ]);
    }

    /**
     * POST /api/admin/abandoned-checkouts/{reference}/send-recovery
     * Sends (or re-sends) the recovery reminder email to the customer.
     * Bumps recovery_email_count and recovery_email_sent_at so the cron
     * doesn't double-send.
     */
    public function sendRecoveryEmail(string $reference)
    {
        $checkout = PendingCheckout::where('reference', $reference)->firstOrFail();

        if (empty($checkout->customer_email)) {
            return response()->json(['error' => 'Checkout has no customer email.'], 422);
        }
        if ($checkout->status === 'converted') {
            return response()->json(['error' => 'This checkout already converted to an order.'], 422);
        }

        Mail::to($checkout->customer_email)->send(new AbandonedCheckoutRecovery($checkout));

        $checkout->forceFill([
            'recovery_email_sent_at' => now(),
            'recovery_email_count' => (int) $checkout->recovery_email_count + 1,
        ])->save();

        return response()->json([
            'ok' => true,
            'recovery_email_sent_at' => $checkout->recovery_email_sent_at,
            'recovery_email_count' => $checkout->recovery_email_count,
        ]);
    }

    /**
     * POST /api/admin/abandoned-checkouts/{reference}/mark-recovered
     * Admin signal that the customer paid through a side channel
     * (WhatsApp, in-store, etc.) — moves the row out of the active
     * abandoned list without creating an Order.
     */
    public function markRecovered(string $reference)
    {
        $checkout = PendingCheckout::where('reference', $reference)->firstOrFail();
        $checkout->forceFill([
            'recovered_at' => now(),
            'status' => 'converted',
        ])->save();

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/admin/abandoned-checkouts/{reference}/expire
     * Force-expire a pending checkout. Releases the reserved stock by
     * delegating to the same path the scheduled cleanup uses.
     */
    public function expireNow(string $reference)
    {
        $checkout = PendingCheckout::where('reference', $reference)->firstOrFail();

        if ($checkout->status === 'pending') {
            DB::transaction(function () use ($checkout) {
                foreach (($checkout->items ?? []) as $item) {
                    $productId = $item['product_id'] ?? null;
                    $qty = (int) ($item['quantity'] ?? 0);
                    if ($productId && $qty > 0) {
                        Product::where('id', $productId)->increment('stock_quantity', $qty);
                    }
                }
                $checkout->forceFill(['status' => 'abandoned'])->save();
            });
        }

        return response()->json(['ok' => true, 'status' => $checkout->fresh()->status]);
    }

    /**
     * GET /api/checkouts/{reference}/recover
     * Public: returns the cart payload so the storefront's
     * /recover-checkout/{reference} page can re-hydrate the cart and
     * redirect the customer to /checkout. Only works while the
     * checkout is still pending or abandoned (not converted).
     */
    public function recover(string $reference)
    {
        $checkout = PendingCheckout::where('reference', $reference)->first();
        if (! $checkout) {
            return response()->json(['error' => 'Checkout not found.'], 404);
        }
        if ($checkout->status === 'converted') {
            return response()->json(['error' => 'This order has already been completed.'], 410);
        }

        return response()->json([
            'reference' => $checkout->reference,
            'status' => $checkout->status,
            'customer_email' => $checkout->customer_email,
            'customer_name' => $checkout->customer_name,
            'customer_phone' => $checkout->customer_phone,
            'shipping_address' => $checkout->shipping_address,
            'shipping_city' => $checkout->shipping_city,
            'shipping_country' => $checkout->shipping_country,
            'shipping_state' => $checkout->shipping_state,
            'shipping_zip' => $checkout->shipping_zip,
            'items' => $checkout->items ?? [],
            'subtotal' => $checkout->subtotal,
            'discount' => $checkout->discount,
            'shipping_cost' => $checkout->shipping_cost,
            'total' => $checkout->total,
        ]);
    }

    /**
     * GET /api/admin/abandoned-checkouts/stats
     * Light counters for the dashboard widget: how many abandoned in
     * the last 7 days, how many recovered (admin marked), how much
     * potential revenue is still in flight.
     */
    public function stats()
    {
        $since = now()->subDays(7);

        return response()->json([
            'abandoned_7d' => (int) PendingCheckout::where('status', 'abandoned')->where('created_at', '>=', $since)->count(),
            'pending_now' => (int) PendingCheckout::where('status', 'pending')->count(),
            'recovered_7d' => (int) PendingCheckout::whereNotNull('recovered_at')->where('recovered_at', '>=', $since)->count(),
            'in_flight_value' => (float) PendingCheckout::whereIn('status', ['pending', 'abandoned'])->where('created_at', '>=', $since)->sum('total'),
        ]);
    }
}
