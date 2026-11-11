<?php
// Migration: Creates additional tables — profiles, user_roles, wishlist, discounts, shipping_rates, delivery_types, site_settings

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Profiles
        Schema::create('profiles', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->foreignId('user_id')->unique()->constrained()->onDelete('cascade');
            $table->string('email')->nullable();
            $table->string('full_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('avatar_url')->nullable();
            $table->timestamps();
        });

        // User roles
        Schema::create('user_roles', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('role', ['admin', 'moderator', 'user'])->default('user');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'role']);
        });

        // Wishlist
        Schema::create('wishlist', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->uuid('product_id');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->unique(['user_id', 'product_id']);
        });

        // Discounts
        Schema::create('discounts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->string('code')->unique();
            $table->string('type'); // percentage or fixed
            $table->decimal('value', 10, 2)->default(0);
            $table->decimal('min_order_amount', 10, 2)->default(0);
            $table->integer('max_uses')->nullable();
            $table->integer('used_count')->default(0);
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Shipping rates
        Schema::create('shipping_rates', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->string('origin_country')->default('AE');
            $table->string('destination_country');
            $table->string('delivery_type');
            $table->decimal('cost', 10, 2);
            $table->string('currency')->default('AED');
            $table->decimal('min_weight', 8, 2)->default(0);
            $table->decimal('max_weight', 8, 2)->default(999);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Delivery types
        Schema::create('delivery_types', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->string('name');
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('estimated_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Site settings
        Schema::create('site_settings', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
        Schema::dropIfExists('delivery_types');
        Schema::dropIfExists('shipping_rates');
        Schema::dropIfExists('discounts');
        Schema::dropIfExists('wishlist');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('profiles');
    }
};
