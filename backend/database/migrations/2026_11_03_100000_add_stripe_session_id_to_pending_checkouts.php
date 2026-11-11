<?php

// Tracks the Stripe Checkout Session id for a staged checkout, mirroring
// paymennt_checkout_id / tabby_payment_id / tamara_order_id. Lets a second
// Pay click reuse the still-open session instead of creating a duplicate,
// and gives verifyStripe() a stable lookup key.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('pending_checkouts', 'stripe_session_id')) {
            return;
        }

        Schema::table('pending_checkouts', function (Blueprint $table) {
            $table->string('stripe_session_id')->nullable()->index()->after('tamara_order_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('pending_checkouts', 'stripe_session_id')) {
            return;
        }

        Schema::table('pending_checkouts', function (Blueprint $table) {
            $table->dropIndex(['stripe_session_id']);
            $table->dropColumn('stripe_session_id');
        });
    }
};
