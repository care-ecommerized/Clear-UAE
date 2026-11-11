<?php

/**
 * ============================================================
 * web.php — Web Routes (SPA Fallback)
 * ============================================================
 * What this file does:
 *   Handles all non-API web requests by serving the React
 *   frontend's index.html file. This is what makes the
 *   Single Page Application (SPA) routing work — any URL
 *   that is not an API call gets the same HTML page, and
 *   React Router handles the actual page navigation.
 *
 * Example: visiting /products or /cart in the browser
 *   will load index.html, then React shows the right page.
 * ============================================================
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Route;

/**
 * Dynamic sitemap.xml — auto-generated from the database.
 * Emits both /en/... and /ar/... URLs for every page with
 * <xhtml:link rel="alternate" hreflang=...> siblings so Google
 * groups the localized variants under one search result entry.
 */
Route::get('/sitemap.xml', function () {
    $baseUrl = rtrim((string) config('app.url'), '/');
    $locales = ['en', 'ar'];

    // Static pages — paths are locale-relative (no /en or /ar yet).
    $staticPages = [
        ['path' => '/', 'freq' => 'daily', 'priority' => '1.0'],
        ['path' => '/products', 'freq' => 'daily', 'priority' => '0.9'],
        ['path' => '/collections', 'freq' => 'weekly', 'priority' => '0.8'],
        ['path' => '/new-arrivals', 'freq' => 'daily', 'priority' => '0.8'],
        ['path' => '/deals', 'freq' => 'daily', 'priority' => '0.8'],
        ['path' => '/brands', 'freq' => 'weekly', 'priority' => '0.8'],
        ['path' => '/gifts', 'freq' => 'weekly', 'priority' => '0.7'],
        ['path' => '/blog', 'freq' => 'weekly', 'priority' => '0.7'],
        ['path' => '/about', 'freq' => 'monthly', 'priority' => '0.6'],
        ['path' => '/contact', 'freq' => 'monthly', 'priority' => '0.6'],
        // FAQ hidden — re-enable when FAQ content is ready
        // ['path' => '/faq', 'freq' => 'monthly', 'priority' => '0.5'],
        ['path' => '/shipping', 'freq' => 'monthly', 'priority' => '0.4'],
        ['path' => '/returns', 'freq' => 'monthly', 'priority' => '0.4'],
        ['path' => '/cancellation', 'freq' => 'monthly', 'priority' => '0.3'],
        ['path' => '/privacy', 'freq' => 'monthly', 'priority' => '0.3'],
        ['path' => '/terms', 'freq' => 'monthly', 'priority' => '0.3'],
        ['path' => '/track-order', 'freq' => 'monthly', 'priority' => '0.3'],
    ];

    // Match the public visibility rules — scheduled-for-future products
    // shouldn't show up in the sitemap (Search Console would 404 them
    // until their publish moment).
    // Products in an excluded category are not part of the shop, so they stay
    // out of the sitemap too. The category's own page is still listed below —
    // it is publicly linked from the header.
    $products = Product::visibleToCustomers()
        ->excludingHiddenCategories()
        ->select('slug', 'updated_at')
        ->get();
    $categories = Category::select('slug', 'updated_at')->get();
    $brands = Brand::select('slug', 'updated_at')->get();

    // Helper: build a locale-prefixed URL path.
    $localized = function (string $lang, string $path): string {
        if ($path === '/') {
            return '/'.$lang;
        }

        return '/'.$lang.$path;
    };

    // Helper: emit one <url> entry with hreflang siblings for every locale.
    $emitUrl = function (string $path, ?string $lastmod, string $freq, string $priority) use ($baseUrl, $locales, $localized): string {
        $out = '';
        foreach ($locales as $lang) {
            $loc = $baseUrl.$localized($lang, $path);
            $out .= "  <url>\n";
            $out .= "    <loc>{$loc}</loc>\n";
            if ($lastmod) {
                $out .= "    <lastmod>{$lastmod}</lastmod>\n";
            }
            $out .= "    <changefreq>{$freq}</changefreq>\n";
            $out .= "    <priority>{$priority}</priority>\n";
            foreach ($locales as $alt) {
                $altLoc = $baseUrl.$localized($alt, $path);
                $out .= "    <xhtml:link rel=\"alternate\" hreflang=\"{$alt}\" href=\"{$altLoc}\" />\n";
            }
            $defaultLoc = $baseUrl.$localized('en', $path);
            $out .= "    <xhtml:link rel=\"alternate\" hreflang=\"x-default\" href=\"{$defaultLoc}\" />\n";
            $out .= "  </url>\n";
        }

        return $out;
    };

    $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n";

    foreach ($staticPages as $page) {
        $xml .= $emitUrl($page['path'], null, $page['freq'], $page['priority']);
    }

    foreach ($products as $product) {
        $xml .= $emitUrl(
            "/product/{$product->slug}",
            $product->updated_at?->toDateString() ?? now()->toDateString(),
            'weekly',
            '0.8'
        );
    }

    foreach ($categories as $category) {
        $xml .= $emitUrl(
            "/category/{$category->slug}",
            $category->updated_at?->toDateString() ?? now()->toDateString(),
            'weekly',
            '0.7'
        );
    }

    foreach ($brands as $brand) {
        $xml .= $emitUrl(
            "/brand/{$brand->slug}",
            $brand->updated_at?->toDateString() ?? now()->toDateString(),
            'weekly',
            '0.7'
        );
    }

    foreach (\App\Models\BlogPost::published()->select('slug', 'updated_at')->get() as $post) {
        $xml .= $emitUrl(
            "/blog/{$post->slug}",
            $post->updated_at?->toDateString() ?? now()->toDateString(),
            'monthly',
            '0.6'
        );
    }

    $xml .= '</urlset>';

    return response($xml, 200, ['Content-Type' => 'application/xml']);
});

