<?php
/**
 * ============================================================
 * OrderStatusUpdate.php — Order Status Change Email
 * ============================================================
 * What this file does:
 *   Sends an email to the customer when their order status
 *   changes (e.g., from "pending" to "processing", "shipped",
 *   or "delivered"). Includes the new status and order details.
 *
 * Sent when: An admin updates the order status
 * ============================================================
 */

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderStatusUpdate extends Mailable
{
    use Queueable, SerializesModels;

    // Takes the order and the new status (e.g., "shipped") as inputs
    public function __construct(public Order $order, public string $newStatus) {}

    // Set the email subject (e.g., "Order AD20260329-1234 - Shipped")
    public function envelope(): Envelope
    {
        $statusLabel = ucfirst($this->newStatus);
        return new Envelope(
            subject: "Order {$this->order->order_number} - {$statusLabel}",
        );
    }

    // Point to the Blade template that renders the email HTML
    public function content(): Content
    {
        return new Content(
            view: 'emails.order-status-update',
        );
    }
}
