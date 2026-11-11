<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Exception\MissingResourceException;

/**
 * Maps a country as the checkout sends it (an English or Arabic name like
 * "Saudi Arabia", an alias like "UAE"/"KSA", or an ISO-2/ISO-3 code) to its
 * ISO-2 code.
 *
 * Production uses symfony/intl (full ISO 3166-1 + CLDR names). Without the
 * PHP `intl` extension (some local runtimes) a built-in table covering the
 * markets this store ships to is used instead.
 */
class CountryCode
{
    /**
     * ISO-2 code for $input, or null when it can't be recognised.
     * Pricing uses this strict form: an unknown country must never be priced
     * as if it were the UAE.
     */
    public static function tryToIso2(?string $input): ?string
    {
        $input = trim((string) $input);
        if ($input === '') {
            return null;
        }

        $hasIntl = extension_loaded('intl');

        // Common aliases first — "UK" must map to "GB", not be treated
        // as a valid ISO-2 just because it's two letters.
        $aliases = [
            'uk' => 'GB', 'usa' => 'US', 'uae' => 'AE',
            'ksa' => 'SA', 'türkiye' => 'TR',
            // Checkout list names (src/data/countries.ts) that CLDR spells
            // differently ("&" for "and", "Czechia", "Côte d’Ivoire", …).
            'myanmar' => 'MM', 'hong kong' => 'HK', 'macau' => 'MO',
            'czech republic' => 'CZ', 'bosnia and herzegovina' => 'BA',
            'trinidad and tobago' => 'TT', 'saint lucia' => 'LC',
            'antigua and barbuda' => 'AG', 'saint kitts and nevis' => 'KN',
            'saint vincent and the grenadines' => 'VC',
            'democratic republic of the congo' => 'CD', 'republic of the congo' => 'CG',
            'ivory coast' => 'CI', 'sao tome and principe' => 'ST',
        ];
        if (isset($aliases[mb_strtolower($input)])) {
            return $aliases[mb_strtolower($input)];
        }

        // Already ISO-2 — accept iff intl confirms it's a real country,
        // or (no intl) any 2-letter alpha string passes through.
        if (strlen($input) === 2 && ctype_alpha($input)) {
            $upper = strtoupper($input);
            if (! $hasIntl || Countries::exists($upper)) {
                return $upper;
            }
        }

        // ISO-3 → ISO-2 (intl only).
        if ($hasIntl && strlen($input) === 3 && ctype_alpha($input)) {
            try {
                return Countries::getAlpha2Code(strtoupper($input));
            } catch (MissingResourceException) {
                // fall through to name lookup
            }
        }

        // Name lookup via symfony/intl (Arabic frontend means customers
        // may submit Arabic country names, so we try the UI locale
        // first, then fall back to English).
        if ($hasIntl) {
            $locales = array_unique(array_filter([
                app()->getLocale(),
                config('app.locale', 'en'),
                'en',
            ]));

            foreach ($locales as $locale) {
                try {
                    $names = Countries::getNames($locale);
                } catch (MissingResourceException) {
                    continue;
                }
                $code = array_search(mb_strtolower($input), array_map('mb_strtolower', $names), true);
                if ($code !== false) {
                    return $code;
                }
            }
        }

        // Built-in fallback covering the markets this store ships to.
        $manualMap = [
            // GCC + MENA
            'united arab emirates' => 'AE', 'saudi arabia' => 'SA',
            'kuwait' => 'KW', 'qatar' => 'QA', 'bahrain' => 'BH',
            'oman' => 'OM', 'jordan' => 'JO', 'lebanon' => 'LB',
            'egypt' => 'EG', 'iraq' => 'IQ', 'morocco' => 'MA',
            'tunisia' => 'TN', 'algeria' => 'DZ', 'palestine' => 'PS',
            'yemen' => 'YE', 'turkey' => 'TR',
            // Common international
            'united states' => 'US', 'united states of america' => 'US',
            'united kingdom' => 'GB', 'great britain' => 'GB',
            'canada' => 'CA', 'australia' => 'AU', 'india' => 'IN',
            'pakistan' => 'PK', 'bangladesh' => 'BD', 'germany' => 'DE',
            'france' => 'FR', 'italy' => 'IT', 'spain' => 'ES',
            'netherlands' => 'NL', 'switzerland' => 'CH', 'singapore' => 'SG',
            'malaysia' => 'MY', 'china' => 'CN', 'japan' => 'JP',
            'south korea' => 'KR', 'korea' => 'KR',
            'south africa' => 'ZA', 'nigeria' => 'NG',
        ];

        return $manualMap[mb_strtolower($input)] ?? null;
    }

    /**
     * ISO-2 code for $input, falling back to AE so a gateway call never
     * fails on an unrecognised name (Paymennt rejects anything that isn't
     * 2-3 characters). Not for pricing — see tryToIso2().
     */
    public static function toIso2OrUae(?string $input): string
    {
        $code = self::tryToIso2($input);
        if ($code === null) {
            if (trim((string) $input) !== '') {
                Log::warning('Country code fallback to AE', ['input' => $input, 'intl' => extension_loaded('intl')]);
            }

            return 'AE';
        }

        return $code;
    }
}
