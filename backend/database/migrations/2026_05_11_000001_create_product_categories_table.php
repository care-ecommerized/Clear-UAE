<?php

// Migration: Create product_categories pivot table for product↔category many-to-many.
// Allows a single product to belong to multiple buckets (e.g. "Gold toned" + "Branded").
// Coexists with products.category_id (kept as the "primary" category for display purposes).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->uuid('product_id');
            $table->uuid('category_id');
            $table->primary(['product_id', 'category_id']);
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_categories');
    }
};
