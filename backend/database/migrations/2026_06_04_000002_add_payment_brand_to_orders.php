<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * payment_brand — the human-friendly instrument the customer paid with
 * ('Apple Pay', 'Google Pay', 'Card', 'Tabby', 'Tamara'). Derived from
 * Paymennt's `usedPaymentMethod` for card/wallet payments, or the BNPL
 * gateway name. Shown in the order timeline and details.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_brand')->nullable()->after('payment_reference');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('payment_brand');
        });
    }
};
