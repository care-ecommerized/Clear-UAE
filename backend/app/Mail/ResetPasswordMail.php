<?php

/**
 * ============================================================
 * ResetPasswordMail.php — Password Reset Link
 * ============================================================
 * Sent when a customer requests a password reset from the
 * /forgot-password page. Contains a link to the SPA's
 * /reset-password page carrying the reset token + email.
 * The token is validated against the password_reset_tokens
 * table by AuthController@resetPassword.
 *
 * Queued (ShouldQueue) — see the note on VerifyEmail.
 * ============================================================
 */

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public ?string $customerName, public string $resetUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset your password — '.\App\Support\Brand::name(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reset-password',
            with: [
                'customerName' => $this->customerName,
                'resetUrl' => $this->resetUrl,
            ],
        );
    }
}
