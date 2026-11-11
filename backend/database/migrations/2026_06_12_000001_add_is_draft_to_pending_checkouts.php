<?php

// Adds the `is_draft` flag so an admin-created "draft order" can live in the
// same pending_checkouts table as a normal storefront checkout. A draft is a
// staged cart (stock reserved) that an admin builds for a customer; the
// customer pays it later via an emailed/shared /pay/{reference} link, at which
// point convertCheckoutToOrder() turns it into a real Order.
//
// Drafts intentionally have expires_at = NULL so the abandoned-checkout cron
// (which only touches `status=pending AND expires_at < now()`) never expires
// them or restores their reserved stock.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_checkouts', function (Blueprint $table) {
            $table->boolean('is_draft')->default(false)->index()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('pending_checkouts', function (Blueprint $table) {
            $table->dropColumn('is_draft');
        });
    }
};
