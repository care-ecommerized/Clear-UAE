<?php

// Tracks the gateway-side checkout/payment ID for Tabby and Tamara,
// mirroring the existing paymennt_checkout_id column. Lets us resume a
// session if the customer hits the pay button twice without creating a
// duplicate at the gateway, and gives the verify/webhook handlers a
// stable lookup key.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_checkouts', function (Blueprint $table) {
            $table->string('tabby_payment_id')->nullable()->index()->after('paymennt_redirect_url');
            $table->string('tamara_order_id')->nullable()->index()->after('tabby_payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('pending_checkouts', function (Blueprint $table) {
            $table->dropIndex(['tabby_payment_id']);
            $table->dropIndex(['tamara_order_id']);
            $table->dropColumn(['tabby_payment_id', 'tamara_order_id']);
        });
    }
};
