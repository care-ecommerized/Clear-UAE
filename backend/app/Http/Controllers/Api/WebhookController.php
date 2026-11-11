<?php

/**
 * ============================================================
 * WEBHOOK CONTROLLER — Payment Webhook Handling
 * ============================================================
 * Receives notifications from Paymennt when a payment succeeds
 * or fails, then updates the order's payment status and sends
 * confirmation emails to the customer.
 *
 * API endpoints:
 *   POST /api/webhooks/paymennt  — Receives Paymennt webhooks
 *   POST /api/webhooks/stripe    — Receives Stripe webhooks (signed)
 * ============================================================
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\NewOrderAdminNotification;
use App\Mail\OrderStatusUpdate;
use App\Mail\PaymentConfirmation;
use App\Models\Order;
use App\Models\PendingCheckout;
use App\Services\TabbyService;
use App\Services\TamaraService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class WebhookController extends Controller
{
    /**
     * Strip PII from webhook payloads before logging. Webhook bodies
     * include customer name, email, address, phone — none of which we
     * need to investigate failures. Keep only identifiers and amount.
     */
    private function summarize(array $body): array
    {
        $payload = $body['result'] ?? $body;
        $allow = [
            'id', 'orderId', 'orderID', 'order_id', 'reference',
            'status', 'state', 'event_type', 'event_name',
            'amount', 'total_amount', 'currency',
            'created_at', 'paid_at', 'authorized_at', 'expires_at',
        ];
        $kept = [];
        foreach ($allow as $key) {
            if (array_key_exists($key, $payload)) {
                $value = $payload[$key];
                $kept[$key] = is_array($value) ? json_encode($value) : (string) $value;
            } elseif (array_key_exists($key, $body)) {
                $value = $body[$key];
                $kept[$key] = is_array($value) ? json_encode($value) : (string) $value;
            }
        }

        return $kept;
    }

    /**
     * POST /api/webhooks/paymennt
     * Called by Paymennt when a checkout's status changes.
     *
     * SECURITY: Paymennt's webhook signature scheme is not publicly
     * documented, so we cannot verify the request via HMAC. Instead, we
     * treat the webhook body as a HINT only — we re-fetch the canonical
     * status from Paymennt's API using the checkout id and act on THAT.
     *
     * Without this, an attacker who learned a CHK-XXXXXXXX reference
     * could forge a "PAID" webhook and trick us into creating a real
     * Order with confirmation emails.
     */
    public function paymennt(Request $request)
    {
        $body = $request->all();

        Log::info('Paymennt webhook received', ['summary' => $this->summarize($body)]);

        // Paymennt may wrap the payload under "result" — handle both shapes
        $payload = $body['result'] ?? $body;

        $merchantOrderId = $payload['orderId']
            ?? $payload['orderID']
            ?? $body['orderId']
            ?? null;

        $checkoutId = $payload['id'] ?? $body['id'] ?? null;

        if (! $merchantOrderId || ! $checkoutId) {
            Log::warning('Paymennt webhook missing orderId or id', ['summary' => $this->summarize($body)]);

            // Still return 200 so Paymennt doesn't keep retrying a malformed payload
            return response()->json(['success' => true]);
        }

        // Re-fetch the canonical status from Paymennt — never trust the
        // webhook body alone. If the API call fails we silently no-op
        // (the verify endpoint will reconcile when the customer lands
        // back on the order-confirmation page).
        $paymentController = app(PaymentController::class);
        $checkoutData = $paymentController->fetchPaymenntCheckout($checkoutId);
        if ($checkoutData === null) {
            return response()->json(['success' => true]);
        }
        $verifiedStatus = strtoupper((string) ($checkoutData['status'] ?? ''));
        $usedMethod = $checkoutData['usedPaymentMethod'] ?? null;

        $paymentStatus = $paymentController->mapGatewayStatus($verifiedStatus);

        Log::info('Paymennt webhook processed', [
            'merchantOrderId' => $merchantOrderId,
            'webhookStatus' => strtoupper((string) ($payload['status'] ?? $body['status'] ?? '')),
            'verifiedStatus' => $verifiedStatus,
            'paymentStatus' => $paymentStatus,
        ]);

        if ($paymentStatus === 'pending') {
            return response()->json(['success' => true]);
        }

        $checkout = PendingCheckout::where('reference', $merchantOrderId)->first();

        if ($checkout) {
            if ($paymentStatus === 'paid') {
                // Idempotent — if verifyPaymennt already converted, this no-ops
                $paymentController->convertCheckoutToOrder($checkout, 'paymennt', null, $usedMethod);
            } else {
                $paymentController->markCheckoutFailed($checkout);
            }

            return response()->json(['success' => true]);
        }

        // No matching pending checkout — could be an already-converted order
        // re-receiving a status update (e.g. refund webhook later).
        $order = Order::where('order_number', $merchantOrderId)->first();
        if ($order) {
            $updateData = [
                'payment_status' => $paymentStatus,
                'payment_method' => 'paymennt',
            ];
            if ($paymentStatus === 'paid') {
                // Payment confirmed — admin will manually advance to 'processing' once they start preparing the order.
                $updateData['status'] = 'confirmed';
            }
            $order->update($updateData);
            $this->sendPaymentNotification($merchantOrderId, $paymentStatus);
        }

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/webhooks/tabby
     * Tabby fires this on payment lifecycle events (Authorize, Capture,
     * Close, Reject, Expire, Refund). We verify the configured static
     * header secret, then re-fetch the full payment from Tabby's API
     * (don't trust the body) and act based on status + refunds[].
     */
    public function tabby(Request $request, TabbyService $tabby)
    {
        if (! $tabby->verifyWebhook($request)) {
            Log::warning('Tabby webhook auth header missing or wrong');

            return response()->json(['success' => false, 'error' => 'invalid signature'], 401);
        }

        $body = $request->all();
        Log::info('Tabby webhook received', ['summary' => $this->summarize($body)]);

        $tabbyPaymentId = $body['id'] ?? $body['payment_id'] ?? null;
        $merchantOrderId = $body['order']['reference_id']
            ?? $body['order_id']
            ?? $body['payment']['order']['reference_id']
            ?? null;

        if (! $tabbyPaymentId || ! $merchantOrderId) {
            Log::warning('Tabby webhook missing id or reference', ['summary' => $this->summarize($body)]);

            return response()->json(['success' => true]);
        }

        $payment = $tabby->fetchPayment($tabbyPaymentId);
        if ($payment === null) {
            return response()->json(['success' => true]);
        }

        $verifiedStatus = strtoupper((string) ($payment['status'] ?? ''));
        $paymentStatus = $tabby->mapStatus($verifiedStatus);
        $refunds = $payment['refunds'] ?? [];
        $hasRefunds = is_array($refunds) && count($refunds) > 0;

        Log::info('Tabby webhook processed', [
            'merchantOrderId' => $merchantOrderId,
            'verifiedStatus' => $verifiedStatus,
            'paymentStatus' => $paymentStatus,
            'hasRefunds' => $hasRefunds,
        ]);

        if ($paymentStatus === 'pending') {
            return response()->json(['success' => true]);
        }

        $checkout = PendingCheckout::where('reference', $merchantOrderId)->first();
        if ($checkout) {
            $paymentController = app(PaymentController::class);
            if ($paymentStatus === 'paid' && ! $hasRefunds) {
                $order = $paymentController->convertCheckoutToOrder($checkout, 'tabby', $tabbyPaymentId);

                // Tabby docs: capture after AUTHORIZED. Idempotent — no-op if the
                // account auto-captures or the verify path already closed it. Never
                // let a capture error fail the webhook (the order is already placed;
                // Tabby will retry the webhook on a non-2xx response).
                try {
                    $tabby->captureAndClose($tabbyPaymentId, (float) ($order->gateway_amount ?? $order->total));
                } catch (\Throwable $e) {
                    Log::warning('Tabby capture-after-authorize failed in webhook (order already placed)', [
                        'merchantOrderId' => $merchantOrderId,
                        'paymentId' => $tabbyPaymentId,
                        'error' => $e->getMessage(),
                    ]);
                }
            } elseif ($paymentStatus === 'failed') {
                $paymentController->markCheckoutFailed($checkout);
            }

            return response()->json(['success' => true]);
        }

        $order = Order::where('order_number', $merchantOrderId)->first();
        if ($order) {
            // A Refund event arrives as status=CLOSED with a non-empty refunds[].
            // Without this branch, we'd map "closed" → "paid" and overwrite a
            // legitimately refunded order back to paid.
            if ($hasRefunds) {
                $totalRefunded = 0.0;
                foreach ($refunds as $r) {
                    $totalRefunded += (float) ($r['amount'] ?? 0);
                }
                $orderTotal = (float) $order->total;
                $update = [
                    'payment_status' => $totalRefunded >= $orderTotal - 0.01 ? 'refunded' : 'partially_refunded',
                    'payment_method' => 'tabby',
                ];
                $order->update($update);

                return response()->json(['success' => true]);
            }

            $update = ['payment_status' => $paymentStatus, 'payment_method' => 'tabby'];
            if ($paymentStatus === 'paid') {
                $update['status'] = 'confirmed';
            }
            $order->update($update);
            $this->sendPaymentNotification($merchantOrderId, $paymentStatus);
        }

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/webhooks/tamara
     * Tamara fires this on order status changes. Verify the HMAC, then
     * re-fetch canonical status from Tamara's API.
     */
    public function tamara(Request $request, TamaraService $tamara)
    {
        $rawBody = $request->getContent();
        $signature = $request->header('tamara-signature') ?? $request->header('Tamara-Signature');

        $jwt = $request->query('tamaraToken') ?: $request->bearerToken();

        if (! $tamara->verifyWebhookToken($jwt) && ! $tamara->verifyWebhookSignature($rawBody, $signature)) {
            Log::warning('Tamara webhook signature mismatch');

            return response()->json(['success' => false, 'error' => 'invalid signature'], 401);
        }

        $body = $request->all();
        Log::info('Tamara webhook received', ['summary' => $this->summarize($body)]);

        $tamaraOrderId = $body['order_id'] ?? $body['data']['order_id'] ?? null;
        $merchantOrderId = $body['order_reference_id']
            ?? $body['data']['order_reference_id']
            ?? null;

        if (! $tamaraOrderId || ! $merchantOrderId) {
            Log::warning('Tamara webhook missing order_id or reference', ['summary' => $this->summarize($body)]);

            return response()->json(['success' => true]);
        }

        $verifiedStatus = $tamara->fetchStatus($tamaraOrderId);
        if ($verifiedStatus === null) {
            return response()->json(['success' => true]);
        }
        $paymentStatus = $tamara->mapStatus($verifiedStatus);

        Log::info('Tamara webhook processed', [
            'merchantOrderId' => $merchantOrderId,
            'verifiedStatus' => $verifiedStatus,
            'paymentStatus' => $paymentStatus,
        ]);

        if ($paymentStatus === 'pending') {
            return response()->json(['success' => true]);
        }

        $checkout = PendingCheckout::where('reference', $merchantOrderId)->first();
        if ($checkout) {
            $paymentController = app(PaymentController::class);
            if ($paymentStatus === 'paid') {
                $paymentController->convertCheckoutToOrder($checkout, 'tamara', $tamaraOrderId);
            } else {
                $paymentController->markCheckoutFailed($checkout);
            }

            return response()->json(['success' => true]);
        }

        $order = Order::where('order_number', $merchantOrderId)->first();
        if ($order) {
            $update = ['payment_status' => $paymentStatus, 'payment_method' => 'tamara'];
            if ($paymentStatus === 'paid') {
                $update['status'] = 'confirmed';
            }
            $order->update($update);
            $this->sendPaymentNotification($merchantOrderId, $paymentStatus);
        }

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/webhooks/stripe
     * Verifies the Stripe-Signature header against services.stripe.webhook_secret
     * (400 on a missing secret or bad signature, so Stripe shows the failure),
     * then converts the checkout on checkout.session.completed (when paid) and
     * checkout.session.async_payment_succeeded. The event body is trusted once
     * the signature verifies. Every other event type is acknowledged with 200.
     */
    public function stripe(Request $request)
    {
        $secret = (string) config('services.stripe.webhook_secret');
        if ($secret === '') {
            Log::warning('Stripe webhook received but STRIPE_WEBHOOK_SECRET is not configured');

            return response()->json(['success' => false, 'error' => 'webhook secret not configured'], 400);
        }

        try {
            $event = \Stripe\Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $secret
            );
        } catch (\Stripe\Exception\SignatureVerificationException|\UnexpectedValueException $e) {
            Log::warning('Stripe webhook signature mismatch', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => 'invalid signature'], 400);
        }

        $handled = [
            \Stripe\Event::CHECKOUT_SESSION_COMPLETED,
            \Stripe\Event::CHECKOUT_SESSION_ASYNC_PAYMENT_SUCCEEDED,
            \Stripe\Event::CHECKOUT_SESSION_ASYNC_PAYMENT_FAILED,
        ];
        if (! in_array($event->type, $handled, true)) {
            return response()->json(['success' => true]);
        }

        $session = $event->data->object;
        $merchantOrderId = $session->client_reference_id ?? ($session->metadata->reference ?? null);

        Log::info('Stripe webhook received', [
            'type' => $event->type,
            'session' => $session->id ?? null,
            'reference' => $merchantOrderId,
            'payment_status' => $session->payment_status ?? null,
        ]);

        if (! $merchantOrderId) {
            return response()->json(['success' => true]);
        }

        $checkout = PendingCheckout::where('reference', $merchantOrderId)->first();
        if (! $checkout) {
            return response()->json(['success' => true]);
        }

        $paymentController = app(PaymentController::class);

        // The event is signed, but client_reference_id is not proof the session
        // is ours: Payment Links (and other apps on the same Stripe account) let
        // the payer set it from the URL. Only the session this app stored for the
        // checkout may act on it — a foreign session can neither pay nor fail it.
        $sessionId = $session->id ?? null;
        $isCurrentSession = $checkout->stripe_session_id && $sessionId === $checkout->stripe_session_id;

        if ($event->type === \Stripe\Event::CHECKOUT_SESSION_ASYNC_PAYMENT_FAILED) {
            // A late failure on an older, superseded session (a retried draft
            // payment) must not release the stock while the newer session is open.
            if (! $isCurrentSession) {
                Log::info('Stripe async_payment_failed for a session that is not the checkout\'s current one ignored', [
                    'reference' => $checkout->reference,
                    'session' => $sessionId,
                    'current_session' => $checkout->stripe_session_id,
                ]);

                return response()->json(['success' => true]);
            }

            $paymentController->markCheckoutFailed($checkout);

            return response()->json(['success' => true]);
        }

        if (! $isCurrentSession && ! $this->isSupersededStripeSession($session, $checkout, $paymentController)) {
            Log::warning('Stripe webhook session does not match checkout; ignored', [
                'reference' => $merchantOrderId,
                'event_session' => $sessionId,
                'stored_session' => $checkout->stripe_session_id,
                'amount_total' => $session->amount_total ?? null,
                'currency' => $session->currency ?? null,
            ]);

            return response()->json(['success' => true]); // 200 so Stripe stops retrying
        }

        $paid = in_array($session->payment_status ?? null, ['paid', 'no_payment_required'], true);
        if (! $paid) {
            // completed with payment_status=unpaid: a delayed method (bank debit)
            // is still processing — async_payment_succeeded will follow.
            return response()->json(['success' => true]);
        }

        // Idempotent — no-ops if verifyStripe() already converted it.
        $paymentController->convertCheckoutToOrder(
            $checkout->fresh(),
            'stripe',
            $paymentController->stripePaymentIntentId($session) ?? ($session->id ?? null)
        );

        return response()->json(['success' => true]);
    }

    /**
     * A session this app created for the checkout earlier, then replaced with a
     * newer one (the customer retried). If it is still paid, that is real money
     * and the order must still be created. It counts only when it carries the
     * metadata.reference this app sets (a Payment Link cannot set metadata), is
     * a real charge (not no_payment_required), and charged exactly the staged
     * amount in the staged currency.
     */
    private function isSupersededStripeSession(object $session, PendingCheckout $checkout, PaymentController $paymentController): bool
    {
        if (! $checkout->stripe_session_id || empty($session->id)) {
            return false;
        }
        if (($session->metadata->reference ?? null) !== $checkout->reference) {
            return false;
        }
        if (($session->payment_status ?? null) === 'no_payment_required') {
            return false;
        }
        if ($checkout->gateway_amount === null || ! $checkout->gateway_currency) {
            return false;
        }

        $currency = strtoupper((string) $checkout->gateway_currency);
        if (strtoupper((string) ($session->currency ?? '')) !== $currency) {
            return false;
        }

        // PaymentController::stripeMinorUnits() is private; call it in its own scope
        // so the amount is converted exactly as when the session was created.
        $expected = (fn () => $this->stripeMinorUnits((float) $checkout->gateway_amount, $currency))
            ->call($paymentController);

        return isset($session->amount_total) && (int) $session->amount_total === $expected;
    }

    /**
     * Send payment notification and status update emails to the customer.
     * Runs after the webhook response is returned so Paymennt's webhook
     * doesn't time out waiting for SMTP + PDF generation.
     */
    private function sendPaymentNotification(string $orderNumber, string $paymentStatus): void
    {
        $order = Order::with('items')->where('order_number', $orderNumber)->first();
        if (! $order || ! $order->customer_email) {
            return;
        }

        dispatch(function () use ($order, $paymentStatus) {
            try {
                Mail::to($order->customer_email)->send(new PaymentConfirmation($order, $paymentStatus));
            } catch (\Throwable $e) {
                Log::error('Failed to send payment confirmation email: '.$e->getMessage(), [
                    'order' => $order->order_number,
                ]);
            }

            if ($paymentStatus === 'paid') {
                try {
                    Mail::to($order->customer_email)->send(new OrderStatusUpdate($order, 'confirmed'));
                } catch (\Throwable $e) {
                    Log::error('Failed to send order status update email: '.$e->getMessage(), [
                        'order' => $order->order_number,
                    ]);
                }
            }
        });

        // Admin notification — separate closure so PDF-rendering memory from the
        // customer PDF generation cannot starve this one.
        if ($paymentStatus === 'paid') {
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
}
