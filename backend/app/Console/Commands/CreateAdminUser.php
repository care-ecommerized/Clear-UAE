<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;

/**
 * php artisan admin:create
 *
 * Interactive prompt to create or upgrade a user to admin. Used during
 * production deploy when the database is fresh and there's no admin user
 * yet (the SPA's admin guard would otherwise lock everyone out).
 *
 * Examples:
 *   php artisan admin:create
 *   php artisan admin:create --email=admin@example.com --name="Ops" --password=secret
 */
class CreateAdminUser extends Command
{
    protected $signature = 'admin:create
        {--email= : Email of the user to make an admin}
        {--name= : Name to set if creating a new user}
        {--password= : Password to set if creating a new user}';

    protected $description = 'Create a new admin user, or grant the admin role to an existing user.';

    public function handle(): int
    {
        $email = $this->option('email') ?: $this->ask('Admin email');
        $email = strtolower(trim((string) $email));

        try {
            Validator::validate(['email' => $email], ['email' => 'required|email']);
        } catch (ValidationException $e) {
            $this->error('Invalid email address.');

            return self::INVALID;
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            $name = $this->option('name') ?: $this->ask('Full name');
            $password = $this->option('password') ?: $this->secret('Password (min 8 characters)');

            try {
                Validator::validate(
                    ['name' => $name, 'password' => $password],
                    ['name' => 'required|string|max:255', 'password' => 'required|string|min:8']
                );
            } catch (ValidationException $e) {
                $this->error('Name is required and password must be at least 8 characters.');

                return self::INVALID;
            }

            $user = User::create([
                'email' => $email,
                'name' => $name,
                'password' => Hash::make($password),
            ]);

            $this->info("User created: {$user->email}");
        } else {
            $this->info("User exists: {$user->email}");
        }

        if ($user->isAdmin()) {
            $this->warn('User already has the admin role — nothing to do.');

            return self::SUCCESS;
        }

        UserRole::create(['user_id' => $user->id, 'role' => 'admin']);

        $this->info("Admin role granted to {$user->email}.");

        return self::SUCCESS;
    }
}
