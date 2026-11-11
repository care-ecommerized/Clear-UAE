<?php

/**
 * "Price on Request" — a watch that is not sold from the site.
 *
 * Some vintage pieces should not carry a public price; the customer has to ask.
 * Such a product hides its price everywhere, drops every add-to-cart path, and
 * offers the enquiry form / WhatsApp button instead.
 *
 * `price` is deliberately left NOT NULL and keeps its value. The flag — not a
 * null price — is the single source of truth for "enquiry only", which means
 * toggling the flag back is lossless and no `Number(product.price)` consumer
 * anywhere in the frontend has to learn about nulls.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('inquiry_only')->default(false)->after('is_featured');
            $table->index('inquiry_only');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['inquiry_only']);
            $table->dropColumn('inquiry_only');
        });
    }
};
