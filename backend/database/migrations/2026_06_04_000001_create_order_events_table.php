<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * order_events — a per-order activity timeline (Shopify-style).
 * Each row is one immutable event: placed, payment, status change,
 * refund, shipping/tracking, an email being sent, or a staff comment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->string('type', 40);          // placed|payment|status|refund|shipping|email|comment
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('actor')->nullable(); // 'System', 'Customer', or an admin's name
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
    }
};
