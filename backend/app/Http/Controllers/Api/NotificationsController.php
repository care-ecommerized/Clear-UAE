<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PendingCheckout;
use App\Support\Brand;
use Illuminate\Http\JsonResponse;

class NotificationsController extends Controller
{
    /**
     * GET /api/admin/notifications/preview/{type}
     *
     * Renders the email HTML for the given notification key without sending
     * anything. Used by the admin Notifications settings tab so admins can
     * preview exactly what each email looks like.
     */
    public function preview(string $type): JsonResponse
    {
        $html = match ($type) {
            'notify_order_confirmation_enabled'  => $this->renderOrderConfirmation(),
            'notify_new_order_admin_enabled'     => $this->renderNewOrderAdmin(),
            'notify_order_status_update_enabled' => $this->renderOrderStatusUpdate(),
            'notify_order_cancelled_enabled'     => $this->renderOrderCancellation(),
            'notify_order_refund_enabled'        => $this->renderOrderRefund(),
            'notify_abandoned_checkout_enabled'  => $this->renderAbandonedCheckout(),
            'notify_password_reset_enabled'      => $this->renderPasswordReset(),
            'notify_email_verification_enabled'  => $this->renderVerifyEmail(),
            default                              => null,
        };

        if ($html === null) {
            return response()->json(['error' => 'Unknown notification type'], 404);
        }

        return response()->json(['html' => $html]);
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /** Build an unsaved dummy Order with one item for preview rendering. */
    private function makeDummyOrder(): Order
    {
        $order = new Order;
        $order->order_number     = Brand::orderPrefix().'1001';
        $order->customer_name    = 'Sarah Al-Rashidi';
        $order->customer_email   = 'preview@example.com';
        $order->customer_phone   = '+971 50 123 4567';
        $order->payment_method   = 'paymennt';
        $order->payment_brand    = 'Card';
        $order->payment_status   = 'paid';
        $order->status           = 'processing';
        $order->subtotal         = 1850.00;
        $order->discount         = 0.00;
        $order->shipping_cost    = 0.00;
        $order->total            = 1850.00;
        $order->shipping_address = '123 Main Street, Downtown';
        $order->shipping_city    = Brand::get('store_city_en', 'City Centre');
        $order->shipping_country = 'UAE';
        $order->tracking_number  = 'TRK-987654321';
        $order->shipping_provider = 'Topex';
        $order->created_at       = now();

        $item                  = new OrderItem;
        $item->product_name    = 'Rolex Submariner Date — Blue Dial';
        $item->quantity        = 1;
        $item->unit_price      = 1850.00;
        $item->total_price     = 1850.00;
        $item->product_image   = null;

        $order->setRelation('items', collect([$item]));

        return $order;
    }

    /** Build an unsaved dummy PendingCheckout for the abandoned checkout preview. */
    private function makeDummyCheckout(): PendingCheckout
    {
        $checkout                   = new PendingCheckout;
        $checkout->reference        = 'CHK-PREVIEW1';
        $checkout->customer_name    = 'Sarah Al-Rashidi';
        $checkout->customer_email   = 'preview@example.com';
        $checkout->subtotal         = 1850.00;
        $checkout->discount         = 0.00;
        $checkout->shipping_cost    = 0.00;
        $checkout->total            = 1850.00;
        $checkout->items            = [
            [
                'product_name'  => 'Rolex Submariner Date — Blue Dial',
                'quantity'      => 1,
                'unit_price'    => 1850.00,
                'total_price'   => 1850.00,
                'product_image' => null,
            ],
        ];

        return $checkout;
    }

    private function renderOrderConfirmation(): string
    {
        return (new \App\Mail\OrderConfirmation($this->makeDummyOrder()))->render();
    }

    private function renderNewOrderAdmin(): string
    {
        return (new \App\Mail\NewOrderAdminNotification($this->makeDummyOrder()))->render();
    }

    private function renderOrderStatusUpdate(): string
    {
        $order         = $this->makeDummyOrder();
        $order->status = 'shipped';

        return (new \App\Mail\OrderStatusUpdate($order, 'shipped'))->render();
    }

    private function renderOrderCancellation(): string
    {
        $order         = $this->makeDummyOrder();
        $order->status = 'cancelled';

        return (new \App\Mail\OrderCancellation($order))->render();
    }

    private function renderOrderRefund(): string
    {
        $order                 = $this->makeDummyOrder();
        $order->payment_status = 'refunded';

        return (new \App\Mail\OrderRefund($order))->render();
    }

    private function renderAbandonedCheckout(): string
    {
        return (new \App\Mail\AbandonedCheckoutRecovery($this->makeDummyCheckout()))->render();
    }

    private function renderPasswordReset(): string
    {
        return (new \App\Mail\ResetPasswordMail(
            'Sarah Al-Rashidi',
            'https://example.com/reset-password?token=PREVIEW&email=preview%40example.com'
        ))->render();
    }

    private function renderVerifyEmail(): string
    {
        return (new \App\Mail\VerifyEmail(
            'Sarah Al-Rashidi',
            'https://example.com/verify-email?PREVIEW'
        ))->render();
    }
}
