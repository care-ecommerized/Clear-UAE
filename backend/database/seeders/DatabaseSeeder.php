<?php

/**
 * ============================================================
 * DatabaseSeeder.php — Main Database Seeder
 * ============================================================
 * What this file does:
 *   Runs the base seeders a brand-new, empty store needs to work:
 *   default site settings and default shipping rates. It creates
 *   no users or products — the installer creates the admin account,
 *   and demo products come from DemoSeeder (optional).
 *
 * Every seeder here is idempotent, so re-running never duplicates.
 *
 * How to run:
 *   php artisan db:seed                          (base data)
 *   php artisan db:seed --class=DemoSeeder       (optional demo catalogue)
 * ============================================================
 */

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            SettingsDefaultsSeeder::class, // first: shipping reads the country/currency
            ShippingDefaultsSeeder::class,
        ]);
    }
}
