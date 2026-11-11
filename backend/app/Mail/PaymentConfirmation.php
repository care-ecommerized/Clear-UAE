<?php
/**
 * ============================================================
 * PaymentConfirmation.php — Payment Status Email
 * ============================================================
 * What this file does:
 *   Sends an email to the customer about their payment status.
 *   If payment succeeded, it confirms the payment. If payment
 *   failed, it asks them to try again. Handles both cases
 *   in one class.
 *
 * Sent when: A payment gateway confirms or rejects a payment
 * ============================================================
 */

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    // Takes the order and payment result ("paid" or "failed")
    public function __construct(public Order $order, public string $paymentStatus) {}

    // Set different subject lines depending on whether payment passed or failed
    public function envelope(): Envelope
    {
        $subject = $this->paymentStatus === 'paid'
            ? "Payment Confirmed - {$this->order->order_number}"
            : "Payment Failed - {$this->order->order_number}";

        return new Envelope(subject: $subject);
    }

    // Point to the Blade template that renders the email HTML
    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-confirmation',
        );
    }
}
