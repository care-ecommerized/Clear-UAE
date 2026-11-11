<?php

/**
 * ============================================================
 * NewOrderAdminNotification.php — Admin New Order Alert
 * ============================================================
 * What this file does:
 *   Sends an email to the admin (AdminNotifier::adminEmails(): ADMIN_NOTIFICATION_EMAILS, else the store email)
 *   every time a customer places a new order. Shows order
 *   number, customer info, items, and total amount.
 *
 * Sent when: A new order is successfully created
 * ============================================================
 */

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewOrderAdminNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Order Received - '.$this->order->order_number.' ('.($this->order->currency ?: \App\Support\Brand::currency()).' '.number_format($this->order->total, 2).')',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.new-order-admin',
        );
    }

    // No PDF attachment: rendering a PDF in the same worker as the customer-confirmation
    // PDF was exhausting memory and silently dropping this email. Admins can
    // download the invoice from /admin/orders.
}
