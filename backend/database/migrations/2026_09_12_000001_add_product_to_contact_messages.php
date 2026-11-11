<?php

/**
 * Let a contact message point at a specific product, so the "Ask about this
 * watch" form on the product page lands in the SAME admin inbox as the normal
 * contact form (with its read/replied state and reply-by-email flow) instead
 * of needing a parallel table of its own.
 *
 * The name/sku/url columns are deliberately a SNAPSHOT rather than something
 * read back through the relation. These are one-of-a-kind vintage pieces that
 * get sold and delisted, and an enquiry has to stay answerable months later —
 * after the product row is gone or renamed. product_id is intentionally NOT a
 * foreign key for the same reason: deleting a watch must never cascade away
 * the lead it generated.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_messages', function (Blueprint $table) {
            $table->uuid('product_id')->nullable()->after('subject')->index();
            $table->string('product_name')->nullable()->after('product_id');
            $table->string('product_sku')->nullable()->after('product_name');
            $table->string('product_url', 1024)->nullable()->after('product_sku');
        });
    }

    public function down(): void
    {
        Schema::table('contact_messages', function (Blueprint $table) {
            $table->dropIndex(['product_id']);
            $table->dropColumn(['product_id', 'product_name', 'product_sku', 'product_url']);
        });
    }
};
