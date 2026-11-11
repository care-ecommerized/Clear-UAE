<?php

/**
 * ============================================================
 * VerifyEmail.php — Email Address Verification
 * ============================================================
 * Sent on signup (and on demand via "Resend"). Contains a
 * signed, time-limited link to the backend verification route.
 * Clicking it marks the address verified and redirects the
 * customer back to the storefront.
 *
 * Queued (ShouldQueue) like every other customer email — the
 * scheduler's queue:work drains it. Do NOT send synchronously:
 * SMTP is slow and after-response callbacks don't fire on the
 * production LiteSpeed server.
 * ============================================================
 */

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerifyEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public ?string $customerName, public string $verifyUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Verify your email — '.\App\Support\Brand::name(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verify-email',
            with: [
                'customerName' => $this->customerName,
                'verifyUrl' => $this->verifyUrl,
            ],
        );
    }
}
