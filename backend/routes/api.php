<?php

/**
 * ============================================================
 * API ROUTES — All Backend API URLs
 * ============================================================
 * What this file does:
 *   Defines every API endpoint the frontend can call.
 *   For example: GET /api/products, POST /api/login, etc.
 *
 * Route groups:
 *   - Public routes: Anyone can access (products, categories)
 *   - Auth routes: Requires login (orders, account)
 *   - Admin routes: Requires admin role (dashboard, settings)
 *
 * Related command:
 *   php artisan route:list   → Shows all registered routes
 * ============================================================
 */

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BlogController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\DiscountController;
use App\Http\Controllers\Api\DraftOrderController;
use App\Http\Controllers\Api\GeoController;
use App\Http\Controllers\Api\IntegrationsController;
use App\Http\Controllers\Api\NewsletterController;
use App\Http\Controllers\Api\NotificationsController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductCsvController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\ShippingController;
use App\Http\Controllers\Api\SpinController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\WebhookController;
use App\Models\ProductVariation;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ===================== Public Routes =====================

// Auth (rate limited)
Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('/signup', [AuthController::class, 'signup']);
    Route::post('/signin', [AuthController::class, 'signin']);
    // Completes a 2FA login challenge (password was already verified by signin).
    Route::post('/2fa/verify', [TwoFactorController::class, 'verify']);
    // Password reset (request a link, then set a new password with the token).
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

// Email verification link target — signed + time-limited (clicked straight from
// the email, so no auth token; the signature is the proof). Named
// `verification.verify` to match the URL generated in User::sendEmailVerificationNotification.
Route::get('/auth/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
    ->middleware('signed')
    ->name('verification.verify');

// Products CSV (admin, must be before {idOrSlug} wildcard)
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/products/export-csv', [ProductCsvController::class, 'export']);
    Route::get('/products/csv-template', [ProductCsvController::class, 'template']);
});

// Products (public)
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/next-drop', [ProductController::class, 'nextDrop']);
Route::get('/products/{idOrSlug}', [ProductController::class, 'show']);
Route::get('/products/{id}/related', [ProductController::class, 'related']);
Route::get('/products/{id}/variations', function (string $id) {
    return response()->json(
        \App\Models\ProductVariation::where('product_id', $id)
            ->orderBy('sort_order')
            ->orderBy('variation_type')
            ->orderBy('variation_value')
            ->get()
    );
});

// Categories (public)
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/with-count', [CategoryController::class, 'withCount']);
Route::get('/categories/{slug}', [CategoryController::class, 'show']);

// Brands (public)
Route::get('/brands', [BrandController::class, 'index']);
Route::get('/brands/{slug}', [BrandController::class, 'show']);

// Orders (public - track only, requires order number + email)
Route::post('/orders/track', [OrderController::class, 'track']);

// Recover an abandoned checkout. Public — the storefront's
// /recover-checkout/:reference page calls this to re-hydrate a cart
// from a recovery email link.
Route::get('/checkouts/{reference}/recover', [CheckoutController::class, 'recover']);

// Draft orders (public) — the customer-facing /pay/{reference} page loads
// the admin-staged draft and saves a shipping address before paying via
// the gateway endpoints below.
Route::get('/draft-orders/{reference}', [DraftOrderController::class, 'show']);
Route::put('/draft-orders/{reference}/shipping', [DraftOrderController::class, 'updateShipping']);

// Tabby pre-scoring — eligibility probe run on the Checkout page BEFORE Tabby is
// offered, as the cart/contact details change. NOT a payment action (nothing is
// staged or charged), so it sits outside the strict payments throttle. Lighter
// per-IP throttle so it can re-run on each cart edit without 429-ing the customer.
Route::middleware('throttle:30,1')->post('/payments/tabby/prescore', [PaymentController::class, 'tabbyPrescore']);

