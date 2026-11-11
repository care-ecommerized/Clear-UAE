<?php

/**
 * ============================================================
 * ORDER CONTROLLER — Handles Order API Requests
 * ============================================================
 * What this file does:
 *   Handles all order-related API calls:
 *   - Create new orders from checkout
 *   - List user's orders
 *   - Get order details & tracking
 *   - Update order status (admin only)
 *   - Cancel/refund orders (admin only)
 *
 * API endpoints handled:
 *   POST /api/orders          → Create new order
 *   GET  /api/orders          → List user's orders
 *   GET  /api/orders/{id}     → Order details
 *   PUT  /api/orders/{id}     → Update status (admin)
 * ============================================================
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\NewOrderAdminNotification;
use App\Mail\OrderCancellation;
use App\Mail\OrderConfirmation;
use App\Mail\OrderRefund;
use App\Mail\OrderStatusUpdate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\AdminNotifier;
use App\Services\InvoiceGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        // user = the linked customer account (if any) — the admin shows a
        // Guest / Account tag and "customer since".
        $query = Order::with(['items.product:id,name_ar', 'user:id,created_at']);

        if (! $request->user()->isAdmin()) {
            $query->where('user_id', $request->user()->id);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $orders = $query->orderByDesc('created_at')
            ->limit(min($request->integer('limit', 50), 100))
            ->offset($request->integer('offset', 0))
            ->get();

        // Admin: add the invoice's VAT figures (same InvoiceTax rules as the
        // PDF invoice) so the Excel export has VAT columns that match it.
        if ($request->user()->isAdmin()) {
            $tax = app(\App\Services\InvoiceTax::class);
            $orders->each(function (Order $o) use ($tax) {
                $t = $tax->for($o);
                $o->setAttribute('vat_rate', $t['rate']);
                $o->setAttribute('vat_amount', $t['vat']);
                $o->setAttribute('net_amount', $t['net']);
            });
        }

        return response()->json($orders);
    }

    public function show(Request $request, string $id)
    {
        $query = Order::with('items')->where('id', $id);

        if (! $request->user()->isAdmin()) {
            $query->where('user_id', $request->user()->id);
        }

        $order = $query->firstOrFail();

        return response()->json($order);
    }

    public function byNumber(Request $request, string $orderNumber)
    {
        // Scope by user ownership unless the requester is an admin —
        // matches the pattern in show() above. Order numbers are
        // sequential (prefix + number) and easily enumerable, so we MUST check
        // ownership here, not just rely on auth:sanctum.
        $query = Order::with('items')->where('order_number', $orderNumber);

        if (! $request->user()->isAdmin()) {
            $query->where('user_id', $request->user()->id);
        }

        $order = $query->firstOrFail();

        return response()->json($order);
    }

    /**
     * GET /api/orders/{id}/items
     * Returns just the line items for an order — used by the admin
     * order-detail dialog. Scoped to the order's owner unless the
     * requester is an admin.
     */
    public function items(Request $request, string $id)
    {
        $query = Order::with('items')->where('id', $id);

        if (! $request->user()->isAdmin()) {
            $query->where('user_id', $request->user()->id);
        }

        $order = $query->firstOrFail();

        return response()->json($order->items);
    }

    public function track(Request $request)
    {
        $request->validate([
            'order_number' => 'required|string',
            'email' => 'required|email',
        ]);

        $order = Order::with('items')
            ->where('order_number', $request->order_number)
            ->where('customer_email', $request->email)
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found. Please check your order number and ensure you are using the same email address used during checkout.'], 404);
        }

        return response()->json($order);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_name' => 'required|string|max:255',
            'customer_email' => 'required|email|max:255',
            'customer_phone' => 'required|string|max:50',
            'shipping_address' => 'nullable|string|max:500',
            'shipping_city' => 'nullable|string|max:100',
            'shipping_country' => 'nullable|string|max:100',
            'shipping_state' => 'nullable|string|max:100',
            'shipping_postal_code' => 'nullable|string|max:20',
            'payment_method' => 'nullable|string|in:paymennt,tabby,tamara',
            'subtotal' => 'required|numeric|min:0',
            'shipping_cost' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'total' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|uuid|exists:products,id',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
        ]);

        $order = DB::transaction(function () use ($validated, $request) {
            $items = $validated['items'];
            unset($validated['items']);

            // Server-side price validation: recalculate subtotal from actual product prices
            $serverSubtotal = 0;
            $productIds = array_column($items, 'product_id');
            $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

            foreach ($items as &$item) {
                $product = $products[$item['product_id']] ?? null;
                if ($product) {
                    // "Price on Request" watches are not sold through the
                    // customer-facing path — the price was never shown, so it
                    // must never be charged. (adminStore() below is deliberately
                    // NOT guarded: an admin placing the order by hand is exactly
                    // how a completed enquiry becomes a sale.)
                    if ($product->inquiry_only) {
                        abort(422, "{$product->name} is available by enquiry only");
                    }

                    $item['price'] = (float) $product->price;
                    $serverSubtotal += $item['price'] * $item['quantity'];

                    // Decrement stock
                    if ($product->stock_quantity !== null) {
                        $product->decrement('stock_quantity', $item['quantity']);
                    }
                }
            }
            unset($item);

            // Use server-calculated subtotal
            $validated['subtotal'] = round($serverSubtotal, 2);
            $discount = (float) ($validated['discount_amount'] ?? 0);
            $shippingCost = (float) ($validated['shipping_cost'] ?? 0);
            $validated['total'] = round($serverSubtotal - $discount + $shippingCost, 2);

            // Remap frontend field names to model field names
            if (isset($validated['discount_amount'])) {
                $validated['discount'] = $validated['discount_amount'];
                unset($validated['discount_amount']);
            }
            if (isset($validated['shipping_postal_code'])) {
                $validated['shipping_zip'] = $validated['shipping_postal_code'];
                unset($validated['shipping_postal_code']);
            }

            $order = Order::create(array_merge(
                $validated,
                ['user_id' => $request->user()?->id]
            ));

            foreach ($items as $item) {
                $productImage = $item['image_url']
                    ?? $item['product_image']
                    ?? optional(\App\Models\Product::find($item['product_id'] ?? null))->image_url;

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'product_image' => $productImage,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['price'],
                    'total_price' => $item['price'] * $item['quantity'],
                ]);
            }

            return $order;
        });

        // Queue the emails instead of sending them in-request. SMTP to
        // the mail server (with TLS handshake + PDF invoice generation
        // via TCPDF) was adding 5–8s to checkout. Queued closures are sent by
        // the scheduler's queue worker, so the customer is redirected to the
        // payment gateway immediately.
        $order->load('items');
        AdminNotifier::orderPlaced($order);
        $orderForMail = $order;

        if ($orderForMail->customer_email) {
            dispatch(function () use ($orderForMail) {
                try {
                    Mail::to($orderForMail->customer_email)->send(new OrderConfirmation($orderForMail));
                } catch (\Throwable $e) {
                    Log::error('Failed to send order confirmation email: '.$e->getMessage(), [
                        'order' => $orderForMail->order_number,
                    ]);
                }
            });
        }

        $adminRecipients = \App\Services\AdminNotifier::adminEmails();
        if (empty($adminRecipients)) {
            Log::warning('Admin order notification skipped: no admin email set (ADMIN_NOTIFICATION_EMAILS or store email)', [
                'order' => $orderForMail->order_number,
            ]);
        } else {
            dispatch(function () use ($orderForMail, $adminRecipients) {
                try {
                    Mail::to($adminRecipients)->send(new NewOrderAdminNotification($orderForMail));
                } catch (\Throwable $e) {
                    Log::error('Failed to send admin order notification: '.$e->getMessage(), [
                        'order' => $orderForMail->order_number,
                        'recipients' => $adminRecipients,
                    ]);
                }
            });
        }

        return response()->json($order, 201);
    }

    /**
     * POST /api/admin/orders
     *
     * Admin manual order creation (e.g. phone/WhatsApp orders). Unlike the
     * customer checkout flow, this skips the payment gateway entirely and
     * creates the Order row directly. Prices are recomputed server-side from
     * the product records — the client-supplied unit_price is never trusted.
     */
    public function adminStore(Request $request)
    {
        $validated = $request->validate([
            'customer_email' => 'required|email|max:255',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'payment_method' => 'nullable|string|in:cod,paymennt,stripe,tabby,tamara',
            'shipping_cost' => 'nullable|numeric|min:0',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|uuid|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        $order = DB::transaction(function () use ($validated, $request) {
            $items = $validated['items'];
            $productIds = array_column($items, 'product_id');
            $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

            $subtotal = 0;
            $orderItems = [];
            foreach ($items as $item) {
                $product = $products[$item['product_id']] ?? null;
                if (! $product) {
                    continue;
                }

                $price = (float) $product->price;
                $quantity = (int) $item['quantity'];
                $subtotal += $price * $quantity;

                if ($product->stock_quantity !== null) {
                    $product->decrement('stock_quantity', $quantity);
                }

                $orderItems[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_image' => $product->image_url,
                    'quantity' => $quantity,
                    'unit_price' => $price,
                    'total_price' => $price * $quantity,
                ];
            }

            $shippingCost = (float) ($validated['shipping_cost'] ?? 0);
            $paymentMethod = $validated['payment_method'] ?? 'cod';

            $order = Order::create([
                'user_id' => \App\Models\User::where('email', strtolower($validated['customer_email']))->value('id'),
                'customer_email' => $validated['customer_email'],
                'customer_name' => $validated['customer_name'] ?? null,
                'customer_phone' => $validated['customer_phone'] ?? null,
                'subtotal' => round($subtotal, 2),
                'discount' => 0,
                'shipping_cost' => round($shippingCost, 2),
                'total' => round($subtotal + $shippingCost, 2),
                'status' => 'pending',
                'payment_method' => $paymentMethod,
                'payment_status' => 'pending',
            ]);

            foreach ($orderItems as $oi) {
                OrderItem::create(array_merge(['order_id' => $order->id], $oi));
            }

            $order->logEvent(
                'order_created',
                'Order created manually',
                'Created from the admin panel',
                $request->user()?->name ?? 'Admin'
            );

            return $order;
        });

        $order->load('items');
        AdminNotifier::orderPlaced($order);

        if ($order->customer_email) {
            $orderForMail = $order;
            dispatch(function () use ($orderForMail) {
                try {
                    Mail::to($orderForMail->customer_email)->send(new OrderConfirmation($orderForMail));
                } catch (\Throwable $e) {
                    Log::error('Failed to send admin-created order confirmation email: '.$e->getMessage(), [
                        'order' => $orderForMail->order_number,
                    ]);
                }
            });
        }

        return response()->json($order, 201);
    }

    public function update(Request $request, string $id)
    {
        $order = Order::findOrFail($id);
        $validated = $request->validate([
            'status' => 'sometimes|string|in:pending,confirmed,processing,shipped,delivered,cancelled',
            'payment_status' => 'sometimes|string|in:pending,paid,failed,refunded',
            'tracking_number' => 'nullable|string|max:100',
            'shipping_label_url' => 'nullable|string|max:2048',
            'shipping_provider' => 'nullable|string|in:aramex,topex',
            'notes' => 'nullable|string|max:1000',
        ]);
        $oldStatus = $order->status;
        $oldPaymentStatus = $order->payment_status;
        $oldTracking = $order->tracking_number;
        $order->update($validated);

        $statusChanged = isset($validated['status']) && $validated['status'] !== $oldStatus;
        $newStatus = $validated['status'] ?? null;
        $becameRefunded = isset($validated['payment_status'])
            && $validated['payment_status'] === 'refunded'
            && $oldPaymentStatus !== 'refunded';

        // Record timeline events for what the admin just changed.
        $actor = optional($request->user())->name ?: 'Admin';
        if ($statusChanged) {
            $order->logEvent('status', 'Status changed to '.ucfirst((string) $newStatus), null, $actor);
        }
        if ($becameRefunded) {
            $order->logEvent('refund', 'Payment marked as refunded', null, $actor);
        }
        if (($validated['payment_status'] ?? null) === 'paid' && $oldPaymentStatus !== 'paid') {
            $order->logEvent('payment', $order->payment_method === 'cod' ? 'Cash collected' : 'Marked as paid', null, $actor);
        }
        if (! empty($validated['tracking_number']) && $validated['tracking_number'] !== $oldTracking) {
            $provider = $validated['shipping_provider'] ?? $order->shipping_provider;
            $order->logEvent('shipping', 'Tracking number added', $validated['tracking_number'].($provider ? ' ('.ucfirst((string) $provider).')' : ''), $actor);
        }

        // Send the bell-notification synchronously (fast — DB row only) so the
        // admin sees the cancel pulse instantly. The customer email is heavier
        // (SMTP) and is queued.
        if ($statusChanged && $newStatus === 'cancelled') {
            AdminNotifier::orderCancelled($order);
        }

        // SMTP can take 3–8s. Queue it so the admin's status-change click returns
        // immediately. Fail-loud: if delivery fails we surface a bell
        // notification so admin can resend manually.
        if ($statusChanged || $becameRefunded) {
            dispatch(function () use ($order, $statusChanged, $newStatus, $becameRefunded) {
                $order->load('items');

                if ($statusChanged) {
                    $mailable = $newStatus === 'cancelled'
                        ? new OrderCancellation($order)
                        : new OrderStatusUpdate($order, $newStatus);
                    $emailType = $newStatus === 'cancelled' ? 'Cancellation' : ucfirst((string) $newStatus).' update';

                    try {
                        Mail::to($order->customer_email)->send($mailable);
                    } catch (\Throwable $e) {
                        Log::error('Failed to send order status update email: '.$e->getMessage());
                        AdminNotifier::customerEmailFailed($order, $emailType, $e->getMessage());
                    }
                }

                if ($becameRefunded) {
                    try {
                        Mail::to($order->customer_email)->send(new OrderRefund($order));
                    } catch (\Throwable $e) {
                        Log::error('Failed to send refund email: '.$e->getMessage());
                        AdminNotifier::customerEmailFailed($order, 'Refund', $e->getMessage());
                    }
                }
            });
        }

        return response()->json($order);
    }

    /**
     * GET /api/admin/orders/{id}/events
     * Returns the order's activity timeline, newest first.
     */
    public function events(string $id)
    {
        $order = Order::findOrFail($id);

        return response()->json($order->events()->get());
    }

    /**
     * POST /api/admin/orders/{id}/comment
     * Body: { comment: string }
     * Adds a staff comment to the order timeline.
     */
    public function addComment(Request $request, string $id)
    {
        $order = Order::findOrFail($id);
        $validated = $request->validate([
            'comment' => 'required|string|max:2000',
        ]);
        $actor = optional($request->user())->name ?: 'Admin';
        $event = $order->logEvent('comment', $validated['comment'], null, $actor);

        return response()->json($event, 201);
    }

    /**
     * GET /api/admin/orders/{id}/invoice
     * Streams the customer invoice PDF inline so the browser opens it
     * in a new tab. Frontend fetches as a blob (auth header attached
     * by the api client) and uses URL.createObjectURL to display it.
     */
    public function invoice(string $id)
    {
        $order = Order::with('items')->findOrFail($id);
        $generator = new InvoiceGenerator;
        $pdf = $generator->generate($order);
        $filename = $generator->filename($order);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * POST /api/admin/orders/invoices
     * Streams a ZIP of customer invoice PDFs for the selected orders, so admin
     * can pull a batch in one download instead of opening them one at a time.
     *
     * Capped at 50 orders per request: each invoice is a separate TCPDF render
     * (which needs ~512M and several hundred ms), and an uncapped batch would
     * sit on a request worker long enough to hit the shared host's timeout.
     */
    public function bulkInvoices(Request $request)
    {
        $validated = $request->validate([
            'order_ids' => 'required|array|min:1|max:50',
            'order_ids.*' => 'required|uuid|exists:orders,id',
        ]);

        $orders = Order::with('items')
            ->whereIn('id', $validated['order_ids'])
            ->orderBy('order_number')
            ->get();

        if ($orders->isEmpty()) {
            return response()->json(['message' => 'No matching orders found.'], 404);
        }

        $generator = new InvoiceGenerator;
        $zipPath = tempnam(sys_get_temp_dir(), 'invoices_').'.zip';

        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return response()->json(['message' => 'Could not create the archive.'], 500);
        }

        foreach ($orders as $order) {
            // Generated one at a time and added straight to the archive; holding
            // every PDF in memory first is what would actually blow the limit.
            $zip->addFromString($generator->filename($order), $generator->generate($order));
        }

        $zip->close();

        $name = 'invoices-'.now()->setTimezone(config('app.display_timezone'))->format('Y-m-d').'.zip';

        return response()->download($zipPath, $name, [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store',
        ])->deleteFileAfterSend(true);
    }

    /**
     * POST /api/admin/orders/{id}/cancel-and-refund
     * Cancels the order, attempts an automatic refund through the
     * payment gateway, restores stock, and emails the customer.
     *
     * Behaviour:
     *  - Already-cancelled orders are a no-op (idempotent).
     *  - If the gateway refund fails, the order is still cancelled
     *    locally; payment_status is set to "pending_refund" so the
     *    admin knows to finish the refund manually in the gateway
     *    dashboard. The error is returned to the caller.
     *  - If the order was paid with paymennt and we have a
     *    payment_reference (= Paymennt checkoutId), we call
     *    PaymentController::refundPaymennt(). Other payment methods
     *    (legacy) skip the gateway call entirely.
     */
    public function cancelAndRefund(Request $request, string $id, PaymentController $payments, \App\Services\TabbyService $tabby, \App\Services\TamaraService $tamara)
    {
        // Claim the order under a row lock first (without holding the lock
        // across the gateway call): of two concurrent requests — a
        // double-clicked button — only the winner refunds and restores
        // stock. The loser sees it cancelled and returns.
        $claimed = DB::transaction(function () use ($id) {
            $o = Order::where('id', $id)->lockForUpdate()->firstOrFail();
            if ($o->status === 'cancelled') {
                return null;
            }
            $prevPaymentStatus = $o->payment_status;
            $o->status = 'cancelled';
            $o->save();

            return [$o->fresh('items'), $prevPaymentStatus];
        });

        if ($claimed === null) {
            return response()->json([
                'success' => true,
                'alreadyCancelled' => true,
                'order' => Order::with('items')->findOrFail($id),
            ]);
        }

        [$order, $prevPaymentStatus] = $claimed;

        $reason = (string) $request->input('reason', '');
        $note = $reason !== '' ? $reason : 'Order cancelled by admin';
        $refundResult = ['success' => true, 'gatewayStatus' => null, 'error' => null];

        // Dispatch to the right gateway based on payment_method. Skips
        // gateway call entirely if the order wasn't paid online (legacy/COD).
        $shouldRefund = $prevPaymentStatus === 'paid' && $order->payment_reference;
        if ($shouldRefund) {
            $refundResult = match ($order->payment_method) {
                'paymennt' => $payments->refundPaymennt($order->payment_reference, (float) $order->total, $note),
                // Refund in the currency the order was actually charged in. A SAR
                // order refunded with its AED figure returns the wrong money.
                'tabby' => $tabby->refund($order->payment_reference, (float) ($order->gateway_amount ?? $order->total), $note),
                'tamara' => $tamara->refund($order->payment_reference, (float) ($order->gateway_amount ?? $order->total), $note, (string) ($order->gateway_currency ?? \App\Support\Brand::currency())),
                // Full refund of the PaymentIntent, in the currency it was charged in.
                'stripe' => $payments->refundStripe($order->payment_reference, $note),
                default => $refundResult,
            };
        }

        DB::transaction(function () use ($order, $refundResult, $prevPaymentStatus) {
            // Restore stock for each item back into the catalog.
            foreach ($order->items as $item) {
                if (! empty($item->product_id)) {
                    Product::where('id', $item->product_id)
                        ->whereNotNull('stock_quantity')
                        ->increment('stock_quantity', (int) $item->quantity);
                }
            }

            // (status was already set to 'cancelled' when the order was claimed.)
            // Only mark refunded locally if the gateway accepted the refund —
            // otherwise flag pending_refund so the admin knows to finish it manually.
            if ($prevPaymentStatus === 'paid') {
                $order->payment_status = $refundResult['success'] ? 'refunded' : 'pending_refund';
            }

            $order->save();
        });

        // Bell notification fires now (fast, just a DB insert) so the admin
        // dashboard shows the pulse right away.
        AdminNotifier::orderCancelled($order);

        // Timeline events for the cancellation + refund outcome.
        $actor = optional($request->user())->name ?: 'Admin';
        $order->logEvent('status', 'Order cancelled', $reason !== '' ? $reason : null, $actor);
        if ($shouldRefund && in_array($order->payment_method, ['paymennt', 'stripe', 'tabby', 'tamara'], true)) {
            if ($refundResult['success']) {
                $order->logEvent('refund', 'Refund issued', 'Refunded via '.ucfirst((string) $order->payment_method), $actor);
            } else {
                $order->logEvent('refund', 'Refund needs manual completion', $refundResult['error'] ?? null, $actor);
            }
        }

        // Klaviyo "Cancelled Order" (+ "Refunded Order" when the gateway
        // actually returned the money). Queued and in its own closure so a
        // Klaviyo outage cannot block the customer's cancellation email.
        dispatch(function () use ($order) {
            try {
                app(\App\Services\Klaviyo::class)->trackOrderCancelled($order);
            } catch (\Throwable $e) {
                Log::error('Failed to send Klaviyo cancellation events: '.$e->getMessage(), [
                    'order' => $order->order_number,
                ]);
            }
        });

        // Customer emails are queued — SMTP is slow and the admin's
        // "Cancel & refund" button shouldn't hang on it.
        $sendRefundEmail = $order->payment_status === 'refunded';
        dispatch(function () use ($order, $sendRefundEmail) {
            $order->load('items');

            try {
                Mail::to($order->customer_email)->send(new OrderCancellation($order));
            } catch (\Throwable $e) {
                Log::error('Failed to send cancellation email: '.$e->getMessage());
                AdminNotifier::customerEmailFailed($order, 'Cancellation', $e->getMessage());
            }

            if ($sendRefundEmail) {
                try {
                    Mail::to($order->customer_email)->send(new OrderRefund($order));
                } catch (\Throwable $e) {
                    Log::error('Failed to send refund email: '.$e->getMessage());
                    AdminNotifier::customerEmailFailed($order, 'Refund', $e->getMessage());
                }
            }
        });

        // Always 200: the cancellation itself succeeded. Refund outcome
        // is reported in the body so the admin UI can distinguish
        // "fully done" from "cancelled but refund needs manual completion".
        return response()->json([
            'success' => true,
            'order' => $order,
            'refund' => [
                'attempted' => $shouldRefund && in_array($order->payment_method, ['paymennt', 'stripe', 'tabby', 'tamara'], true),
                'success' => $refundResult['success'],
                'gatewayStatus' => $refundResult['gatewayStatus'],
                'error' => $refundResult['error'],
            ],
        ]);
    }

    /**
     * POST /api/admin/orders/awbs/merge
     * Body: { order_ids: string[] }
     *
     * Fetches each order's shipping_label_url, merges them into one PDF
     * (one AWB per page run; multi-page AWBs keep all their pages), and
     * streams the merged PDF inline so the frontend can blob-open it.
     *
     * Topex labels are stored locally under storage/app/public/labels/...
     * — we read them directly from disk. Aramex labels are remote URLs;
     * we fetch them over HTTP. Orders missing a label are skipped (their
     * order_number is reported back in the X-Skipped header).
     */
    public function mergeAwbs(Request $request)
    {
        $validated = $request->validate([
            'order_ids' => 'required|array|min:1|max:50',
            'order_ids.*' => 'required|uuid',
        ]);

        $orders = Order::whereIn('id', $validated['order_ids'])
            ->whereNotNull('shipping_label_url')
            ->orderBy('order_number')
            ->get();

        if ($orders->isEmpty()) {
            return response()->json(['error' => 'No orders with AWBs found'], 422);
        }

        $merger = new Fpdi;
        $skipped = [];

        foreach ($orders as $order) {
            $pdfBytes = $this->fetchLabelBytes($order->shipping_label_url);
            if ($pdfBytes === null) {
                $skipped[] = $order->order_number;

                continue;
            }

            // Write to a temp file because FPDI's setSourceFile needs a path.
            $tmp = tempnam(sys_get_temp_dir(), 'awb_').'.pdf';
            file_put_contents($tmp, $pdfBytes);

            try {
                $pageCount = $merger->setSourceFile($tmp);
                for ($i = 1; $i <= $pageCount; $i++) {
                    $tplId = $merger->importPage($i);
                    $size = $merger->getTemplateSize($tplId);
                    $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
                    $merger->AddPage($orientation, [$size['width'], $size['height']]);
                    $merger->useTemplate($tplId);
                }
            } catch (\Throwable $e) {
                Log::warning("AWB merge: failed to import {$order->order_number} ({$e->getMessage()})");
                $skipped[] = $order->order_number;
            } finally {
                @unlink($tmp);
            }
        }

        $merged = $merger->Output('S');
        $filename = 'AWBs-'.now()->format('Y-m-d-His').'.pdf';

        return response($merged, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
            'X-Skipped' => implode(',', $skipped),
        ]);
    }

    /**
     * Read the bytes of an AWB label given its public URL.
     * Local /storage/labels/* URLs are read from disk; remote URLs (Aramex)
     * are fetched over HTTP. Returns null on failure.
     */
    private function fetchLabelBytes(string $url): ?string
    {
        // Local /storage/<path> -> read from public disk
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $linkHost = parse_url($url, PHP_URL_HOST);
        $isLocal = ! $linkHost || $linkHost === $appHost;
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        if ($isLocal && str_starts_with($path, '/storage/')) {
            $relative = substr($path, strlen('/storage/'));
            if (Storage::disk('public')->exists($relative)) {
                return Storage::disk('public')->get($relative);
            }
        }

        try {
            $response = Http::timeout(15)->get($url);
            if ($response->successful()) {
                return $response->body();
            }
            Log::warning("AWB merge: HTTP {$response->status()} fetching {$url}");
        } catch (\Throwable $e) {
            Log::warning("AWB merge: exception fetching {$url}: {$e->getMessage()}");
        }

        return null;
    }
}
