<?php
/**
 * ============================================================
 * app.php — Application Bootstrap Configuration
 * ============================================================
 * What this file does:
 *   This is where the Laravel application is set up. It
 *   configures routing (which files handle web/API/console
 *   requests), registers middleware (like the admin check),
 *   and sets up rate limiting rules to prevent abuse.
 *
 * Key things configured here:
 *   - Route file locations (web.php, api.php, console.php)
 *   - The "admin" middleware alias
 *   - Rate limits for auth, payments, and order endpoints
 * ============================================================
 */

use Illuminate\Auth\AuthenticationException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

// Build and configure the Laravel application
return Application::configure(basePath: dirname(__DIR__))
    // Tell Laravel where to find the route files
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Register middleware (code that runs before requests reach controllers)
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
        ]);

        // Web installer gate: until storage/installed exists, API calls get a
        // 503 install_required and pages redirect to /install; afterwards
        // /install is a 404. Global (api + web), first in the stack.
        // Skipped in the testing environment unless a test opts in.
        $middleware->prepend(\App\Http\Middleware\EnsureInstalled::class);

        // Behind Cloudflare / LiteSpeed / a TLS-terminating proxy, trust its
        // X-Forwarded-* headers so isSecure() and url() report https.
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '*'),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Using Bearer token auth, not stateful/session auth
        // $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Return JSON 401 for unauthenticated API requests instead of trying
        // to redirect to a non-existent /login route. We handle BOTH
        // AuthenticationException AND the RouteNotFoundException that fires
        // when guard.redirectTo() can't resolve the missing 'login' route.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });
        $exceptions->render(function (\Symfony\Component\Routing\Exception\RouteNotFoundException $e, Request $request) {
            if (str_contains($e->getMessage(), '[login]') && $request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });
    })
    // Set up rate limiting rules (prevents too many requests in a short time)
    ->booted(function () {
        // Max 10 login/signup attempts per minute per IP address
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
        // Max 5 payment attempts per minute per user (or per IP if not logged in)
        RateLimiter::for('payments', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });
        // Max 10 order creation attempts per minute per user
        RateLimiter::for('orders', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });
    })
    ->create();
