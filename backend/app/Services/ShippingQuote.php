<?php

namespace App\Services;

use App\Models\ShippingRate;
use App\Support\Brand;
use App\Support\CountryCode;

/**
 * Prices shipping for a checkout from the admin-defined `shipping_rates`
 * table (Admin → Settings → Shipping Rates).
 *
 * The checkout page shows the same price from the same table, but the
 * amount it submits is never trusted: a browser that failed to load the
 * rates used to fall back to a flat 25 and the order was charged that
 * (25 instead of the configured rate of 35).
 *
 * Rules (kept in step with useCustomShipping.getShippingOptions):
 *   - Weight is 0.3 kg per watch.
 *   - Only active rates from the store's country (Brand::countryCode(),
 *     AE by default) to the destination count.
 *   - A rate applies when min_weight <= weight <= max_weight. When a cart is
 *     heavier than every band of a delivery type, that type's heaviest band
 *     is used, so large carts are still priced instead of blocked.
 *   - The customer's chosen delivery type is used when it exists for the
 *     destination; otherwise the cheapest available option.
 *   - No rate for the destination → null (the caller refuses the checkout).
 */
class ShippingQuote
{
    public const KG_PER_ITEM = 0.3;

    /** Origin of every shipment: the store's own country (AE by default). */
    public static function origin(): string
    {
        return Brand::countryCode();
    }

    /**
     * @param  array<int, array{quantity: int|string}>  $items
     * @return array{country_code: string, delivery_type: string, cost: float}|null
     */
    public function quote(?string $country, ?string $countryCodeHint, array $items, ?string $deliveryType): ?array
    {
        // The country name is authoritative. The code the browser sends is
        // only used when the name can't be recognised at all.
        $code = CountryCode::tryToIso2($country) ?? CountryCode::tryToIso2($countryCodeHint);
        if ($code === null) {
            return null;
        }

        $quantity = array_sum(array_map(fn ($i) => (int) ($i['quantity'] ?? 0), $items));
        $weight = round($quantity * self::KG_PER_ITEM, 3);

        $options = $this->optionsFor($code, $weight);
        if ($options === []) {
            return null;
        }

        $chosen = ($deliveryType !== null && isset($options[$deliveryType]))
            ? $options[$deliveryType]
            : collect($options)->sortBy('cost')->first();

        return ['country_code' => $code] + $chosen;
    }

    /**
     * One option per delivery type for this destination and weight.
     *
     * @return array<string, array{delivery_type: string, cost: float}>
     */
    public function optionsFor(string $countryCode, float $weight): array
    {
        $rates = ShippingRate::query()
            ->where('origin_country', self::origin())
            ->where('destination_country', $countryCode)
            ->where('is_active', true)
            ->get();

        $options = [];
        foreach ($rates->groupBy('delivery_type') as $type => $typeRates) {
            $match = $typeRates->first(fn ($r) => $weight >= (float) $r->min_weight && $weight <= (float) $r->max_weight)
                ?? $typeRates->sortByDesc(fn ($r) => (float) $r->max_weight)->first();

            $options[$type] = ['delivery_type' => (string) $type, 'cost' => round((float) $match->cost, 2)];
        }

        return $options;
    }
}
