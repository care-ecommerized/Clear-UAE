<?php

/**
 * Migration: remember which shipping option the customer chose.
 *
 * `shipping_method` holds the delivery type (shipping_rates.delivery_type /
 * delivery_types.name, e.g. "standard", "international_special"), so order
 * emails can show the option's label and estimated days ("Shipping
 * (2-3 Days)"). Nullable: older orders have none and fall back to the
 * destination's default.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['pending_checkouts', 'orders'] as $table) {
            if (! Schema::hasColumn($table, 'shipping_method')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->string('shipping_method', 100)->nullable()->after('shipping_cost');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['pending_checkouts', 'orders'] as $table) {
            if (Schema::hasColumn($table, 'shipping_method')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('shipping_method'));
            }
        }
    }
};
