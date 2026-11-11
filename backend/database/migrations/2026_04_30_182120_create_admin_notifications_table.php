<?php

// Real-time notifications surfaced in the admin bell dropdown.
// One row per event (order placed, paid, cancelled, checkout abandoned).
// Read state is tracked per-admin via the `read_by` JSON array of user IDs.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->string('type')->index();              // order.placed | order.paid | order.cancelled | checkout.abandoned
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('link', 1024)->nullable();     // admin route to open on click
            $table->json('data')->nullable();             // extra context (order_id, etc.)
            $table->json('read_by')->nullable();          // array of user IDs who have read it
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notifications');
    }
};