/**
 * robots.txt — served from Laravel (not a static file) so the
 * "Sitemap:" line always points at this install's own domain.
 */
Route::get('/robots.txt', function () {
    $baseUrl = rtrim((string) config('app.url'), '/');

    $disallow = implode("\n", [
        'Disallow: /admin/',
        'Disallow: /*/checkout',
        'Disallow: /*/cart',
        'Disallow: /*/order-confirmation',
        'Disallow: /*/account',
        'Disallow: /*/wishlist',
        'Disallow: /checkout',
        'Disallow: /cart',
        'Disallow: /order-confirmation',
    ]);

    $txt = "User-agent: Googlebot\nAllow: /\n{$disallow}\n\n";
    $txt .= "User-agent: Bingbot\nAllow: /\n{$disallow}\n\n";
    $txt .= "User-agent: Twitterbot\nAllow: /\n\n";
    $txt .= "User-agent: facebookexternalhit\nAllow: /\n\n";
    $txt .= "User-agent: *\nAllow: /\n{$disallow}\n\n";
    $txt .= "Sitemap: {$baseUrl}/sitemap.xml\n";

    return response($txt, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
});

/**
 * Web installer (/install). Only reachable until storage/installed exists —
 * App\Http\Middleware\EnsureInstalled returns 404 for these afterwards.
 * Must stay above the SPA catch-all below.
 */
Route::prefix('install')->controller(\App\Http\Controllers\InstallController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/requirements', 'requirements');
    Route::get('/database', 'database');
    Route::post('/database', 'saveDatabase');
    Route::get('/migrate', 'migrate');
    Route::post('/migrate', 'runMigrate');
    Route::get('/admin', 'admin');
    Route::post('/admin', 'saveAdmin');
    Route::get('/store', 'store');
    Route::post('/store', 'saveStore');
});

/**
 * Uploaded files (/storage/…) when the public_html/storage → backend/storage/
 * app/public link is missing — e.g. a host that disables symlink(). With the
 * link in place Apache serves these files itself and this never runs; without
 * it the request falls through .htaccess to Laravel, and this keeps uploaded
 * logos and product photos working instead of returning the SPA shell.
 * Must stay above the SPA catch-all below.
 */
Route::get('/storage/{path}', function (string $path) {
    $segments = explode('/', str_replace('\\', '/', $path));
    foreach ($segments as $segment) {
        // No traversal and no dotfiles (.gitignore etc.).
        if ($segment === '' || str_starts_with($segment, '.')) {
            abort(404);
        }
    }

    $disk = \Illuminate\Support\Facades\Storage::disk('public');
    if (! $disk->exists($path)) {
        abort(404);
    }

    // Shipping labels hold customer PII: never let a CDN or proxy keep them.
    $cache = str_starts_with($path, 'labels/')
        ? 'private, no-store'
        : 'public, max-age=31536000, immutable';

    return $disk->response($path, null, ['Cache-Control' => $cache]);
})->where('path', '.*')->withoutMiddleware([
    // Serving a file needs no session: without this every image/label hit
    // would start a session and write a database row.
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
]);

/**
 * /favicon.ico (routed here by .htaccess): the Branding favicon when one is
 * uploaded, so browsers, Google's favicon fetcher and link-preview bots
 * stop showing the bundled icon; otherwise the bundled file.
 */
Route::get('/favicon.ico', function () {
    try {
        $favicon = \App\Support\Brand::get('favicon_url');
    } catch (\Throwable) {
        $favicon = '';
    }
    if ($favicon !== '') {
        $target = preg_match('#^https?://#i', $favicon) ? $favicon : url('/'.ltrim($favicon, '/'));

        return redirect()->away($target, 302);
    }

    foreach ([dirname(base_path()).'/favicon.ico', public_path('favicon.ico'), base_path('../public/favicon.ico')] as $file) {
        if (is_file($file)) {
            return response()->file($file, ['Content-Type' => 'image/x-icon', 'Cache-Control' => 'public, max-age=86400']);
        }
    }

    abort(404);
})->withoutMiddleware([
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
]);

// Serve the SPA frontend for all non-API routes, with per-page SEO tags
// injected server-side (so crawlers/social scrapers get real metadata).
Route::get('/{any?}', [\App\Http\Controllers\SpaController::class, 'index'])
    ->where('any', '^(?!api).*$');
