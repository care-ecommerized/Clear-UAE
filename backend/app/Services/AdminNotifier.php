<?php

/**
 * ============================================================
 * AdminNotifier.php — Bell Notification Helper
 * ============================================================
 * Tiny convenience wrapper around AdminNotification::create().
 * Call from controllers / commands when an event happens that
 * admins should see in the bell dropdown.
 *
 * Usage:
 *   AdminNotifier::orderPlaced($order);
 *   AdminNotifier::orderPaid($order);
 *   AdminNotifier::orderCancelled($order);
 *   AdminNotifier::checkoutAbandoned($checkout);
 *   AdminNotifier::adminEmails();   // admin email recipients
 * ============================================================
 */

namespace App\Services;

use App\Models\AdminNotification;
use App\Models\ContactMessage;
use App\Models\Order;
use App\Models\PendingCheckout;
use App\Support\Brand;

class AdminNotifier
{
    public static function orderPlaced(Order $order): void
    {
        self::create(
            type: 'order.placed',
            title: "New order {$order->order_number}",
            message: "{$order->customer_name} — ".self::formatMoney($order->total),
            link: "/admin/orders?order={$order->order_number}",
            data: ['order_id' => $order->id, 'order_number' => $order->order_number],
        );
    }

    public static function orderPaid(Order $order): void
    {
        self::create(
            type: 'order.paid',
            title: "Payment received for {$order->order_number}",
            message: "{$order->customer_name} paid ".self::formatMoney($order->total),
            link: "/admin/orders?order={$order->order_number}",
            data: ['order_id' => $order->id, 'order_number' => $order->order_number],
        );
    }

    public static function orderCashOnDelivery(Order $order): void
    {
        self::create(
            type: 'order.paid',
            title: "New Cash on Delivery order {$order->order_number}",
            message: "{$order->customer_name} will pay ".self::formatMoney($order->total).' on delivery',
            link: "/admin/orders?order={$order->order_number}",
            data: ['order_id' => $order->id, 'order_number' => $order->order_number],
        );
    }

    public static function orderCancelled(Order $order): void
    {
        self::create(
            type: 'order.cancelled',
            title: "Order {$order->order_number} cancelled",
            message: "{$order->customer_name} — ".self::formatMoney($order->total),
            link: "/admin/orders?order={$order->order_number}",
            data: ['order_id' => $order->id, 'order_number' => $order->order_number],
        );
    }

    public static function checkoutAbandoned(PendingCheckout $checkout): void
    {
        self::create(
            type: 'checkout.abandoned',
            title: "Checkout abandoned ({$checkout->reference})",
            message: ($checkout->customer_name ?: $checkout->customer_email).' — '.self::formatMoney($checkout->total),
            link: '/admin/abandoned-checkouts',
            data: ['checkout_id' => $checkout->id, 'reference' => $checkout->reference],
        );
    }

    /**
     * A customer asked about a specific product from its product page.
     *
     * Worth a bell on its own rather than folding into the generic contact
     * inbox: these are buying-intent leads on single-piece stock, and the
     * admin notification email can be missed or filtered.
     */
    public static function productInquiry(ContactMessage $msg): void
    {
        $product = $msg->product_name ?: 'a product';

        self::create(
            type: 'inquiry.product',
            title: "Product inquiry: {$product}",
            message: "{$msg->name} <{$msg->email}>",
            link: '/admin/contact-messages',
            data: [
                'contact_message_id' => $msg->id,
                'product_id' => $msg->product_id,
                'product_name' => $msg->product_name,
            ],
        );
    }

    /**
     * Bell notification when a customer-facing email could not be delivered
     * (SMTP rejected, bad address, etc.) so admin can follow up manually.
     */
    public static function customerEmailFailed(Order $order, string $emailType, string $reason): void
    {
        self::create(
            type: 'email.failed',
            title: "Email to {$order->customer_email} failed",
            message: "{$emailType} for {$order->order_number}: {$reason}",
            link: "/admin/orders?order={$order->order_number}",
            data: [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'email_type' => $emailType,
                'reason' => $reason,
            ],
        );
    }

    /**
     * Recipients for admin notification emails: ADMIN_NOTIFICATION_EMAILS
     * (services.admin_emails) when set, otherwise the store email from the
     * Brand settings. Empty only when neither is configured.
     *
     * @return list<string>
     */
    public static function adminEmails(): array
    {
        $emails = (array) config('services.admin_emails', []);
        if ($emails === []) {
            $emails = [Brand::email()];
        }

        return array_values(array_filter(array_map('trim', $emails)));
    }

    private static function create(string $type, string $title, ?string $message, ?string $link, array $data = []): void
    {
        try {
            AdminNotification::create([
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'link' => $link,
                'data' => $data,
                'read_by' => [],
            ]);
        } catch (\Throwable $e) {
            // Notifications are best-effort — never break the parent flow if the table is missing or write fails.
            report($e);
        }
    }

    private static function formatMoney(float $amount): string
    {
        return Brand::currency().' '.number_format($amount, 2);
    }
}
