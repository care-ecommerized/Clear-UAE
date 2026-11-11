<?php

// config/services.php - Add these entries to your Laravel services config

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the configuration options for third-party
    | services. This file provides a sane location for storing the API
    | credentials that your application utilizes.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'sparkpost' => [
        'secret' => env('SPARKPOST_SECRET'),
    ],

    'stripe' => [
        'model' => App\Models\User::class,
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'paymennt' => [
        'api_key' => env('PAYMENNT_API_KEY'),
        'api_secret' => env('PAYMENNT_API_SECRET'),
        'public_key' => env('PAYMENNT_PUBLIC_KEY'),
        'webhook_secret' => env('PAYMENNT_WEBHOOK_SECRET'),
        'base_url' => env('PAYMENNT_BASE_URL', 'https://api.paymennt.com'),
    ],

    'tabby' => [
        'public_key' => env('TABBY_PUBLIC_KEY'),
        'secret_key' => env('TABBY_SECRET_KEY'),
        'merchant_code' => env('TABBY_MERCHANT_CODE'),
        // Tabby issues a separate merchant code per market. The default above is
        // the UAE one; a Saudi (SAR) session must be opened against the KSA code
        // or Tabby rejects it. Leave unset and Tabby simply stays unavailable
        // for KSA — ask Tabby for the SA merchant code to switch it on.
        'merchant_code_sa' => env('TABBY_MERCHANT_CODE_SA'),
        // Tabby's webhook auth model: when registering the webhook URL we pick
        // an arbitrary header name and a static secret. Tabby drops that header
        // onto every callback. We compare on receipt.
        'webhook_header_name' => env('TABBY_WEBHOOK_HEADER_NAME', 'X-Tabby-Auth'),
        'webhook_header_value' => env('TABBY_WEBHOOK_HEADER_VALUE'),
        // Tabby uses a single base URL for sandbox + live; the API key prefix (pk_test_ vs pk_live_) selects the environment.
        'base_url' => env('TABBY_BASE_URL', 'https://api.tabby.ai'),
    ],

    'tamara' => [
        'api_token' => env('TAMARA_API_TOKEN'),
        'public_key' => env('TAMARA_PUBLIC_KEY'),
        'notification_token' => env('TAMARA_NOTIFICATION_TOKEN'),
        // Sandbox: https://api-sandbox.tamara.co  |  Live: https://api.tamara.co
        'base_url' => env('TAMARA_BASE_URL', 'https://api-sandbox.tamara.co'),
    ],

    // Admin notification recipients — comma-separated emails. Used by
    // OrderController, PaymentController, WebhookController for the
    // "new order" admin notification. Set via .env; when empty, readers fall
    // back to the store email from Brand settings (AdminNotifier::adminEmails()).
    // Config can't read the DB-backed Brand here (config:cache runs without it).
    'admin_emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ADMIN_NOTIFICATION_EMAILS', ''))
    ))),

    // Hourly "system health alert" emails (SystemHealthCheck). Off by default
    // — the client doesn't want them; problems are still logged. Set
    // HEALTH_ALERT_EMAILS=true in .env to turn the emails back on.
    'health_alert_emails' => (bool) env('HEALTH_ALERT_EMAILS', false),

    /*
     * Klaviyo (email marketing). The private key is a secret and lives ONLY
     * here / in .env — never in site_settings, which is served publicly by
     * SettingsController.
     *
     * `revision` pins the Klaviyo API version. Klaviyo dates every breaking
     * change, and an un-pinned request follows the account's default, which
     * can move under us. Keep this in sync with the Klaviyo CLI's default
     * (`klaviyo --help`) so what we verify from the terminal matches what the
     * app actually sends.
     *
     * `newsletter_double_opt_in` must mirror the list's own opt-in setting in
     * Klaviyo. It does not change what we send (Klaviyo decides that from the
     * list); it only picks which confirmation message the storefront shows, so
     * we never say "you're subscribed" when Klaviyo has really sent a
     * confirmation email that is still unclicked.
     */
    'klaviyo' => [
        'private_key' => env('KLAVIYO_PRIVATE_KEY'),
        'public_key' => env('KLAVIYO_PUBLIC_KEY'),
        'newsletter_list_id' => env('KLAVIYO_NEWSLETTER_LIST_ID'),
        'newsletter_double_opt_in' => env('KLAVIYO_NEWSLETTER_DOUBLE_OPT_IN', false),
        'base_url' => env('KLAVIYO_BASE_URL', 'https://a.klaviyo.com'),
        'revision' => env('KLAVIYO_REVISION', '2026-07-15'),

        /*
         * Currency reported with order events. Deliberately the STORE currency
         * (Brand::currency() when this is unset), not the gateway currency: a
         * KSA order is charged in SAR, and mixing SAR and store-currency
         * figures into one Klaviyo metric would silently corrupt revenue totals. The amount actually charged is still sent in
         * the event properties as GatewayAmount/GatewayCurrency.
         */
        'currency' => env('KLAVIYO_CURRENCY'),

        /*
         * Prefix for every event's Klaviyo `unique_id` (its idempotency key).
         *
         * Order numbers are allocated per-database, so staging and production
         * both mint AW-1006, AW-1007 … for completely different orders. With a
         * bare order number as the key and both environments pointed at the same
         * Klaviyo account, a staging test order SILENTLY OVERWRITES the real
         * customer order that happens to share its number — wrong revenue
         * attributed to the wrong person, with no error anywhere.
         *
         * Defaults to APP_ENV so the environments can never collide. Change it
         * only with care: altering the prefix makes previously-sent events
         * un-matchable, so a re-send would duplicate them instead of updating.
         */
        'event_prefix' => env('KLAVIYO_EVENT_PREFIX', env('APP_ENV', 'local').'-'),
    ],

    'aramex' => [
        'username' => env('ARAMEX_USERNAME'),
        'password' => env('ARAMEX_PASSWORD'),
        'account_number' => env('ARAMEX_ACCOUNT_NUMBER'),
        'account_pin' => env('ARAMEX_ACCOUNT_PIN'),
        // Empty defaults on purpose: ShippingController::getSender() fills
        // blanks from the Brand settings (store name, phone, email, address).
        'account_entity' => env('ARAMEX_ACCOUNT_ENTITY', ''),
        'country_code' => env('ARAMEX_ACCOUNT_COUNTRY_CODE', ''),
        'sender_company' => env('SENDER_COMPANY_NAME', ''),
        'sender_person' => env('SENDER_PERSON_NAME', ''),
        'sender_phone' => env('SENDER_PHONE', ''),
        'sender_email' => env('SENDER_EMAIL', ''),
        'sender_address' => env('SENDER_ADDRESS_LINE1', ''),
        'sender_city' => env('SENDER_CITY', ''),
        'sender_state' => env('SENDER_STATE', ''),
        'sender_country' => env('SENDER_COUNTRY_CODE', ''),
    ],

    'topex' => [
        'username' => env('TOPEX_USERNAME'),
        'password' => env('TOPEX_PASSWORD'),
        'account_number' => env('TOPEX_ACCOUNT_NUMBER'),

    ],

    /*
     * Shared shipping settings (Topex + Aramex).
     */
    'shipping' => [
        // What goes in the AWB "Description of goods" cell.
        //
        // Deliberately generic: the AWB travels on the OUTSIDE of the parcel,
        // so printing the actual product names ("1 x Rolex Submariner") tells
        // every person who handles it that the box contains a valuable watch.
        // A neutral description keeps the courier and customs satisfied without
        // advertising the contents.
        //
        // Change with SHIPPING_GOODS_DESCRIPTION in .env — no deploy needed.
        'goods_description' => env('SHIPPING_GOODS_DESCRIPTION', 'Watches and Accessories'),

        // Topex returns every 4x6 AWB as a two-page PDF with a blank second
        // page, so printing one wasted a sheet each time. With this on, blank
        // pages are removed after download — only genuinely empty ones, so a
        // multi-piece shipment keeps every real label.
        //
        // Turn off with SHIPPING_STRIP_BLANK_LABEL_PAGES=false if a courier
        // ever needs the spare page.
        'strip_blank_label_pages' => env('SHIPPING_STRIP_BLANK_LABEL_PAGES', true),
    ],

];