// Payments (rate limited)
Route::middleware('throttle:payments')->group(function () {
    Route::post('/checkouts', [CheckoutController::class, 'start']);
    Route::post('/payments/paymennt', [PaymentController::class, 'paymennt']);
    Route::get('/payments/paymennt/verify', [PaymentController::class, 'verifyPaymennt']);
    Route::post('/payments/tabby', [PaymentController::class, 'tabby']);
    Route::get('/payments/tabby/verify', [PaymentController::class, 'verifyTabby']);
    Route::post('/payments/tamara', [PaymentController::class, 'tamara']);
    Route::get('/payments/tamara/verify', [PaymentController::class, 'verifyTamara']);
    Route::post('/payments/stripe', [PaymentController::class, 'stripe']);
    Route::get('/payments/stripe/verify', [PaymentController::class, 'verifyStripe']);
    Route::post('/payments/stripe/cancel', [PaymentController::class, 'cancelStripe']);
    // Cash on Delivery — DEV/STAGING ONLY (controller double-gates on env +
    // payment_cod_enabled). Places an order with no gateway for flow testing.
    Route::get('/payments/cod/verify', [PaymentController::class, 'verifyCod']);
});

// Shipping (public)
Route::get('/shipping/delivery-types', [ShippingController::class, 'deliveryTypes']);
Route::get('/shipping/rates', [ShippingController::class, 'rates']);
Route::post('/shipping/calculate-rate', [ShippingController::class, 'calculateRate']);
Route::post('/shipping/track', [ShippingController::class, 'track']);
// Guest checkout needs UAE city/area lookup for the Topex shipping form
Route::get('/shipping/topex/cities', [ShippingController::class, 'topexCities']);
Route::get('/shipping/topex/areas', [ShippingController::class, 'topexAreas']);
// Aramex city dropdown for non-UAE countries — returns the official list
// Aramex will accept on AWB creation, so we send back the same value here.
Route::get('/shipping/aramex/cities', [ShippingController::class, 'aramexCities']);

// Webhooks (public - no auth, but signature-verified inside the handlers)
Route::post('/webhooks/paymennt', [WebhookController::class, 'paymennt']);
Route::post('/webhooks/tabby', [WebhookController::class, 'tabby']);
Route::post('/webhooks/tamara', [WebhookController::class, 'tamara']);
Route::post('/webhooks/stripe', [WebhookController::class, 'stripe']);

// Settings (public - filtered)
Route::get('/settings', [SettingsController::class, 'index']);
// "all" is excluded so the admin GET /settings/all (registered later) is reachable.
Route::get('/settings/{key}', [SettingsController::class, 'show'])->where('key', '(?!all$)[^/]+');

// Visitor country, read from Cloudflare's CF-IPCountry header. Public and
// unthrottled: it is one cheap header read, called once per visitor on the
// homepage, and throttling it would just push people onto the UAE default.
Route::get('/geo/country', [GeoController::class, 'country']);

// Contact form (public — reuse the auth bucket: 10/min per IP is plenty for a contact form)
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:auth');

// Newsletter signup (public). Reuses the auth bucket (10/min per IP): a real
// person subscribes once, so anything above that is a bot stuffing our Klaviyo
// list with addresses we would then be paying for and mailing.
Route::post('/newsletter/subscribe', [NewsletterController::class, 'subscribe'])
    ->middleware('throttle:auth');

// Blog (public)
Route::get('/blog', [BlogController::class, 'index']);
Route::get('/blog/tags', [BlogController::class, 'tags']);
Route::get('/blog/{slug}', [BlogController::class, 'show']);
Route::get('/blog/{slug}/related', [BlogController::class, 'related']);

// Spin to Win (public). Throttled per IP because each win creates a real code.
Route::get('/spin/config', [SpinController::class, 'publicConfig']);
Route::post('/spin', [SpinController::class, 'spin'])->middleware('throttle:5,1');

// Discounts (public validate)
Route::get('/discounts/validate/{code}', [DiscountController::class, 'validate']);
Route::post('/discounts/{id}/increment-usage', [DiscountController::class, 'incrementUsage']);

// ===================== Guest-Allowed Order Creation =====================
// Placing an order does NOT require login — guest checkout is supported
Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:orders');

