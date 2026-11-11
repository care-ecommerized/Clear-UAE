<?php

namespace App\Support;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The store's identity, read from site_settings (Admin → Theme → Branding).
 *
 * Every place that shows the store's name, logo, contact details or legal
 * entity — emails, the PDF invoice, server-rendered SEO, the sitemap, order
 * numbers — reads it from here, so a buyer rebrands the whole store from the
 * admin without touching code. Values fall back to neutral defaults when a
 * setting is blank, or when the database isn't installed yet.
 */
class Brand
{
    public const CACHE_KEY = 'brand.settings';

    private const DEFAULTS = [
        'store_name' => 'Ecomweb',
        'legal_name' => '',
        'store_email' => '',
        'store_phone' => '',
        'store_address' => '',
        'store_address_ar' => '',
        'store_city_en' => '',
        'store_city_ar' => '',
        'logo_url' => '',
        'logo_dark_url' => '',
        'favicon_url' => '',
        'og_image_url' => '',
        'site_tagline' => '',
        'seo_title_en' => '',
        'seo_title_ar' => '',
        'seo_description_en' => '',
        'seo_description_ar' => '',
        'order_number_prefix' => 'ORD-',
        'invoice_licence_no' => '',
        'invoice_tax_no' => '',
        'mail_from_name' => '',
        'mail_from_address' => '',
        'default_currency' => 'AED',
        'currency' => '',
        'timezone' => '',
        'country_code' => 'AE',
        'theme_primary_color' => '',
        'instagram_url' => '',
        'facebook_url' => '',
        'twitter_url' => '',
        'tiktok_url' => '',
        'whatsapp_number' => '',
    ];

    /** Fallback brand color for emails when the theme has none. */
    public const DEFAULT_COLOR = '#015056';

    /** @var array<string, string>|null */
    private static ?array $resolved = null;

