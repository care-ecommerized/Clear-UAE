<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * php artisan admin:disable-2fa {email}
 *
 * Recovery hatch: turns OFF two-factor authentication for an account that has
 * locked itself out (lost phone AND lost recovery codes). Run this on the
 * server, then the admin logs in with just email + password and can re-enable
 * 2FA from Settings.
 *
 * Example:
 *   php artisan admin:disable-2fa admin@example.com
 */
class DisableTwoFactor extends Command
{
    protected $signature = 'admin:disable-2fa {email : Email of the account to reset}';

    protected $description = 'Disable two-factor authentication for a locked-out account.';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with email: {$email}");

            return self::FAILURE;
        }

        if (! $user->hasTwoFactorEnabled() && is_null($user->two_factor_secret)) {
            $this->warn('Two-factor authentication is not set up on this account — nothing to do.');

            return self::SUCCESS;
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->info("Two-factor authentication disabled for {$user->email}.");
        $this->line('They can now sign in with just email + password, then re-enable 2FA from Settings.');

        return self::SUCCESS;
    }
}
