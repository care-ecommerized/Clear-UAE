<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NewsletterSubscriber;
use App\Services\Klaviyo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * ============================================================
 * NEWSLETTER CONTROLLER — Storefront email signups
 * ============================================================
 * Backs the newsletter form on the homepage. Before this existed
 * the form threw the address away without sending it anywhere.
 *
 * API endpoint:
 *   POST /api/newsletter/subscribe  — public, throttled
 * ============================================================
 */
class NewsletterController extends Controller
{
    /**
     * POST /api/newsletter/subscribe
     *
     * Runs synchronously rather than on the queue (unlike order emails) because
     * the customer is waiting on the answer: a queued job could not tell them
     * their address was rejected, and would show "subscribed" for a signup that
     * never happened. Klaviyo answers in ~200ms and the client is capped at an
     * 8s timeout, so the request cannot hang. This is not in the checkout path.
     */
    public function subscribe(Request $request, Klaviyo $klaviyo)
    {
        $data = $request->validate([
            'email' => 'required|email:rfc|max:180',
            // Which form on the site sent this, so Klaviyo can segment by
            // placement later (homepage vs footer vs popup).
            'source' => 'nullable|string|max:60',
        ]);

        $email = strtolower(trim($data['email']));

        // Saved first, so the store keeps the address whatever Klaviyo does.
        NewsletterSubscriber::firstOrCreate(['email' => $email], ['source' => $data['source'] ?? null]);

        if (! $klaviyo->newsletterEnabled()) {
            return response()->json(['subscribed' => true, 'double_opt_in' => false]);
        }

        if (! $klaviyo->subscribeToNewsletter($email, $data['source'] ?? null)) {
            // The admin list has the address; only the Klaviyo copy is missing.
            Log::warning('Newsletter signup saved locally but Klaviyo rejected it', ['email' => $email]);

            return response()->json(['subscribed' => true, 'double_opt_in' => false]);
        }

        return response()->json([
            'subscribed' => true,
            // Tells the storefront which confirmation to show: with double
            // opt-in the customer is NOT subscribed until they click the
            // confirmation email, and saying otherwise would be a lie.
            'double_opt_in' => $klaviyo->newsletterUsesDoubleOptIn(),
        ]);
    }

    /** GET /api/admin/newsletter-subscribers — newest first (admin). */
    public function index()
    {
        return response()->json(NewsletterSubscriber::orderByDesc('created_at')->get(['id', 'email', 'source', 'created_at']));
    }

    /** DELETE /api/admin/newsletter-subscribers/{id} — e.g. an unsubscribe request (admin). */
    public function destroy(string $id)
    {
        NewsletterSubscriber::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }
}
