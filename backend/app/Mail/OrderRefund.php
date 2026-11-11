<?php
/**
 * ============================================================
 * OrderRefund.php — Refund Processed Email
 * ============================================================
 * What this file does:
 *   Sends an email to the customer when a refund has been
 *   processed for their order. Includes the refund amount
 *   and estimated timeline for the money to appear.
 *
 * Sent when: A refund is processed for a cancelled/returned order
 * ============================================================
 */

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderRefund extends Mailable
{
    use Queueable, SerializesModels;

    // The refunded order data is passed in here
    public function __construct(public Order $order) {}

    // Set the email subject line
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Refund Processed - {$this->order->order_number}",
        );
    }

    // Point to the Blade template that renders the email HTML
    public function content(): Content
    {
        return new Content(
            view: 'emails.order-refund',
        );
    }
}