    /** All brand values, lower-case keys, blanks replaced by defaults. */
    public static function all(): array
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $stored = self::stored();
        $values = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = trim((string) ($stored[$key] ?? ''));
            if (in_array($key, self::UPLOAD_URL_KEYS, true)) {
                $value = self::rootRelativeUpload($value);
            }
            $values[$key] = $value !== '' ? $value : $default;
        }

        return self::$resolved = $values;
    }

    public static function get(string $key, string $fallback = ''): string
    {
        $value = self::all()[$key] ?? '';

        return $value !== '' ? $value : $fallback;
    }

    /**
     * One raw site_settings value, whatever the case of its key. The admin
     * saves keys upper-case (PAYMENT_STRIPE_ENABLED) while seeders and old
     * code used lower-case; MySQL's _ci collation hid that, SQLite's '='
     * does not. The upper-case row (what the admin last saved) wins.
     */
    public static function setting(string $key, ?string $default = null): ?string
    {
        try {
            $rows = SiteSetting::query()
                ->whereIn('key', array_unique([strtoupper($key), strtolower($key), $key]))
                ->pluck('value', 'key');
        } catch (Throwable) {
            return $default;
        }

        $value = $rows[strtoupper($key)] ?? $rows[$key] ?? $rows[strtolower($key)] ?? null;

        return $value === null ? $default : (string) $value;
    }

    /**
     * Forget the cached values. Called whenever settings are saved. Before
     * install the installer swaps the cache store to files, so the store the
     * app really uses (remembered as installer.real_cache_store) is cleared
     * as well — otherwise defaults cached there would outlive the install.
     */
    public static function flush(): void
    {
        self::$resolved = null;
        $stores = array_unique(array_filter([config('cache.default'), config('installer.real_cache_store')]));
        foreach ($stores as $store) {
            try {
                Cache::store($store)->forget(self::CACHE_KEY);
            } catch (Throwable) {
                // Cache store unavailable (e.g. before install) — nothing cached.
            }
        }
    }

    public static function name(): string
    {
        return self::get('store_name');
    }

    /** Legal entity for invoices and email footers; the store name when unset. */
    public static function legalName(): string
    {
        return self::get('legal_name', self::name());
    }

    public static function email(): string
    {
        return self::get('store_email', (string) config('mail.from.address', ''));
    }

    public static function phone(): string
    {
        return self::get('store_phone');
    }

    public static function address(): string
    {
        return self::get('store_address');
    }

    public static function color(): string
    {
        $color = self::get('theme_primary_color');

        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) ? $color : self::DEFAULT_COLOR;
    }

    public static function currency(): string
    {
        return strtoupper(self::get('default_currency', self::get('currency', 'AED')));
    }

    public static function orderPrefix(): string
    {
        return self::get('order_number_prefix', 'ORD-');
    }

    public static function countryCode(): string
    {
        return strtoupper(self::get('country_code', 'AE'));
    }

    /** Public site URL without a trailing slash. */
    public static function url(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /** Domain shown in email/invoice footers, e.g. "example.com". */
    public static function domain(): string
    {
        $host = parse_url(self::url(), PHP_URL_HOST);

        return $host ? preg_replace('/^www\./', '', $host) : '';
    }

    /**
     * Absolute logo URL for emails and meta tags. Uses the uploaded logo, else
     * the bundled default at /logo.png.
     */
    public static function logoUrl(bool $dark = false): string
    {
        $logo = $dark ? self::get('logo_dark_url', self::get('logo_url')) : self::get('logo_url');

        return self::absolute($logo !== '' ? self::mailSafeLogo($logo) : '/logo.png');
    }

    public static function ogImageUrl(): string
    {
        $image = self::get('og_image_url');

        return $image !== '' ? self::absolute($image) : self::logoUrl();
    }

    /**
     * A local filesystem path to the logo, for the PDF renderer (it can't fetch
     * the site's own URLs reliably on shared hosting). Null when no readable file.
     */
    public static function logoPath(): ?string
    {
        $logo = self::get('logo_url');
        $candidates = [];

        if ($logo !== '') {
            // TCPDF's WebP support depends on GD; hand it the PNG rendition.
            $path = parse_url(self::mailSafeLogo($logo), PHP_URL_PATH) ?: $logo;
            if (preg_match('#/storage/(.+)$#', $path, $m)) {
                $candidates[] = storage_path('app/public/'.urldecode($m[1]));
            }
            $candidates[] = public_path(ltrim($path, '/'));
        }

        $candidates[] = public_path('logo.jpg');
        $candidates[] = public_path('logo.png');

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Settings that hold an uploaded file's URL. */
    private const UPLOAD_URL_KEYS = ['logo_url', 'logo_dark_url', 'favicon_url', 'og_image_url'];

    /**
     * Uploads saved before URLs became root-relative carry the install-time
     * domain (http://temp-domain/storage/...). Keep only the path so they
     * follow the store to its real domain.
     */
    private static function rootRelativeUpload(string $value): string
    {
        if ($value !== '' && preg_match('#^https?://[^/]+(/storage/.+)$#i', $value, $m)) {
            return $m[1];
        }

        return $value;
    }

    /**
     * Outlook and several webmail clients don't render WebP. For an uploaded
     * .webp logo, write a PNG sibling (<name>_mail.png, transparency kept for
     * the brand-coloured email header) once and return its URL. Falls back
     * to the original when conversion is not possible.
     */
    private static function mailSafeLogo(string $logo): string
    {
        $path = (string) (parse_url($logo, PHP_URL_PATH) ?: $logo);
        if (! preg_match('#/storage/(uploads/.+)\.webp$#i', $path, $m)) {
            return $logo;
        }

        $source = storage_path('app/public/'.urldecode($m[1]).'.webp');
        $pngRel = $m[1].'_mail.png';
        $target = storage_path('app/public/'.urldecode($pngRel));
        $pngUrl = '/storage/'.$pngRel;

        if (is_file($target)) {
            return $pngUrl;
        }
        if (! is_file($source)) {
            return $logo;
        }

        try {
            if (function_exists('imagecreatefromwebp')) {
                $img = @imagecreatefromwebp($source);
                if ($img !== false) {
                    imagealphablending($img, false);
                    imagesavealpha($img, true);
                    $ok = @imagepng($img, $target);
                    imagedestroy($img);
                    if ($ok) {
                        return $pngUrl;
                    }
                }
            }
            if (class_exists(\Imagick::class)) {
                $im = new \Imagick($source);
                $im->setImageFormat('png');
                if ($im->writeImage($target)) {
                    return $pngUrl;
                }
            }
        } catch (Throwable) {
            // Fall through to the original URL.
        }

        return $logo;
    }

    private static function absolute(string $url): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        return self::url().'/'.ltrim($url, '/');
    }

    /** Raw settings rows as lower-case key => value. */
    private static function stored(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, 3600, function () {
                if (! Schema::hasTable('site_settings')) {
                    return [];
                }

                return SiteSetting::query()
                    ->whereIn('key', array_map('strtoupper', array_keys(self::DEFAULTS)))
                    ->pluck('value', 'key')
                    ->mapWithKeys(fn ($v, $k) => [strtolower($k) => (string) $v])
                    ->all();
            });
        } catch (Throwable) {
            // Not installed yet / DB unreachable: run on defaults.
            return [];
        }
    }
}
