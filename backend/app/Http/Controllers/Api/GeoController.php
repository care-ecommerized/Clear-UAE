<?php

/**
 * ============================================================
 * GeoController — Visitor country lookup
 * ============================================================
 * Tells the storefront which country a visitor is browsing from
 * so it can preselect their country and show prices in their own
 * currency.
 *
 * The site sits behind Cloudflare, which resolves the country of
 * the real connecting IP and passes it as the CF-IPCountry header
 * on EVERY request. That is free, instant, has no rate limit, and
 * — unlike a browser-side lookup — cannot be blocked by an ad
 * blocker or rejected for coming from a datacenter IP.
 *
 * The frontend previously called ipapi.co directly from the
 * browser. Its free tier rate-limits hard and commonly rejects
 * datacenter IPs (i.e. anyone on a VPN), and every rejection fell
 * back to "UAE" — indistinguishable from a real UAE visitor.
 * ipapi.co is still used client-side, but only if this fails.
 * ============================================================
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeoController extends Controller
{
    /**
     * Cloudflare uses these for requests it cannot place — treat them as
     * "unknown" rather than handing the frontend a bogus country code.
     * XX = unknown / T1 = Tor exit node.
     */
    private const NON_COUNTRY_CODES = ['XX', 'T1', ''];

    public function country(Request $request): JsonResponse
    {
        $code = strtoupper(trim((string) $request->header('CF-IPCountry', '')));

        $known = ! in_array($code, self::NON_COUNTRY_CODES, true)
            && preg_match('/^[A-Z]{2}$/', $code) === 1;

        return response()->json([
            'country_code' => $known ? $code : null,
            // Lets the frontend (and us, when debugging) tell "Cloudflare says
            // UAE" apart from "we have no idea, defaulting to UAE".
            'source' => $known ? 'cloudflare' : 'unavailable',
        ])->header('Cache-Control', 'no-store, private');
    }
}
