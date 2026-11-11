<?php

/**
 * ============================================================
 * Migration: Add Two-Factor Authentication Columns to Users
 * ============================================================
 * What this file does:
 *   Adds the columns needed for optional TOTP (authenticator-app)
 *   two-factor authentication, used by admin accounts:
 *   - two_factor_secret: the shared TOTP secret (encrypted)
 *   - two_factor_recovery_codes: one-time recovery codes (encrypted)
 *   - two_factor_confirmed_at: set once the admin verifies a code,
 *     which is what actually switches 2FA on for that account.
 *
 * Column names mirror Laravel Fortify's convention.
 * ============================================================
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
