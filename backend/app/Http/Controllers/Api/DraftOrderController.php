<?php

/**
 * ============================================================
 * DraftOrderController.php — Admin Draft Orders
 * ============================================================
 * A "draft order" is a staged cart an admin builds FOR a customer
 * (Shopify-style). It lives in the same `pending_checkouts` table as
 * a normal storefront checkout, flagged `is_draft = true`:
 *
 *   1. Admin picks the customer + products → store() reserves stock and
 *      stages the cart, then emails the customer a /pay/{reference} link
 *      (and returns the link so the admin can copy/share it).
 *   2. Customer opens the link → the public storefront pay page loads the
 *      draft via show(), collects a shipping address via updateShipping(),
 *      then calls the existing /api/payments/{gateway} endpoints with the
 *      same reference.
 *   3. On PAID, the normal verify/webhook path runs
 *      PaymentController::convertCheckoutToOrder() — turning the draft into
 *      a real Order. Nothing draft-specific is needed there because a draft
 *      IS a pending_checkout.
 *
 * Drafts have expires_at = NULL so the abandoned-checkout cron never
 * expires them or releases their reserved stock — the admin cancels a
 * draft (cancel()) to put the stock back.
 * ============================================================
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\DraftOrderInvitation;
use App\Models\PendingCheckout;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class DraftOrderController extends Controller
{
    /**
     * GET /api/admin/draft-orders
     * List draft orders (newest first), optional search + status filter.
     */
    public function index(Request $request)
    {
        $query = PendingCheckout::where('is_draft', true);

        if ($request->filled('search')) {
            $term = (string) $request->string('search');
            $query->where(function ($w) use ($term) {
                $w->where('reference', 'like', "%{$term}%")
                    ->orWhere('customer_email', 'like', "%{$term}%")
                    ->orWhere('customer_name', 'like', "%{$term}%")
                    ->orWhere('order_number', 'like', "%{$term}%");
            });
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $drafts = $query->orderByDesc('created_at')
            ->limit(min($request->integer('limit', 50), 100))
            ->offset($request->integer('offset', 0))
            ->get();

        return response()->json(['data' => $drafts]);
    }

    /**
     * POST /api/admin/draft-orders
     * Create a draft order: recompute prices server-side, reserve stock,
     * stage the cart, and email the customer the pay link.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_email' => 'required|email|max:255',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'shipping_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|uuid|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        $checkout = DB::transaction(function () use ($validated) {
            $items = [];
            $serverSubtotal = 0.0;
            $productIds = array_column($validated['items'], 'product_id');
            $products = Product::whereIn('id', $productIds)->lockForUpdate()->get()->keyBy('id');

            foreach ($validated['items'] as $line) {
                $product = $products[$line['product_id']] ?? null;
                if (! $product) {
                    abort(422, 'Product no longer available');
                }

                // NOTE: `inquiry_only` ("Price on Request") products are
                // deliberately NOT refused here. This route is admin-only
                // (POST /admin/draft-orders) and drafting a pay-link is exactly
                // how an agreed enquiry becomes a sale — same reasoning as
                // OrderController::adminStore(). The customer-facing paths
                // (CheckoutController, OrderController::store) do refuse.
                // ⚠️ The line price below is the product's stored list price,
                // which for a POA watch was never shown to anyone — update the
                // product's price to the agreed figure before drafting.

                $qty = (int) $line['quantity'];
                if ($product->stock_quantity !== null && $product->stock_quantity < $qty) {
                    abort(422, "{$product->name} is out of stock");
                }

                $price = (float) $product->price;
                $serverSubtotal += $price * $qty;

                // Canonical, absolute image URL so it survives into the gateway
                // hosted page and the converted order's line items.
                $rawImage = $product->image_url
                    ?: (is_array($product->images) ? ($product->images[0] ?? null) : null);
                $image = $rawImage
                    ? (str_starts_with($rawImage, 'http') ? $rawImage : url($rawImage))
                    : null;

                if ($product->stock_quantity !== null) {
                    $product->decrement('stock_quantity', $qty);
                }

                $items[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => $qty,
                    'price' => $price,
                    'image_url' => $image,
                ];
            }

            $shippingCost = (float) ($validated['shipping_cost'] ?? 0);
            $total = round($serverSubtotal + $shippingCost, 2);

            return PendingCheckout::create([
                'reference' => 'CHK-'.strtoupper(Str::random(10)),
                'status' => 'pending',
                'is_draft' => true,
                'customer_email' => $validated['customer_email'],
                'customer_name' => $validated['customer_name'] ?? null,
                'customer_phone' => $validated['customer_phone'] ?? null,
                'shipping_country' => 'UAE',
                'subtotal' => round($serverSubtotal, 2),
                'discount' => 0,
                'shipping_cost' => $shippingCost,
                'gift_fee' => 0,
                'total' => $total,
                'items' => $items,
                'notes' => $validated['notes'] ?? 'Draft order created by admin',
                // NULL on purpose — see class docblock (cron must not expire drafts).
                'expires_at' => null,
            ]);
        });

        $this->sendInvitation($checkout);

        return response()->json([
            'reference' => $checkout->reference,
            'pay_url' => $this->payUrl($checkout->reference),
            'total' => $checkout->total,
            'email_sent_to' => $checkout->customer_email,
        ], 201);
    }

    /**
     * GET /api/draft-orders/{reference}  (public)
     * Loads a draft for the customer-facing pay page.
     */
    public function show(string $reference)
    {
        $checkout = PendingCheckout::where('reference', $reference)->where('is_draft', true)->first();
        if (! $checkout) {
            return response()->json(['error' => 'Draft order not found.'], 404);
        }

        return response()->json([
            'reference' => $checkout->reference,
            'status' => $checkout->status,
            'order_number' => $checkout->order_number,
            'customer_email' => $checkout->customer_email,
            'customer_name' => $checkout->customer_name,
            'customer_phone' => $checkout->customer_phone,
            'shipping_address' => $checkout->shipping_address,
            'shipping_city' => $checkout->shipping_city,
            'shipping_state' => $checkout->shipping_state,
            'shipping_country' => $checkout->shipping_country,
            'shipping_zip' => $checkout->shipping_zip,
            'items' => $checkout->items ?? [],
            'subtotal' => $checkout->subtotal,
            'discount' => $checkout->discount,
            'shipping_cost' => $checkout->shipping_cost,
            'total' => $checkout->total,
        ]);
    }

    /**
     * PUT /api/draft-orders/{reference}/shipping  (public)
     * The customer saves their shipping address before paying. The gateway
     * endpoints read these fields off the checkout row, so they MUST be set
     * before /api/payments/{gateway} is called.
     */
    public function updateShipping(Request $request, string $reference)
    {
        $validated = $request->validate([
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'shipping_address' => 'required|string|max:500',
            'shipping_city' => 'required|string|max:100',
            'shipping_state' => 'nullable|string|max:100',
            'shipping_country' => 'required|string|max:100',
            'shipping_zip' => 'nullable|string|max:20',
        ]);

        $checkout = PendingCheckout::where('reference', $reference)->where('is_draft', true)->first();
        if (! $checkout) {
            return response()->json(['error' => 'Draft order not found.'], 404);
        }
        if ($checkout->status !== 'pending') {
            return response()->json(['error' => "This draft is {$checkout->status} and can no longer be edited."], 422);
        }

        $checkout->fill([
            'shipping_address' => $validated['shipping_address'],
            'shipping_city' => $validated['shipping_city'],
            'shipping_state' => $validated['shipping_state'] ?? null,
            'shipping_country' => $validated['shipping_country'],
            'shipping_zip' => $validated['shipping_zip'] ?? null,
        ]);
        if (! empty($validated['customer_name'])) {
            $checkout->customer_name = $validated['customer_name'];
        }
        if (! empty($validated['customer_phone'])) {
            $checkout->customer_phone = $validated['customer_phone'];
        }
        $checkout->save();

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/admin/draft-orders/{reference}/send-link
     * Re-send the pay link email and return it for copy/share.
     */
    public function sendLink(string $reference)
    {
        $checkout = PendingCheckout::where('reference', $reference)->where('is_draft', true)->first();
        if (! $checkout) {
            return response()->json(['error' => 'Draft order not found.'], 404);
        }
        if (empty($checkout->customer_email)) {
            return response()->json(['error' => 'Draft has no customer email.'], 422);
        }
        if ($checkout->status === 'converted') {
            return response()->json(['error' => 'This draft already converted to an order.'], 422);
        }

        $this->sendInvitation($checkout);

        return response()->json([
            'ok' => true,
            'pay_url' => $this->payUrl($checkout->reference),
            'recovery_email_sent_at' => $checkout->recovery_email_sent_at,
            'recovery_email_count' => $checkout->recovery_email_count,
        ]);
    }

    /**
     * POST /api/admin/draft-orders/{reference}/cancel
     * Cancel an unpaid draft and release the reserved stock.
     */
    public function cancel(string $reference)
    {
        $checkout = PendingCheckout::where('reference', $reference)->where('is_draft', true)->first();
        if (! $checkout) {
            return response()->json(['error' => 'Draft order not found.'], 404);
        }
        if ($checkout->status === 'converted') {
            return response()->json(['error' => 'This draft already converted to an order and cannot be cancelled.'], 422);
        }

        DB::transaction(function () use ($checkout) {
            $fresh = PendingCheckout::where('id', $checkout->id)->lockForUpdate()->first();
            if (! $fresh || $fresh->status !== 'pending') {
                return;
            }
            foreach (($fresh->items ?? []) as $item) {
                if (! empty($item['product_id'])) {
                    Product::where('id', $item['product_id'])
                        ->whereNotNull('stock_quantity')
                        ->increment('stock_quantity', (int) ($item['quantity'] ?? 1));
                }
            }
            $fresh->update(['status' => 'abandoned']);
        });

        return response()->json(['ok' => true]);
    }

    private function payUrl(string $reference): string
    {
        return rtrim(config('app.url'), '/').'/pay/'.$reference;
    }

    /**
     * Queue the invitation email (queued closure — afterResponse is broken on
     * the prod LiteSpeed server) and stamp the send-tracking columns.
     */
    private function sendInvitation(PendingCheckout $checkout): void
    {
        if (empty($checkout->customer_email)) {
            return;
        }

        $fresh = $checkout->fresh();
        dispatch(function () use ($fresh) {
            try {
                Mail::to($fresh->customer_email)->send(new DraftOrderInvitation($fresh));
            } catch (\Throwable $e) {
                Log::error('Failed to send draft order invitation: '.$e->getMessage(), [
                    'reference' => $fresh->reference,
                ]);
            }
        });

        $checkout->forceFill([
            'recovery_email_sent_at' => now(),
            'recovery_email_count' => (int) $checkout->recovery_email_count + 1,
        ])->save();
    }
}
