<?php

/**
 * Invoice / VAT settings.
 *
 * These drive the customer tax invoice (resources/views/invoices/customer-invoice.blade.php).
 * The store's identity on the invoice (legal name, address, phone, email,
 * licence and tax number) comes from Admin → Settings → Branding via
 * App\Support\Brand; the values here are only env fallbacks for it.
 *
 * The TRN is deliberately NOT hard-coded with a fallback: an invoice must never
 * display a made-up tax number, so the template only prints the TRN line — and
 * only calls itself a Tax Invoice — when a real value is configured.
 */
return [

    /*
     * Tax Registration Number. Used only when the Branding "invoice tax
     * number" setting is blank. Leave empty if not VAT-registered: the
     * document then renders as a plain commercial invoice with no VAT.
     */
    'trn' => env('COMPANY_TRN'),

    /*
     * Standard VAT rate (UAE: 5% since 2018).
     */
    'vat_rate' => (float) env('VAT_RATE', 0.05),

    /*
     * Whether the prices captured on orders already include VAT.
     *
     * TRUE by default: the storefront charges a single all-in price with no
     * VAT added at checkout, so the amount collected IS the gross. VAT is
     * therefore back-calculated out of the total (total x rate / (1 + rate))
     * rather than added on top — adding it on top would restate what the
     * customer actually paid, which an invoice must never do.
     */
    'prices_include_vat' => (bool) env('PRICES_INCLUDE_VAT', true),

    /*
     * Country of supply is the store's country (Brand::countryCode()). A
     * delivery to any other country is treated as an export of goods and
     * zero-rated (0%) on the invoice. Its ISO code and English name are
     * recognised automatically; list any other spellings orders may carry here.
     */
    'home_country_aliases' => array_filter(explode(',', (string) env('INVOICE_HOME_COUNTRY_ALIASES', ''))),

    /*
     * Company identity fallbacks for the "From" block, used only when the
     * matching Branding setting is blank.
     */
    'company' => [
        'name' => env('COMPANY_NAME', ''),
        'address' => env('COMPANY_ADDRESS', ''),
        'phone' => env('COMPANY_PHONE', ''),
        'email' => env('COMPANY_EMAIL', ''),
        'licence' => env('COMPANY_LICENCE', ''),
    ],
];
