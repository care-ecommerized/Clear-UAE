<?php

/**
 * Restore the Paymennt id columns on pending_checkouts where they are missing.
 *
 * Environment drift repair: a since-deleted migration
 * (2026_06_22_000001_drop_paymennt_columns_from_pending_checkouts, from a
 * branch that no longer exists) dropped these columns on the staging DB.
 * Production never ran it and still has them, so the two environments
 * disagree on the shape of this table — `paymennt:reconcile-pending` and the
 * whole Paymennt checkout flow crash on staging with "Unknown column
 * 'paymennt_checkout_id'" while working fine on production.
 *
 * Every change here is guarded by hasColumn/hasIndex, so this is a no-op on
 * production (and on any environment that already matches) and only repairs
 * the ones that drifted.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_checkouts', function (Blueprint $table) {
            if (! Schema::hasColumn('pending_checkouts', 'paymennt_checkout_id')) {
                $table->string('paymennt_checkout_id')->nullable()->index();
            }

            if (! Schema::hasColumn('pending_checkouts', 'paymennt_redirect_url')) {
                $table->string('paymennt_redirect_url', 1024)->nullable();
            }
        });
    }

    public function down(): void
    {
        // Intentionally not dropping these. This migration exists to repair
        // drift, and production has held real Paymennt checkout ids in these
        // columns since April — a rollback must not destroy the only link
        // between a pending checkout and the payment that was taken for it.
    }
};
