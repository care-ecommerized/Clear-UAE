<?php
// Stages a checkout while the customer is at the payment gateway, before
// any Order row exists. Mirrors Shopify's two-stage Checkout → Order model.
//
// Lifecycle:
//   pending     — customer is at Paymennt's hosted page
//   converted   — payment succeeded, an Order row was created from this
//   abandoned   — customer never paid; stock released; row kept for stats
//   failed      — Paymennt reported FAILED/CANCELLED/EXPIRED; stock released

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_checkouts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->string('reference')->unique();          // our own id, sent to Paymennt as orderId
            $table->string('paymennt_checkout_id')->nullable()->index();
            $table->string('paymennt_redirect_url', 1024)->nullable();
            $table->string('status')->default('pending')->index(); // pending|converted|abandoned|failed
            $table->string('order_number')->nullable()->index();    // populated when converted
            $table->string('customer_email');
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->text('shipping_address')->nullable();
            $table->string('shipping_city')->nullable();
            $table->string('shipping_state')->nullable();
            $table->string('shipping_country')->default('UAE');
            $table->string('shipping_zip')->nullable();
            $table->decimal('subtotal', 10, 2);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('shipping_cost', 10, 2)->default(0);
            $table->decimal('total', 10, 2);
            $table->string('discount_id')->nullable();      // for usage increment on conversion
            $table->json('items');                          // [{product_id, product_name, quantity, price}, ...]
            $table->text('notes')->nullable();
            $table->timestamp('expires_at')->nullable()->index(); // reservation window
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_checkouts');
    }
};
