<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // On cPanel, public_html is separate from the Laravel app directory.
        // Override the public path if the CPANEL_PUBLIC_PATH env is set.
        if ($publicPath = env('CPANEL_PUBLIC_PATH')) {
            $this->app->usePublicPath($publicPath);
        }

        // An https store keeps url()/asset() output (upload URLs, form
        // actions) on https even when a proxy strips X-Forwarded-Proto.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        $this->applyBrandSettings();
    }

    /**
     * Let Admin → Theme → Branding override the .env mail sender and the
     * display timezone, so buyers on shared hosting never have to edit .env.
     */
    private function applyBrandSettings(): void
    {
        $brand = \App\Support\Brand::all();

        if (filter_var($brand['mail_from_address'], FILTER_VALIDATE_EMAIL)) {
            config(['mail.from.address' => $brand['mail_from_address']]);
        }
        config(['mail.from.name' => $brand['mail_from_name'] !== '' ? $brand['mail_from_name'] : $brand['store_name']]);

        if ($brand['timezone'] !== '' && in_array($brand['timezone'], timezone_identifiers_list(), true)) {
            config(['app.display_timezone' => $brand['timezone']]);
        }
    }
}
