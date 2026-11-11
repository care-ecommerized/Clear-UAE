<?php
/**
 * ============================================================
 * OrderConfirmation.php — Order Confirmation Email
 * ============================================================
 * What this file does:
 *   Builds and sends a confirmation email to the customer
 *   after they place an order. Shows order number, items,
 *   totals, and shipping address. Attaches a PDF tax invoice
 *   (with TRN) so the customer has an official record.
 *
 * Sent when: A new order is successfully created
 * ============================================================
 */

namespace App\Mail;

use App\Models\Order;
use App\Services\InvoiceGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    // The order data is passed in and made available to the email template
    public function __construct(public Order $order) {}

    // Set the email subject line (e.g., "Order Confirmation - AD20260329-1234")
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Order Confirmation - ' . $this->order->order_number,
        );
    }

    // Point to the Blade template that renders the email HTML
    public function content(): Content
    {
        return new Content(
            view: 'emails.order-confirmation',
        );
    }

    // Attach a generated PDF tax invoice to the email
    public function attachments(): array
    {
        $generator = new InvoiceGenerator();

        return [
            Attachment::fromData(
                fn () => $generator->generate($this->order),
                $generator->filename($this->order),
            )->withMime('application/pdf'),
        ];
    }
}
