<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records the currency an order was actually charged in at the gateway.
 *
 * Every price in this app is stored in AED, but Tamara and Tabby reject a
 * KSA order unless it is priced in SAR (`not_supported_country_currency`).
 * The converted figure has to be remembered, because a later capture or
 * refund sends a bare amount whose currency is implied by the original
 * payment — sending the AED number against a SAR payment would move the
 * wrong amount of money.
 *
 * Null on an existing row means AED, which is what every historical order
 * was charged in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_checkouts', function (Blueprint $table) {
            $table->string('gateway_currency', 3)->nullable()->after('total');
            $table->decimal('gateway_amount', 10, 2)->nullable()->after('gateway_currency');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('gateway_currency', 3)->nullable()->after('total');
            $table->decimal('gateway_amount', 10, 2)->nullable()->after('gateway_currency');
        });
    }

    public function down(): void
    {
        Schema::table('pending_checkouts', function (Blueprint $table) {
            $table->dropColumn(['gateway_currency', 'gateway_amount']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['gateway_currency', 'gateway_amount']);
        });
    }
};
