<?php

namespace App\Services;

use App\Models\Order;
use App\Support\Brand;
use Locale;

/**
 * Works out the VAT breakdown for a customer invoice.
 *
 * Two things drive every decision here:
 *
 * 1. Orders carry NO tax columns — only subtotal / discount / shipping / total.
 *    The storefront has always charged one all-in price with no VAT added at
 *    checkout, so the captured total IS the gross amount the customer paid. VAT
 *    is therefore extracted out of it, never added on top: an invoice has to
 *    state what was actually charged, and adding 5% after the fact would
 *    describe a payment that never happened.
 *
 * 2. A delivery outside the store's home country (Brand::countryCode()) is an
 *    export of goods and is zero-rated, so the whole invoice carries 0% and the
 *    total is unchanged.
 */
class InvoiceTax
{
    /**
     * Names stored on orders that differ from the ISO code, for the common
     * cases (and as the only names when the intl extension is missing).
     */
    private const COUNTRY_ALIASES = [
        'AE' => ['UAE', 'United Arab Emirates'],
        'SA' => ['KSA', 'Saudi Arabia'],
        'GB' => ['UK', 'United Kingdom'],
        'US' => ['USA', 'United States'],
        'QA' => ['Qatar'],
        'KW' => ['Kuwait'],
        'BH' => ['Bahrain'],
        'OM' => ['Oman'],
    ];

    /**
     * @return array{
     *     registered: bool, trn: ?string, is_export: bool, rate: float,
     *     rate_label: string, gross: float, net: float, vat: float,
     *     title: string
     * }
     */
    public function for(Order $order): array
    {
        // Admin → Branding first, then the COMPANY_TRN env fallback.
        $trn = trim(Brand::get('invoice_tax_no', (string) config('invoice.trn'))) ?: null;
        $registered = $trn !== null;

        $isExport = $this->isExport($order->shipping_country);

        // Zero-rated when exporting, and no VAT at all when the business isn't
        // VAT-registered — in that case this is a commercial invoice, not a tax
        // invoice, and must not show a rate.
        $rate = ($registered && ! $isExport) ? (float) config('invoice.vat_rate', 0.05) : 0.0;

        $gross = (float) $order->total;

        if (! config('invoice.prices_include_vat', true)) {
            // Exclusive pricing: the captured total is net and VAT sits on top.
            $vat = round($gross * $rate, 2);
            $net = $gross;
            $gross = $net + $vat;
        } else {
            // Inclusive pricing (the default): pull the VAT back out of the
            // total. Deriving net by subtraction keeps net + vat === total to
            // the fils, which a re-multiplication would not guarantee.
            $vat = $rate > 0 ? round($gross * $rate / (1 + $rate), 2) : 0.0;
            $net = round($gross - $vat, 2);
        }

        return [
            'registered' => $registered,
            'trn' => $trn,
            'is_export' => $isExport,
            'rate' => $rate,
            'rate_label' => $this->rateLabel($registered, $isExport, $rate),
            'gross' => $gross,
            'net' => $net,
            'vat' => $vat,
            'title' => $registered ? 'TAX INVOICE' : 'INVOICE',
        ];
    }

    /** A supply delivered anywhere other than the home country is an export. */
    private function isExport(?string $country): bool
    {
        $normalized = strtoupper(trim((string) $country));

        if ($normalized === '') {
            // Treated as an export. Only a country explicitly recognised as the
            // home country carries VAT; anything else, blank included, is
            // zero-rated. (Checkout always fills shipping_country, so this is a
            // rule, not a case that fires in practice.)
            return true;
        }

        return ! in_array($normalized, $this->homeCountryNames(), true);
    }

    /**
     * Every spelling of the home country an order may carry, upper-cased: its
     * ISO code, its English name, common abbreviations, plus any extra
     * aliases set in invoice.home_country_aliases.
     *
     * @return list<string>
     */
    private function homeCountryNames(): array
    {
        $code = Brand::countryCode();
        $names = [$code];

        if (class_exists(Locale::class)) {
            $name = Locale::getDisplayRegion('-'.$code, 'en');
            if ($name !== '' && $name !== $code) {
                $names[] = $name;
            }
        }

        $names = array_merge(
            $names,
            self::COUNTRY_ALIASES[$code] ?? [],
            (array) config('invoice.home_country_aliases', [])
        );

        return array_values(array_unique(array_map(
            fn ($n) => strtoupper(trim((string) $n)),
            $names
        )));
    }

    private function rateLabel(bool $registered, bool $isExport, float $rate): string
    {
        if (! $registered) {
            return '';
        }

        if ($isExport) {
            return '0% (zero-rated export)';
        }

        return rtrim(rtrim(number_format($rate * 100, 2, '.', ''), '0'), '.').'%';
    }
}
