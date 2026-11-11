<?php

// Adds a `published_at` timestamp so admins can schedule a product to
// go live at a future moment (typically the Saturday "drop"). Public
// product queries filter by (published_at IS NULL OR published_at <= NOW()),
// which means scheduled products auto-flip to live when their time
// arrives — no cron required.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('is_featured');
            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['published_at']);
            $table->dropColumn('published_at');
        });
    }
};
