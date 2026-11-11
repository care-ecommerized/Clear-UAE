<?php

namespace App\Services;

use App\Support\Brand;
use Illuminate\Support\Facades\Log;

/**
 * Converts amounts in the store's base currency (Brand::currency() — the
 * currency product prices, checkout totals and shipping rates are entered
 * in) into the currency a payment gateway is charging in.
 *
 * The rate comes from the admin-editable `currency_rates` site setting —
 * deliberately the same source the storefront uses to display prices
 * (see CurrencyContext.tsx), so the SAR figure a Saudi customer sees on
 * the product page is the SAR figure Tamara/Tabby actually bill them.
 * Reading the rate from anywhere else would let display and charge drift
 * apart.
 *
 * Rates are rebased on the store currency: rate(X) = rates[X] / rates[base].
 * A table keyed to AED (the shipped default) therefore keeps working for a
 * store whose base is USD, and so does one an admin re-keys to their own base.
 */
class CurrencyConverter
{
    /**
     * Mirrors the defaults in src/hooks/useSiteSettings.ts (AED-relative).
     * Used only when the DB setting is missing or unparseable — keeping them
     * identical is what stops the frontend and backend disagreeing then.
     */
    private const FALLBACK_RATES = [
        'AED' => 1.0,
        'USD' => 0.27,
        'EUR' => 0.25,
        'GBP' => 0.21,
        'SAR' => 1.02,
        'KWD' => 0.08,
        'BHD' => 0.10,
    ];

    /** @var array<string, float>|null */
    private ?array $rates = null;

    /** The store's base currency. */
    public function base(): string
    {
        return strtoupper(Brand::currency());
    }

    /**
     * How many units of $currency make one unit of the base currency.
     */
    public function rate(string $currency): float
    {
        $currency = strtoupper($currency);
        $base = $this->base();
        if ($currency === $base) {
            return 1.0;
        }

        $target = $this->rawRate($currency);
        $baseRate = $this->rawRate($base);

        if ($target <= 0 || $baseRate <= 0) {
            Log::warning('CurrencyConverter: no usable rate, charging at 1:1', ['currency' => $currency, 'base' => $base]);

            return 1.0;
        }

        return $target / $baseRate;
    }

    /**
     * Convert a base-currency amount into $currency, rounded to 2 decimals.
     */
    public function convert(float $amountInBase, string $currency): float
    {
        return round($amountInBase * $this->rate($currency), 2);
    }

    private function rawRate(string $currency): float
    {
        return (float) ($this->rates()[$currency] ?? self::FALLBACK_RATES[$currency] ?? 0);
    }

    /**
     * @return array<string, float>
     */
    private function rates(): array
    {
        if ($this->rates !== null) {
            return $this->rates;
        }

        $decoded = json_decode((string) Brand::setting('currency_rates'), true);

        $this->rates = is_array($decoded)
            ? array_change_key_case($decoded, CASE_UPPER)
            : [];

        return $this->rates;
    }
}
