<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the "exclude from shop" flag to categories.
 *
 * A flagged category keeps its own page and stays in the header menu, but its
 * products are kept out of the general catalogue — All Products, search,
 * featured/new arrivals, related items and the sitemap. It is deliberately
 * separate from `is_active`, which only controls whether the category card is
 * listed at all: overloading that would have silently hidden the products of
 * every already-inactive category the moment this shipped.
 *
 * Indexed because it is read on nearly every customer-facing product query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('exclude_from_shop')->default(false)->after('is_active');
            $table->index('exclude_from_shop');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex(['exclude_from_shop']);
            $table->dropColumn('exclude_from_shop');
        });
    }
};
