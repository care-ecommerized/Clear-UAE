<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gift message card — an optional add-on selected on the product page.
 * `gift_fee` is the (admin-configurable) charge added once per order;
 * `gift_message` is the note printed on the card. Stored on both the
 * pending checkout and the final order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('gift_fee', 10, 2)->default(0)->after('shipping_cost');
            $table->text('gift_message')->nullable()->after('gift_fee');
        });

        Schema::table('pending_checkouts', function (Blueprint $table) {
            $table->decimal('gift_fee', 10, 2)->default(0)->after('shipping_cost');
            $table->text('gift_message')->nullable()->after('gift_fee');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['gift_fee', 'gift_message']);
        });

        Schema::table('pending_checkouts', function (Blueprint $table) {
            $table->dropColumn(['gift_fee', 'gift_message']);
        });
    }
};
