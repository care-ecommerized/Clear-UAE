<?php
/**
 * ============================================================
 * ADMIN MIDDLEWARE — Admin Role Check
 * ============================================================
 * What this file does:
 *   This middleware runs BEFORE any admin-only API route.
 *   It checks if the logged-in user has the "admin" role.
 *   If not, it blocks the request with a 403 Forbidden error.
 *
 * How it's used:
 *   In routes/api.php, admin routes are wrapped with this
 *   middleware so only admins can access them. For example:
 *   Route::middleware(['auth:sanctum', 'admin'])->group(...)
 * ============================================================
 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminMiddleware
{
    /**
     * Check if the current user is an admin.
     * If not logged in or not an admin, return a 403 error.
     * Otherwise, let the request continue to the controller.
     */
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user() || !$request->user()->isAdmin()) {
            return response()->json(['error' => 'Admin access required'], 403);
        }

        return $next($request);
    }
}