// ===================== Auth Required Routes =====================

Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/auth/signout', [AuthController::class, 'signout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::post('/auth/email/resend', [AuthController::class, 'resendVerification']);

    // Two-factor authentication management (the logged-in user sets up their own).
    Route::get('/auth/2fa/status', [TwoFactorController::class, 'status']);
    Route::post('/auth/2fa/enable', [TwoFactorController::class, 'enable']);
    Route::post('/auth/2fa/confirm', [TwoFactorController::class, 'confirm']);
    Route::post('/auth/2fa/disable', [TwoFactorController::class, 'disable']);

    // Orders (auth — viewing orders requires login)
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/by-number/{orderNumber}', [OrderController::class, 'byNumber']);
    Route::get('/orders/{id}/items', [OrderController::class, 'items']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);

    // ===================== Admin Routes =====================
    Route::middleware('admin')->group(function () {
        // Products CSV import
        Route::post('/products/import-csv', [ProductCsvController::class, 'import']);

        // Products CRUD
        Route::post('/products', [ProductController::class, 'store']);
        Route::put('/products/{id}', [ProductController::class, 'update']);
        Route::delete('/products/{id}', [ProductController::class, 'destroy']);

        // Product Variations
        Route::post('/product-variations', function (\Illuminate\Http\Request $request) {
            $validated = $request->validate([
                'product_id' => 'required|uuid|exists:products,id',
                'variation_type' => 'required|string|max:255',
                'variation_value' => 'required|string|max:255',
                'price' => 'nullable|numeric|min:0',
                'sku' => 'nullable|string|max:255',
                'image_url' => 'nullable|string|max:2048',
                'stock_quantity' => 'nullable|integer|min:0',
                'is_active' => 'boolean',
                'sort_order' => 'nullable|integer|min:0',
            ]);

            return response()->json(ProductVariation::create($validated), 201);
        });
        Route::put('/product-variations/{id}', function (\Illuminate\Http\Request $request, string $id) {
            $variation = ProductVariation::findOrFail($id);
            $variation->update($request->only([
                'variation_type', 'variation_value', 'price', 'sku',
                'image_url', 'stock_quantity', 'is_active', 'sort_order',
            ]));

            return response()->json($variation);
        });
        Route::delete('/product-variations/{id}', function (string $id) {
            ProductVariation::findOrFail($id)->delete();

            return response()->json(['success' => true]);
        });

        // Categories CRUD
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::put('/categories/{id}', [CategoryController::class, 'update']);
        Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);
        Route::post('/categories/assign-products', [CategoryController::class, 'assignProducts']);
        Route::post('/categories/unassign-products', [CategoryController::class, 'unassignProducts']);

        // Brands CRUD
        Route::post('/brands', [BrandController::class, 'store']);
        Route::put('/brands/{id}', [BrandController::class, 'update']);
        Route::delete('/brands/{id}', [BrandController::class, 'destroy']);

        // Orders (admin)
        Route::post('/admin/orders', [OrderController::class, 'adminStore']);
        Route::post('/admin/orders/import-shopify', [\App\Http\Controllers\Api\ShopifyOrderImportController::class, 'import']);
        Route::put('/orders/{id}', [OrderController::class, 'update']);
        Route::get('/admin/orders/{id}/events', [OrderController::class, 'events']);
        Route::post('/admin/orders/{id}/comment', [OrderController::class, 'addComment']);
        Route::get('/admin/orders/{id}/invoice', [OrderController::class, 'invoice']);
        // Bulk invoice download (ZIP). Declared before the {id} route above would
        // otherwise be fine either way, but POST keeps the id list out of the URL.
        Route::post('/admin/orders/invoices', [OrderController::class, 'bulkInvoices']);
        Route::post('/admin/orders/{id}/cancel-and-refund', [OrderController::class, 'cancelAndRefund']);
        Route::post('/admin/orders/awbs/merge', [OrderController::class, 'mergeAwbs']);

        // Shipping (admin)
        Route::post('/shipping/create-shipment', [ShippingController::class, 'createShipment']);
        Route::post('/shipping/delivery-types', [ShippingController::class, 'storeDeliveryType']);
        Route::put('/shipping/delivery-types/{id}', [ShippingController::class, 'updateDeliveryType']);
        Route::delete('/shipping/delivery-types/{id}', [ShippingController::class, 'destroyDeliveryType']);
        Route::post('/shipping/rates', [ShippingController::class, 'storeRates']);
        Route::put('/shipping/rates/{id}', [ShippingController::class, 'updateRate']);
        Route::delete('/shipping/rates/{id}', [ShippingController::class, 'destroyRate']);

        // Admin panel
        Route::get('/admin/dashboard', [AdminController::class, 'dashboard']);
        Route::get('/admin/customers', [AdminController::class, 'customers']);
        Route::get('/admin/customers/orders', [AdminController::class, 'customerOrders']);
        Route::get('/admin/customers/export-csv', [AdminController::class, 'exportCustomersCsv']);
        Route::get('/admin/customers/csv-template', [AdminController::class, 'customersCsvTemplate']);
        Route::post('/admin/customers/import-csv', [AdminController::class, 'importCustomersCsv']);
        Route::get('/admin/abandoned-checkouts', [CheckoutController::class, 'adminList']);
        Route::get('/admin/abandoned-checkouts/stats', [CheckoutController::class, 'stats']);
        Route::post('/admin/abandoned-checkouts/{reference}/send-recovery', [CheckoutController::class, 'sendRecoveryEmail']);
        Route::post('/admin/abandoned-checkouts/{reference}/mark-recovered', [CheckoutController::class, 'markRecovered']);
        Route::post('/admin/abandoned-checkouts/{reference}/expire', [CheckoutController::class, 'expireNow']);

        // Contact form messages (admin inbox)
        Route::get('/admin/contact-messages', [ContactController::class, 'index']);
        Route::patch('/admin/contact-messages/{id}/read', [ContactController::class, 'markRead']);
        Route::post('/admin/contact-messages/{id}/reply', [ContactController::class, 'reply']);
        Route::delete('/admin/contact-messages/{id}', [ContactController::class, 'destroy']);

        // Draft orders (admin) — Shopify-style staged carts the admin builds
        // for a customer and sends a /pay/{reference} link to.
        Route::get('/admin/draft-orders', [DraftOrderController::class, 'index']);
        Route::post('/admin/draft-orders', [DraftOrderController::class, 'store']);
        Route::post('/admin/draft-orders/{reference}/send-link', [DraftOrderController::class, 'sendLink']);
        Route::post('/admin/draft-orders/{reference}/cancel', [DraftOrderController::class, 'cancel']);

        Route::get('/customers', [AdminController::class, 'customers']);
        Route::post('/admin/reset-password', [AdminController::class, 'resetPassword']);
        Route::post('/admin/change-password', [AdminController::class, 'changePassword']);

        // Email notification preview (must be before the bell-notifications wildcard routes)
        Route::get('/admin/notifications/preview/{type}', [NotificationsController::class, 'preview']);

        // Bell notifications
        Route::get('/admin/notifications', [AdminController::class, 'notifications']);
        Route::get('/admin/notifications/unread-count', [AdminController::class, 'unreadNotificationCount']);
        Route::post('/admin/notifications/read-all', [AdminController::class, 'markAllNotificationsRead']);
        Route::post('/admin/notifications/{id}/read', [AdminController::class, 'markNotificationRead']);
        Route::post('/admin/make-admin', [AdminController::class, 'makeAdmin']);
        Route::delete('/admin/remove-admin/{userId}', [AdminController::class, 'removeAdmin']);

        // Upload
        Route::post('/upload', [UploadController::class, 'upload']);
        Route::post('/upload/multiple', [UploadController::class, 'uploadMultiple']);
        Route::delete('/upload/{filename}', [UploadController::class, 'destroy']);

        // Payment / shipping credentials (encrypted, admin only, never public)
        Route::get('/admin/integrations', [IntegrationsController::class, 'index']);
        Route::put('/admin/integrations/{provider}', [IntegrationsController::class, 'update']);

        // Settings (admin)
        Route::get('/settings/all', [SettingsController::class, 'all']);
        Route::put('/settings', [SettingsController::class, 'batch']);
        Route::put('/settings/{key}', [SettingsController::class, 'update']);
        Route::post('/settings/batch', [SettingsController::class, 'batch']);
        Route::post('/settings/fetch-currency-rates', [SettingsController::class, 'fetchCurrencyRates']);
        Route::delete('/settings/{key}', [SettingsController::class, 'destroy']);

        // Newsletter subscribers (admin)
        Route::get('/admin/newsletter-subscribers', [NewsletterController::class, 'index']);
        Route::delete('/admin/newsletter-subscribers/{id}', [NewsletterController::class, 'destroy']);

        // Blog (admin)
        Route::get('/admin/blog', [BlogController::class, 'adminIndex']);
        Route::post('/admin/blog', [BlogController::class, 'store']);
        Route::put('/admin/blog/{id}', [BlogController::class, 'update']);
        Route::delete('/admin/blog/{id}', [BlogController::class, 'destroy']);

        // Spin to Win (admin)
        Route::get('/admin/spin', [SpinController::class, 'adminShow']);
        Route::put('/admin/spin', [SpinController::class, 'adminUpdate']);

        // Discounts (admin)
        Route::get('/discounts', [DiscountController::class, 'index']);
        Route::post('/discounts', [DiscountController::class, 'store']);
        Route::put('/discounts/{id}', [DiscountController::class, 'update']);
        Route::delete('/discounts/{id}', [DiscountController::class, 'destroy']);

        // Test emails (admin only)
        Route::post('/admin/test-emails', function (\Illuminate\Http\Request $request) {
            $request->validate(['email' => 'required|email']);
            $email = $request->email;

            $order = \App\Models\Order::with('items')->latest()->first();
            if (! $order) {
                $order = new \App\Models\Order;
                $order->order_number = 'AD20260326-TEST';
                $order->customer_name = 'Test Customer';
                $order->customer_email = $email;
                $order->customer_phone = '+971 50 123 4567';
                $order->payment_method = 'paymennt';
                $order->payment_status = 'paid';
                $order->status = 'processing';
                $order->subtotal = 1250.00;
                $order->discount = 100.00;
                $order->shipping_cost = 0.00;
                $order->total = 1150.00;
                $order->shipping_address = '123 Sheikh Zayed Road';
                $order->shipping_city = 'Dubai';
                $order->shipping_country = 'UAE';
                $order->tracking_number = 'TRK-98765432';

                $item1 = new \App\Models\OrderItem;
                $item1->product_name = 'Rolex Submariner Date';
                $item1->quantity = 1;
                $item1->unit_price = 850.00;
                $item1->total_price = 850.00;

                $item2 = new \App\Models\OrderItem;
                $item2->product_name = 'Omega Speedmaster Professional';
                $item2->quantity = 1;
                $item2->unit_price = 400.00;
                $item2->total_price = 400.00;

                $order->setRelation('items', collect([$item1, $item2]));
            }

            $results = [];
            $emails = [
                'Order Confirmation' => fn () => new \App\Mail\OrderConfirmation($order),
                'Payment Confirmed' => fn () => new \App\Mail\PaymentConfirmation($order, 'paid'),
                'Payment Failed' => fn () => new \App\Mail\PaymentConfirmation($order, 'failed'),
                'Order Processing' => fn () => new \App\Mail\OrderStatusUpdate($order, 'processing'),
                'Order Shipped' => fn () => new \App\Mail\OrderStatusUpdate($order, 'shipped'),
            ];

            foreach ($emails as $name => $makeMailable) {
                try {
                    \Illuminate\Support\Facades\Mail::to($email)->send($makeMailable());
                    $results[$name] = 'sent';
                } catch (\Exception $e) {
                    $results[$name] = 'failed: '.$e->getMessage();
                }
            }

            return response()->json(['results' => $results]);
        });
    });
});
