<?php
/**
 * ============================================================
 * OrderCancellation.php — Order Cancellation Email
 * ============================================================
 * What this file does:
 *   Sends an email to the customer when their order is
 *   cancelled. Includes the order details and refund info
 *   if the payment was already processed.
 *
 * Sent when: An order is cancelled by admin or system
 * ============================================================
 */

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderCancellation extends Mailable
{
    use Queueable, SerializesModels;

    // The cancelled order data is passed in here
    public function __construct(public Order $order) {}

    // Set the email subject line
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Order Cancelled - {$this->order->order_number}",
        );
    }

    // Point to the Blade template that renders the email HTML
    public function content(): Content
    {
        return new Content(
            view: 'emails.order-cancellation',
        );
    }
}
