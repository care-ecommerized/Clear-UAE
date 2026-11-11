<?php

/**
 * ============================================================
 * AbandonedCheckoutRecovery.php — Recovery Reminder Email
 * ============================================================
 * Sent to a customer who started a checkout but didn't pay.
 * Lists the items still waiting in their cart and links them
 * to a recovery URL that re-hydrates the cart and drops them
 * straight back at /checkout.
 *
 * Sent when:
 *   - An admin clicks "Send recovery email" on a row in
 *     /admin/abandoned-checkouts.
 *   - (Future) The scheduled cron fires the 1h / 24h / 3d
 *     reminder sequence.
 * ============================================================
 */

namespace App\Mail;

use App\Models\PendingCheckout;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AbandonedCheckoutRecovery extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public PendingCheckout $checkout) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Complete your '.\App\Support\Brand::name().' order',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.abandoned-checkout-recovery',
            with: [
                'checkout' => $this->checkout,
                'recoveryUrl' => rtrim(config('app.url'), '/').'/recover-checkout/'.$this->checkout->reference,
            ],
        );
    }
}
