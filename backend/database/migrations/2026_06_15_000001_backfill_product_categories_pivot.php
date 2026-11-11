<?php

// Migration: backfill the product_categories pivot from products.category_id.
// The storefront listing and category counts now read the pivot, so every
// product's PRIMARY category must have a matching pivot row. INSERT IGNORE makes
// this idempotent (skips rows that already exist) and non-destructive (never
// removes multi-category memberships added via the product form).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // INSERT IGNORE is MySQL-only; the test suite migrates a fresh SQLite
        // database where there are no products to backfill.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            'INSERT IGNORE INTO product_categories (product_id, category_id)
             SELECT id, category_id FROM products WHERE category_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        // No-op: memberships must survive a rollback (they may include
        // multi-category assignments made after this migration ran).
    }
};
