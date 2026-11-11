<?php
// Migration: Creates the "products" table — the main table for all watch products in the store

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('name_ar')->nullable();
            $table->text('description')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('short_description')->nullable();
            $table->text('short_description_ar')->nullable();
            $table->decimal('price', 10, 2);
            $table->decimal('original_price', 10, 2)->nullable();
            $table->string('sku')->nullable();
            $table->string('image_url')->nullable();
            $table->json('images')->nullable()->default('[]');
            $table->string('video_url')->nullable();
            $table->string('badge')->nullable();
            $table->string('size')->nullable();
            $table->text('notes_top')->nullable();
            $table->text('notes_middle')->nullable();
            $table->text('notes_base')->nullable();
            $table->integer('stock_quantity')->default(0);
            $table->uuid('category_id')->nullable();
            $table->uuid('brand_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('category_id')->references('id')->on('categories')->onDelete('set null');
            $table->foreign('brand_id')->references('id')->on('brands')->onDelete('set null');
            $table->index(['is_active', 'is_featured']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
