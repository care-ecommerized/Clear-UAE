<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Display Timezone
    |--------------------------------------------------------------------------
    |
    | Timestamps are STORED in UTC (above) — that stays, it is what keeps
    | date comparisons correct. This is the timezone they are rendered in for
    | humans: emails, PDF invoices, anything a person reads.
    |
    | Without it, a Carbon instance formats as UTC, so an order placed at
    | 03:06 local time (UTC+4) on the 19th went out in the admin email as
    | "18 Jul, 11:06 PM" — four hours earlier, on the previous day.
    |
    | Defaults to UTC. The store's Branding setting (timezone) overrides it at
    | runtime in AppServiceProvider, so a store owner sets it from the admin.
    |
    */

    'display_timezone' => env('DISPLAY_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Invoice Date Cutover
    |--------------------------------------------------------------------------
    |
    | The printed invoice number embeds the order date (AD{YYYYMMDD}-{ref}).
    | Invoices are rendered on demand, so switching that date from UTC to the
    | display timezone would also renumber OLD orders — anything placed in the
    | hours where the two dates differ would reprint with a number that no
    | longer matches the copy already sent to the customer.
    |
    | So the local date applies only to orders created from this instant on.
    | Older orders keep rendering exactly as they were issued. Stored as UTC,
    | since that is how created_at is compared. A fresh install has no
    | earlier invoices, so the default applies the local date to every order.
    |
    | Orders that record their own invoice_date (set when the order is
    | created) always use it; this cut-off only matters for older rows.
    |
    */

    'invoice_local_dates_from' => env('INVOICE_LOCAL_DATES_FROM', '1970-01-01 00:00:00'),

    /*
    |--------------------------------------------------------------------------
    | Checkout Reservation Window (minutes)
    |--------------------------------------------------------------------------
    |
    | How long a pending checkout holds its reserved stock before the sweeper
    | marks it abandoned and puts the stock back. Shorter frees stock for other
    | customers sooner; too short and a slow-but-genuine payer (Tabby/Tamara
    | app switching, 3-D Secure OTP) gets expired mid-payment.
    |
    | The sweeper runs every minute, so the real worst case is this + 1 minute.
    |
    */

    'checkout_reservation_minutes' => (int) env('CHECKOUT_RESERVATION_MINUTES', 10),

    /*
    | The storefront's origin (CORS, and the hosts payment return URLs may
    | point at). Read through config so it survives `php artisan config:cache`.
    */

    'frontend_url' => env('FRONTEND_URL'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
