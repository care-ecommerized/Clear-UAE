<?php

/**
 * ============================================================
 * DraftOrderInvitation.php — "Your order is ready to pay" email
 * ============================================================
 * Sent to a customer when an admin creates a draft order for them.
 * Lists the items the admin selected and links to /pay/{reference},
 * where the customer enters a shipping address and pays. On payment
 * the draft converts into a real Order.
 *
 * Sent when:
 *   - An admin clicks "Create Draft Order" (CreateDraftOrderDialog).
 *   - An admin clicks "Resend link" on a row in /admin/draft-orders.
 * ============================================================
 */

namespace App\Mail;

use App\Models\PendingCheckout;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DraftOrderInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public PendingCheckout $checkout) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your '.\App\Support\Brand::name().' order is ready — complete your payment',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.draft-order-invitation',
            with: [
                'checkout' => $this->checkout,
                'payUrl' => rtrim(config('app.url'), '/').'/pay/'.$this->checkout->reference,
            ],
        );
    }
}
