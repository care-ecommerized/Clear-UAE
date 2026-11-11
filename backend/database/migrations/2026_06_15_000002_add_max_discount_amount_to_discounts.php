<?php

// Migration: add an optional maximum discount cap to discounts.
// Mainly for percentage discounts — e.g. "20% off, up to AED 100 max".
// Null = no cap.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            $table->decimal('max_discount_amount', 10, 2)->nullable()->after('value');
        });
    }

    public function down(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            $table->dropColumn('max_discount_amount');
        });
    }
};
