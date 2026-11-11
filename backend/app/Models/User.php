<?php

/**
 * ============================================================
 * User.php — User Accounts
 * ============================================================
 * What this file does:
 *   Represents a registered user in the system. Stores their
 *   name, email, and password. Users can have profiles, roles,
 *   orders, and wishlisted products.
 *
 * Database table: users
 * ============================================================
 */

namespace App\Models;

use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, MustVerifyEmailTrait, Notifiable;

    /**
     * Send the email-verification link. Overrides the framework default so we
     * deliver our own branded Mailable (queued) pointing at a signed backend
     * route that, once clicked, marks the address verified and bounces the
     * user back to the storefront.
     */
    public function sendEmailVerificationNotification(): void
    {
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addHours(48),
            ['id' => $this->getKey(), 'hash' => sha1($this->getEmailForVerification())]
        );

        Mail::to($this->email)->send(new \App\Mail\VerifyEmail($this->name, $url));
    }

    /**
     * Send the password-reset link. Overrides the framework default so the link
     * points at the SPA's /reset-password page (carrying the token + email)
     * instead of a backend Blade form.
     */
    public function sendPasswordResetNotification($token): void
    {
        $url = rtrim(config('app.url'), '/')
            .'/reset-password?token='.$token.'&email='.urlencode($this->email);

        Mail::to($this->email)->send(new \App\Mail\ResetPasswordMail($this->name, $url));
    }

    // Fields that can be filled when creating/updating a user
    protected $fillable = ['name', 'email', 'password'];

    // Fields hidden from JSON responses (never sent to frontend)
    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    // Tell Laravel how to convert certain fields (auto-hash passwords, parse dates,
    // and transparently encrypt the 2FA secret + recovery codes at rest)
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    // True only once the admin has scanned the QR and confirmed a code —
    // a stored-but-unconfirmed secret does NOT count as enabled.
    public function hasTwoFactorEnabled(): bool
    {
        return ! is_null($this->two_factor_secret) && ! is_null($this->two_factor_confirmed_at);
    }

    // A user has one profile with their personal details
    public function profile()
    {
        return $this->hasOne(Profile::class);
    }

    // A user can have multiple roles (e.g., "admin", "user")
    public function roles()
    {
        return $this->hasMany(UserRole::class);
    }

    // Check if this user has the "admin" role
    public function isAdmin(): bool
    {
        return $this->roles()->where('role', 'admin')->exists();
    }

    // A user can have many orders
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    // A user can have many wishlisted products
    public function wishlist()
    {
        return $this->hasMany(Wishlist::class);
    }
}
