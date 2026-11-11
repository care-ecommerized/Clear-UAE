<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill order_items.product_image for orders that were created before the
 * Payment/Order controllers started persisting the image. Joins to products
 * by product_id and copies the current image_url for every NULL/empty row.
 * Idempotent: skips rows that already have an image set.
 */
return new class extends Migration
{
    public function up(): void
    {
        // UPDATE ... JOIN is MySQL-only syntax, and the test suite migrates a
        // fresh SQLite database where there is no legacy data to backfill.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(<<<'SQL'
            UPDATE order_items oi
            JOIN products p ON p.id = oi.product_id
            SET oi.product_image = p.image_url
            WHERE (oi.product_image IS NULL OR oi.product_image = '')
              AND p.image_url IS NOT NULL
              AND p.image_url <> ''
        SQL);
    }

    public function down(): void
    {
        // One-shot data backfill; nothing to revert.
    }
};
