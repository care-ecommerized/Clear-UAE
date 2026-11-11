<?php

/**
 * ============================================================
 * SettingsDefaultsSeeder.php — Default site settings
 * ============================================================
 * Gives a brand-new store sensible site_settings so it works out of
 * the box: store identity, currency, theme colours, homepage section
 * toggles and email notification toggles. Every payment method starts
 * OFF — the admin switches a gateway on once its keys are entered in
 * Admin → Settings → Integrations.
 *
 * Idempotent: a key that already exists is never overwritten, so it is
 * safe to re-run after the admin has customised the store.
 *
 * Keys are stored UPPER-case, exactly like SettingsController writes them.
 *
 * Run: php artisan db:seed --class=SettingsDefaultsSeeder
 * ============================================================
 */

namespace Database\Seeders;

use App\Models\SiteSetting;
use Illuminate\Database\Seeder;

class SettingsDefaultsSeeder extends Seeder
{
    /**
     * Default values, lower-case keys (as the frontend reads them).
     *
     * Policies and most copy fields are deliberately absent: blank means the
     * storefront uses its built-in bilingual copy.
     *
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            // Store identity
            'store_name' => 'Ecomweb',
            'order_number_prefix' => 'ORD-',
            'default_currency' => 'AED',
            'currency' => 'AED',
            'country_code' => 'AE',
            'timezone' => 'Asia/Dubai',

            // Currencies & languages
            'supported_currencies' => '["AED","USD","EUR","GBP","SAR","KWD","BHD"]',
            'currency_rates' => '{"AED":1,"USD":0.27,"EUR":0.25,"GBP":0.21,"SAR":1.02,"KWD":0.08,"BHD":0.10}',
            'default_language' => 'en',
            'supported_languages' => '["en","ar"]',
            'free_shipping_threshold' => '500',
            'gift_card_price' => '10',

            // Payments — all off until the admin configures a gateway.
            // COD must stay off: it is a testing aid, not a production method.
            'payment_stripe_enabled' => 'false',
            'payment_paymennt_enabled' => 'false',
            'payment_tabby_enabled' => 'false',
            'payment_tamara_enabled' => 'false',
            'payment_cod_enabled' => 'false',

            // Theme (Admin → Theme defaults)
            'theme_primary_color' => '#015056',
            'theme_accent_color' => '#D0D2AF',
            'theme_background_color' => '#FFFFF0',
            'theme_text_color' => '#015056',
            'theme_header_bg_color' => '#015056',
            'theme_footer_bg_color' => '#015056',
            'theme_secondary_color' => '#D0D2AF',
            'theme_muted_color' => '#D0D2AF',
            'theme_border_color' => '#D0D2AF',

            // Homepage sections (same as useSiteSettings defaults)
            'announcement_enabled' => 'true',
            'section_announcement_enabled' => 'true',
            'section_categorygrid_enabled' => 'true',
            'section_featured_enabled' => 'true',
            'section_brandstory_enabled' => 'true',
            'section_midbanner_enabled' => 'true',
            'section_newsletter_enabled' => 'false',
            'section_faq_enabled' => 'true',
            'faq_items' => '[]',

            // Off on a fresh store: the popup promises a discount code and the
            // countdown counts down to a "drop" — neither exists yet.
            'popup_enabled' => 'false',
            'popup_discount_percent' => '5',
            'countdown_enabled' => 'false',

            // Product-page enquiry CTAs are opt-in.
            'product_inquiry_enabled' => 'false',
            'product_whatsapp_enabled' => 'false',

            // Email notifications — all on.
            'notify_order_confirmation_enabled' => 'true',
            'notify_new_order_admin_enabled' => 'true',
            'notify_order_status_update_enabled' => 'true',
            'notify_order_cancelled_enabled' => 'true',
            'notify_order_refund_enabled' => 'true',
            'notify_abandoned_checkout_enabled' => 'true',
            'notify_password_reset_enabled' => 'true',
            'notify_email_verification_enabled' => 'true',
        ];
    }

    public function run(): void
    {
        $created = 0;
        foreach (self::defaults() as $key => $value) {
            $setting = SiteSetting::firstOrCreate(['key' => strtoupper($key)], ['value' => $value]);
            if ($setting->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->command?->info("Site settings created: $created (existing kept)");
    }
}
