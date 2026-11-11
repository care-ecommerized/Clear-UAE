<?php

use App\Models\ShippingRate;
use Illuminate\Database\Migrations\Migration;

/**
 * The checkout country list grew from 53 to ~197 countries. Checkout only
 * accepts a destination that has an admin-defined rate (otherwise it would
 * silently fall back to a flat local price), so give every newly listed country
 * the same flat rate the other non-GCC countries already use.
 *
 * The rate is COPIED from an existing non-GCC row (delivery type, weight range,
 * cost, currency) rather than hard-coded, so this always matches whatever the
 * shop currently charges internationally. Countries that already have any rate
 * are left untouched, and if there is no non-GCC rate to copy (e.g. a fresh
 * staging DB) nothing is inserted.
 */
return new class extends Migration
{
    private const NEW_COUNTRY_CODES = [
        // Existing-list country that never had a rate
        'YE',
        // Middle East
        'PS',
        // Asia
        'AF', 'NP', 'BT', 'MV', 'MM', 'KH', 'LA', 'VN', 'BN', 'TL', 'MN', 'HK', 'MO', 'TW',
        'KZ', 'UZ', 'KG', 'TJ', 'TM', 'AZ', 'AM', 'GE',
        // Europe
        'IE', 'PT', 'GR', 'FI', 'IS', 'LU', 'MT', 'CY', 'CZ', 'SK', 'HU', 'RO', 'BG', 'HR', 'SI',
        'RS', 'BA', 'ME', 'MK', 'AL', 'EE', 'LV', 'LT', 'UA', 'BY', 'MD', 'MC', 'LI', 'AD', 'SM',
        'GI', 'FO', 'GL',
        // North & Central America, Caribbean
        'GT', 'BZ', 'HN', 'SV', 'NI', 'CR', 'PA', 'JM', 'HT', 'DO', 'PR', 'TT', 'BS', 'BB', 'BM',
        'KY', 'AW', 'CW', 'LC', 'GD', 'AG', 'KN', 'VC', 'DM',
        // South America
        'PE', 'VE', 'EC', 'BO', 'PY', 'UY', 'GY', 'SR',
        // Africa
        'LY', 'SD', 'SS', 'ET', 'ER', 'DJ', 'SO', 'UG', 'TZ', 'RW', 'BI', 'CD', 'CG', 'GA', 'CM',
        'CF', 'TD', 'NE', 'ML', 'BF', 'SN', 'GM', 'GN', 'GW', 'SL', 'LR', 'CI', 'GH', 'TG', 'BJ',
        'MR', 'CV', 'GQ', 'ST', 'AO', 'ZM', 'ZW', 'MW', 'MZ', 'NA', 'BW', 'LS', 'SZ', 'MG', 'MU',
        'SC', 'KM',
        // Oceania
        'FJ', 'PG', 'WS', 'TO', 'VU', 'SB', 'NC', 'PF', 'GU',
    ];

    private const GCC_AND_LOCAL = ['AE', 'SA', 'KW', 'QA', 'BH', 'OM'];

    public function up(): void
    {
        $template = ShippingRate::query()
            ->where('is_active', true)
            ->whereNotIn('destination_country', self::GCC_AND_LOCAL)
            ->orderBy('created_at')
            ->first();

        if (! $template) {
            return;
        }

        foreach (self::NEW_COUNTRY_CODES as $code) {
            if (ShippingRate::where('destination_country', $code)->exists()) {
                continue;
            }

            ShippingRate::create([
                'origin_country' => $template->origin_country,
                'destination_country' => $code,
                'delivery_type' => $template->delivery_type,
                'min_weight' => $template->min_weight,
                'max_weight' => $template->max_weight,
                'cost' => $template->cost,
                'currency' => $template->currency,
                'is_active' => true,
                'sort_order' => $template->sort_order ?? 0,
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally a no-op: these rows are indistinguishable from rates the
        // admin may have edited since, so rolling back must not delete pricing.
    }
};
