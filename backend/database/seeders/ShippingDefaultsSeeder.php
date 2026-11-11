<?php

/**
 * Seeds generic delivery types and shipping rates so the checkout shows a
 * shipping option out of the box:
 *
 *   - "Standard Delivery"      — one flat rate to the store's own country
 *   - "International Shipping" — one flat rate to every other country the
 *                                checkout lists (src/data/countries.ts)
 *
 * The origin is the store's country (Brand::countryCode(), from the
 * country_code setting) and the currency its default currency, so a store
 * outside the UAE gets rates that match. Prices are placeholders the admin
 * edits in Admin → Settings → Shipping Rates.
 *
 * Idempotent — safe to re-run: a destination that already has any rate from
 * the origin is left untouched, and delivery types are matched by name.
 *
 * Run: php artisan db:seed --class=ShippingDefaultsSeeder
 */

namespace Database\Seeders;

use App\Models\DeliveryType;
use App\Models\ShippingRate;
use App\Support\Brand;
use Illuminate\Database\Seeder;

class ShippingDefaultsSeeder extends Seeder
{
    public const STANDARD_COST = 25.00;

    public const INTERNATIONAL_COST = 150.00;

    /** ISO-2 codes of every country in the checkout list (src/data/countries.ts). */
    public const COUNTRIES = [
        'AE', 'SA', 'KW', 'QA', 'BH', 'OM', 'JO', 'LB', 'EG', 'IQ', 'YE', 'PS', 'IN', 'PK', 'BD', 'LK',
        'PH', 'ID', 'MY', 'SG', 'TH', 'CN', 'JP', 'KR', 'AF', 'NP', 'BT', 'MV', 'MM', 'KH', 'LA', 'VN',
        'BN', 'TL', 'MN', 'HK', 'MO', 'TW', 'KZ', 'UZ', 'KG', 'TJ', 'TM', 'AZ', 'AM', 'GE', 'GB', 'DE',
        'FR', 'IT', 'ES', 'NL', 'BE', 'CH', 'AT', 'SE', 'NO', 'DK', 'PL', 'TR', 'RU', 'IE', 'PT', 'GR',
        'FI', 'IS', 'LU', 'MT', 'CY', 'CZ', 'SK', 'HU', 'RO', 'BG', 'HR', 'SI', 'RS', 'BA', 'ME', 'MK',
        'AL', 'EE', 'LV', 'LT', 'UA', 'BY', 'MD', 'MC', 'LI', 'AD', 'SM', 'GI', 'FO', 'GL', 'US', 'CA',
        'MX', 'GT', 'BZ', 'HN', 'SV', 'NI', 'CR', 'PA', 'JM', 'HT', 'DO', 'PR', 'TT', 'BS', 'BB', 'BM',
        'KY', 'AW', 'CW', 'LC', 'GD', 'AG', 'KN', 'VC', 'DM', 'BR', 'AR', 'CL', 'CO', 'PE', 'VE', 'EC',
        'BO', 'PY', 'UY', 'GY', 'SR', 'ZA', 'NG', 'KE', 'MA', 'TN', 'DZ', 'LY', 'SD', 'SS', 'ET', 'ER',
        'DJ', 'SO', 'UG', 'TZ', 'RW', 'BI', 'CD', 'CG', 'GA', 'CM', 'CF', 'TD', 'NE', 'ML', 'BF', 'SN',
        'GM', 'GN', 'GW', 'SL', 'LR', 'CI', 'GH', 'TG', 'BJ', 'MR', 'CV', 'GQ', 'ST', 'AO', 'ZM', 'ZW',
        'MW', 'MZ', 'NA', 'BW', 'LS', 'SZ', 'MG', 'MU', 'SC', 'KM', 'AU', 'NZ', 'FJ', 'PG', 'WS', 'TO',
        'VU', 'SB', 'NC', 'PF', 'GU',
    ];

    public function run(): void
    {
        // ---- Delivery types ----
        $types = [
            ['name' => 'standard',      'label' => 'Standard Delivery',      'estimated_days' => '1-3 working days',  'sort_order' => 10],
            ['name' => 'international', 'label' => 'International Shipping', 'estimated_days' => '7-10 working days', 'sort_order' => 20],
        ];
        foreach ($types as $t) {
            DeliveryType::firstOrCreate(
                ['name' => $t['name']],
                array_merge($t, ['is_active' => true])
            );
        }

        // ---- Shipping rates ----
        Brand::flush(); // pick up a country/currency seeded moments ago
        $origin = Brand::countryCode();
        $currency = Brand::currency();

        $rates = [['destination_country' => $origin, 'delivery_type' => 'standard', 'cost' => self::STANDARD_COST]];
        foreach (self::COUNTRIES as $country) {
            if ($country !== $origin) {
                $rates[] = ['destination_country' => $country, 'delivery_type' => 'international', 'cost' => self::INTERNATIONAL_COST];
            }
        }

        $existing = ShippingRate::where('origin_country', $origin)
            ->pluck('destination_country')
            ->map(fn ($c) => strtoupper($c))
            ->flip();

        $created = 0;
        foreach ($rates as $rate) {
            if ($existing->has($rate['destination_country'])) {
                continue;
            }
            ShippingRate::create(array_merge($rate, [
                'origin_country' => $origin,
                'currency' => $currency,
                'min_weight' => 0,
                'max_weight' => 30,
                'is_active' => true,
                'sort_order' => 0,
            ]));
            $created++;
        }

        $this->command?->info('Delivery types: '.DeliveryType::count());
        $this->command?->info("Shipping rates created: $created (existing kept)");
    }
}
