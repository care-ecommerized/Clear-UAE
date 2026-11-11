<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ContactFormSubmission;
use App\Mail\ContactReply;
use App\Models\ContactMessage;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Services\AdminNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    /**
     * Is the product-page enquiry form switched on?
     *
     * Opt-in (must be exactly 'true'), so the feature stays dark everywhere it
     * has not been deliberately enabled — the same shape as
     * PaymentController::codEnabled(). site_settings lives in each
     * environment's own database, so this is on in staging while production
     * is untouched, with no rebuild.
     */
    private function productInquiryEnabled(): bool
    {
        return \App\Support\Brand::setting('product_inquiry_enabled') === 'true';
    }

    /**
     * POST /api/contact (public, throttled).
     * Stores the submission and emails the admin recipients.
     *
     * Doubles as the product-enquiry endpoint: when product_id is present the
     * message is tied to that watch and shows up in the same admin inbox.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:180',
            // Required for a product enquiry (these are high-value leads on
            // single-piece stock and the reply usually happens by phone or
            // WhatsApp), optional for the general contact form, which shares
            // this endpoint.
            'phone' => [$request->filled('product_id') ? 'required' : 'nullable', 'string', 'max:50'],
            'subject' => 'required|string|max:120',
            'message' => 'required|string|max:5000',
            // Product enquiry (all optional — absent for the normal contact page).
            'product_id' => 'nullable|uuid',
            'product_name' => 'nullable|string|max:255',
            'product_sku' => 'nullable|string|max:255',
            'product_url' => 'nullable|string|max:1024',
            // Honeypot — see below.
            'company' => 'nullable|string|max:255',
        ]);

        $isProductInquiry = ! empty($data['product_id']);

        // Gate only the product path, so turning the feature off can never
        // break the ordinary /contact page.
        if ($isProductInquiry && ! $this->productInquiryEnabled()) {
            return response()->json([
                'success' => false,
                'error' => 'Product inquiries are not currently available.',
            ], 403);
        }

        // Honeypot: a field hidden from real users. Anything that fills it is a
        // bot, so answer 201 exactly like a success — telling a spammer it was
        // rejected just invites them to adapt. Nothing is stored or emailed.
        if (! empty($data['company'])) {
            Log::info('Contact form honeypot triggered', ['ip' => $request->ip()]);

            return response()->json(['success' => true], 201);
        }

        // Trust the server for the product's own details rather than whatever
        // the browser posted — the client is free to send any name/price it
        // likes. Fall back to the submitted values only if the row is gone.
        $product = $isProductInquiry ? Product::find($data['product_id']) : null;

        $msg = ContactMessage::create([
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
            'phone' => isset($data['phone']) ? trim($data['phone']) : null,
            'subject' => trim($data['subject']),
            'message' => trim($data['message']),
            'ip_address' => $request->ip(),
            'is_read' => false,
            'product_id' => $product?->id ?? ($isProductInquiry ? $data['product_id'] : null),
            'product_name' => $product?->name ?? ($data['product_name'] ?? null),
            'product_sku' => $product?->sku ?? ($data['product_sku'] ?? null),
            'product_url' => $data['product_url'] ?? null,
        ]);

        if ($isProductInquiry) {
            AdminNotifier::productInquiry($msg);
        }

        $recipients = \App\Services\AdminNotifier::adminEmails();
        if (! empty($recipients)) {
            // Queue rather than send synchronously — same pattern as order
            // notifications so the POST returns fast and SMTP work happens
            // in the scheduler's queue:work worker (after-response on
            // LiteSpeed is unreliable).
            dispatch(function () use ($msg, $recipients) {
                try {
                    Mail::to($recipients)->send(new ContactFormSubmission($msg));
                } catch (\Throwable $e) {
                    Log::error('Failed to send contact form notification: '.$e->getMessage(), [
                        'contact_id' => $msg->id,
                        'recipients' => $recipients,
                    ]);
                }
            });
        } else {
            Log::warning('Contact form notification skipped: no admin email set (ADMIN_NOTIFICATION_EMAILS or store email)', [
                'contact_id' => $msg->id,
            ]);
        }

        return response()->json(['success' => true, 'id' => $msg->id], 201);
    }

    /**
     * GET /api/admin/contact-messages (admin).
     */
    public function index()
    {
        // Eager-load so the product pill on each row doesn't fire a query per message.
        $messages = ContactMessage::with('product:id,name,slug,sku')
            ->orderByDesc('created_at')->limit(500)->get();
        $unread = ContactMessage::where('is_read', false)->count();

        return response()->json([
            'data' => $messages,
            'unread_count' => $unread,
        ]);
    }

    /**
     * PATCH /api/admin/contact-messages/{id}/read (admin).
     */
    public function markRead(string $id)
    {
        $msg = ContactMessage::findOrFail($id);
        $msg->is_read = true;
        $msg->save();

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/admin/contact-messages/{id}/reply (admin).
     * Emails the original sender, stamps replied_at + reply_message,
     * and marks the thread as read.
     */
    public function reply(Request $request, string $id)
    {
        $data = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $msg = ContactMessage::findOrFail($id);
        $body = trim($data['body']);

        // Send synchronously so the admin gets immediate success/failure feedback.
        // SMTP from the CLI/HTTP context works fine; we don't need to queue this.
        try {
            Mail::to($msg->email)->send(new ContactReply($msg, $body));
        } catch (\Throwable $e) {
            Log::error('Failed to send contact reply: '.$e->getMessage(), [
                'contact_id' => $msg->id,
                'recipient' => $msg->email,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to send the reply email. '.$e->getMessage(),
            ], 500);
        }

        $msg->reply_message = $body;
        $msg->replied_at = now();
        $msg->is_read = true;
        $msg->save();

        return response()->json(['success' => true, 'message' => $msg]);
    }

    /**
     * DELETE /api/admin/contact-messages/{id} (admin).
     */
    public function destroy(string $id)
    {
        $msg = ContactMessage::findOrFail($id);
        $msg->delete();

        return response()->json(['success' => true]);
    }
}
