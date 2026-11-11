<?php

/**
 * ============================================================
 * ADMIN CONTROLLER — Dashboard Stats & User Management
 * ============================================================
 * What this controller does:
 *   Provides admin-only features like viewing dashboard
 *   statistics (orders, revenue, products, customers),
 *   listing customers, resetting passwords, and managing
 *   admin roles (grant or revoke).
 *
 * API endpoints:
 *   GET    /api/admin/dashboard            — Get dashboard stats
 *   GET    /api/admin/customers            — List all customers
 *   POST   /api/admin/reset-password       — Reset a user's password
 *   POST   /api/admin/make-admin           — Give a user admin role
 *   DELETE /api/admin/remove-admin/{userId} — Remove admin role
 * ============================================================
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use App\Models\Order;
use App\Models\Product;
use App\Models\Profile;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminController extends Controller
{
    /**
     * GET /api/admin/dashboard
     *
     * Query params:
     *   from=YYYY-MM-DD  inclusive lower bound on created_at (optional)
     *   to=YYYY-MM-DD    inclusive upper bound on created_at (optional)
     *
     * Returns:
     *   - Period stats (orders/revenue) over the selected range
     *   - Prior-period stats (immediately preceding range of equal length)
     *     so the UI can compute a real trend % instead of hardcoded numbers
     *   - statusCounts keyed by lifecycle status for the bar chart
     *   - recentOrders within the range (most-recent 10)
     *   - all-time totals (products, customers) — not range-dependent
     */
    public function dashboard(Request $request)
    {
        // Default to last 30 days if no range is supplied. Cap +1 day on
        // `to` so the same calendar day on both sides of the picker is a
        // legitimate 1-day range (createdAt < to_exclusive).
        $to = $request->query('to')
            ? \Carbon\Carbon::parse($request->query('to'))->endOfDay()
            : \Carbon\Carbon::now()->endOfDay();
        $from = $request->query('from')
            ? \Carbon\Carbon::parse($request->query('from'))->startOfDay()
            : \Carbon\Carbon::now()->subDays(29)->startOfDay();

        // Prior period of equal length, immediately preceding [from, to].
        $periodSeconds = $to->getTimestamp() - $from->getTimestamp();
        $priorTo = (clone $from)->subSecond();
        $priorFrom = (clone $priorTo)->subSeconds($periodSeconds);

        $inRange = fn ($q) => $q->whereBetween('created_at', [$from, $to]);
        $inPriorRange = fn ($q) => $q->whereBetween('created_at', [$priorFrom, $priorTo]);

        // Per-status order counts within the range. The DB stores statuses
        // as free-form strings; we explicitly enumerate so the response
        // shape is stable even when one bucket is empty.
        $buckets = ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'];
        $rawCounts = Order::query()
            ->tap($inRange)
            ->select('status', DB::raw('COUNT(*) as c'))
            ->groupBy('status')
            ->pluck('c', 'status')
            ->toArray();
        $statusCounts = [];
        foreach ($buckets as $b) {
            $statusCounts[$b] = (int) ($rawCounts[$b] ?? 0);
        }

        // Period totals.
        $periodOrders = (int) Order::query()->tap($inRange)->count();
        $periodRealized = (float) Order::query()
            ->tap($inRange)
            ->where('payment_status', 'paid')
            ->sum('total');

        // Prior-period totals for trend %.
        $priorOrders = (int) Order::query()->tap($inPriorRange)->count();
        $priorRealized = (float) Order::query()
            ->tap($inPriorRange)
            ->where('payment_status', 'paid')
            ->sum('total');

        // Recent orders within the range, newest first, top 10.
        $recentOrders = Order::query()
            ->tap($inRange)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get([
                'id', 'order_number', 'customer_name', 'customer_email',
                'status', 'payment_status', 'total', 'created_at',
            ]);

        return response()->json([
            // All-time
            'totalProducts' => Product::where('is_active', true)->count(),
            'totalCustomers' => (int) Order::distinct('customer_email')->count('customer_email'),

            // Within selected range
            'totalOrders' => $periodOrders,
            'totalRevenue' => $periodRealized,
            'realizedSales' => $periodRealized,
            'statusCounts' => $statusCounts,
            'recentOrders' => $recentOrders,

            // Prior-period comparison (frontend computes %)
            'priorOrders' => $priorOrders,
            'priorRevenue' => $priorRealized,

            // Echo back the resolved range so the UI can show what was queried
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
        ]);
    }

    /**
     * GET /api/admin/customers
     * Returns a list of all customers who have placed orders.
     * Groups orders by email so each customer appears once,
     * with their total order count and total amount spent.
     */
    public function customers()
    {
        // Source 1: customers built from real orders (has order stats).
        // Source 2: registered/imported users with no orders yet
        // (zeros for stats — surfaced so admins see their imports).
        $orders = Order::select([
            'customer_email', 'customer_name', 'customer_phone',
            'shipping_address', 'shipping_city', 'shipping_country',
            'status', 'total', 'created_at',
        ])
            ->whereNotNull('customer_email')
            ->orderByDesc('created_at')
            ->get();

        $grouped = [];
        foreach ($orders as $o) {
            $email = $o->customer_email;
            if (! isset($grouped[$email])) {
                $grouped[$email] = [
                    'id' => md5($email),
                    'email' => $email,
                    'full_name' => $o->customer_name,
                    'phone' => $o->customer_phone,
                    'addresses' => [],
                    'cities' => [],
                    'countries' => [],
                    'first_order_at' => $o->created_at,
                    'last_order_at' => $o->created_at,
                    'order_count' => 0,
                    'delivered_count' => 0,
                    'total_spent' => 0.0,
                ];
            }
            $g = &$grouped[$email];
            $g['order_count']++;
            $g['total_spent'] += (float) $o->total;
            if ($o->status === 'delivered') {
                $g['delivered_count']++;
            }

            // Compose a one-line address from the order's shipping fields.
            // Multiple distinct addresses for the same customer are collected.
            $addressParts = array_filter([
                $o->shipping_address,
                $o->shipping_city,
            ], fn ($p) => ! empty(trim((string) $p)));
            $address = trim(implode(', ', $addressParts));
            if ($address !== '' && ! in_array($address, $g['addresses'], true)) {
                $g['addresses'][] = $address;
            }

            if (! empty($o->shipping_city) && ! in_array($o->shipping_city, $g['cities'], true)) {
                $g['cities'][] = $o->shipping_city;
            }
            if (! empty($o->shipping_country) && ! in_array($o->shipping_country, $g['countries'], true)) {
                $g['countries'][] = $o->shipping_country;
            }
            if ($o->created_at < $g['first_order_at']) {
                $g['first_order_at'] = $o->created_at;
            }
            if ($o->created_at > $g['last_order_at']) {
                $g['last_order_at'] = $o->created_at;
            }
            unset($g);
        }

        // Pull registered users (incl. CSV imports) who don't already
        // appear from the orders pass. They show with zero stats.
        // Exclude admins — they're staff, not customers.
        $adminUserIds = \DB::table('user_roles')->where('role', 'admin')->pluck('user_id');
        $registeredUsers = User::with('profile')
            ->whereNotIn('id', $adminUserIds)
            ->select(['id', 'name', 'email', 'created_at'])
            ->orderByDesc('created_at')
            ->get();

        foreach ($registeredUsers as $u) {
            $email = strtolower($u->email);
            if (isset($grouped[$email])) {
                continue;
            }
            $grouped[$email] = [
                'id' => md5($email),
                'email' => $email,
                'full_name' => $u->name,
                'phone' => $u->profile->phone ?? null,
                'addresses' => array_values(array_filter([implode(', ', array_filter([$u->profile->address ?? null, $u->profile->city ?? null]))])),
                'cities' => array_values(array_filter([$u->profile->city ?? null])),
                'countries' => array_values(array_filter([$u->profile->country ?? null])),
                'first_order_at' => $u->created_at,
                'last_order_at' => null,
                'order_count' => 0,
                'delivered_count' => 0,
                'total_spent' => 0.0,
            ];
        }

        // Sort: customers with recent orders first, then anyone else
        // (registered/imported but no orders) by signup date desc.
        $list = array_values($grouped);
        usort($list, function ($a, $b) {
            $hasOrders = ($b['order_count'] > 0) <=> ($a['order_count'] > 0);
            if ($hasOrders !== 0) {
                return $hasOrders;
            }

            $aKey = $a['last_order_at'] ?? $a['first_order_at'];
            $bKey = $b['last_order_at'] ?? $b['first_order_at'];

            return strcmp((string) $bKey, (string) $aKey);
        });

        // Backwards-compat: keep `created_at` for any existing consumer.
        foreach ($list as &$row) {
            $row['created_at'] = $row['first_order_at'];
        }

        return response()->json($list);
    }

    /**
     * GET /api/admin/customers/orders?email=...
     * Returns this customer's order history with line items so the admin
     * can see what products they bought (with prices and dates) — used by
     * the "View purchases" drilldown on the Customers list.
     */
    public function customerOrders(\Illuminate\Http\Request $request)
    {
        $email = trim((string) $request->query('email', ''));
        if ($email === '') {
            return response()->json([]);
        }

        $orders = Order::with(['items:id,order_id,product_id,product_name,product_image,quantity,unit_price,total_price'])
            ->where('customer_email', $email)
            ->orderByDesc('created_at')
            ->get([
                'id', 'order_number', 'status', 'total', 'subtotal',
                'shipping_cost', 'created_at',
            ]);

        return response()->json($orders);
    }

    /**
     * GET /api/admin/customers/export-csv
     * Streams the aggregated customer list (one row per unique
     * customer_email from orders) as a UTF-8 CSV with a BOM so it
     * opens cleanly in Excel.
     */
    public function exportCustomersCsv(): StreamedResponse
    {
        // Reuse the same aggregation as customers() so the CSV mirrors
        // what's on screen.
        $payload = $this->customers()->getData(true);

        return new StreamedResponse(function () use ($payload) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            // Canonical column order — matches the on-screen table and
            // the import template exactly.
            fputcsv($handle, [
                'Name', 'Email', 'Phone Number', 'Address', 'Countries',
                'Orders', 'Total Spent', 'Last Order Date',
            ]);

            foreach ($payload as $r) {
                // Excel mangles long phone numbers into scientific
                // notation (9.66533E+11). Wrapping the value in
                // `="..."` is the standard CSV-for-Excel hack that
                // forces it to stay as a literal string on open.
                $phoneRaw = (string) ($r['phone'] ?? '');
                $phoneCell = $phoneRaw !== '' ? '="'.$phoneRaw.'"' : '';

                fputcsv($handle, [
                    $r['full_name'] ?? '',
                    $r['email'],
                    $phoneCell,
                    implode(' | ', $r['addresses'] ?? []),
                    implode(' | ', $r['countries'] ?? []),
                    (int) ($r['order_count'] ?? 0),
                    number_format((float) ($r['total_spent'] ?? 0), 2, '.', ''),
                    ! empty($r['last_order_at']) ? date('Y-m-d', strtotime($r['last_order_at'])) : '',
                ]);
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="customers-export-'.date('Y-m-d').'.csv"',
            'Cache-Control' => 'no-cache, no-store',
        ]);
    }

    /**
     * GET /api/admin/customers/csv-template
     * Downloads a blank CSV template that matches the importer's
     * expected columns. Includes a sample row.
     */
    public function customersCsvTemplate(): StreamedResponse
    {
        return new StreamedResponse(function () {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            // Same column order as the export. Orders / Total Spent /
            // Last Order Date are read-only on import (derived from
            // real orders) but included so re-importing an exported
            // file Just Works.
            fputcsv($handle, [
                'Name', 'Email', 'Phone Number', 'Address', 'Countries',
                'Orders', 'Total Spent', 'Last Order Date',
            ]);
            fputcsv($handle, [
                'Jane Doe', 'jane@example.com', '+971501234567',
                'Villa 12, Al Wasl Rd, Dubai', 'United Arab Emirates',
                '0', '0.00', '',
            ]);

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="customers-csv-template.csv"',
            'Cache-Control' => 'no-cache, no-store',
        ]);
    }

    /**
     * POST /api/admin/customers/import-csv
     * Bulk-creates User accounts from a CSV with columns:
     * Name, Email, Phone Number, Address, Countries (other columns
     * are accepted for round-trip compatibility but ignored — Orders,
     * Total Spent, Last Order Date are derived from real orders).
     * Rows whose email already exists as a User are updated
     * (name/phone); missing rows are created with a random password
     * (admin must use the "Reset Password" flow before the user can
     * log in). The Customers list is built from orders, so newly
     * imported users will only appear there once they place an order.
     */
    public function importCustomersCsv(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        // A 5MB CSV can be thousands of rows, so the default 30s
        // PHP execution cap is too low for the bcrypt loop below.
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $rawHeaders = fgetcsv($handle, 0, ',', '"', '') ?: [];
        $records = [];
        while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($cells !== [null]) {
                $records[] = $cells;
            }
        }
        fclose($handle);

        if (! $rawHeaders || ! $records) {
            return response()->json(['message' => 'CSV file is empty or has no data rows.'], 422);
        }

        $rawHeaders[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $rawHeaders[0]);
        $rawHeaders = array_map('trim', $rawHeaders);

        // Normalize headers so the importer accepts both the new
        // canonical names ("Name", "Phone Number") and the legacy
        // snake_case names ("full_name", "phone") — round-trip safe.
        $headerMap = [
            'name' => 'name',
            'full_name' => 'name',
            'email' => 'email',
            'phone' => 'phone',
            'phone_number' => 'phone',
            'phone#' => 'phone',
        ];
        $headers = array_map(function ($h) use ($headerMap) {
            $key = strtolower(str_replace([' ', '-'], '_', trim($h)));
            $key = str_replace('__', '_', $key);

            return $headerMap[$key] ?? $key;
        }, $rawHeaders);

        if (! in_array('email', $headers, true)) {
            return response()->json([
                'message' => 'Missing required CSV column: Email',
            ], 422);
        }

        $stats = [
            'users_created' => 0,
            'users_updated' => 0,
            'rows_skipped' => 0,
            'errors' => [],
        ];

        // Pre-compute a single throwaway bcrypt hash and reuse it for
        // every newly-imported user. These accounts cannot log in
        // without Reset Password (admin re-hashes there at full cost),
        // so per-row hashing would just waste 100ms+ per row and trip
        // the 30s PHP execution cap on larger imports.
        $placeholderHash = Hash::make(Str::random(32));

        DB::transaction(function () use ($records, $headers, &$stats, $placeholderHash) {
            foreach ($records as $index => $values) {
                $rowNumber = $index + 2;

                if (count($values) !== count($headers)) {
                    $stats['errors'][] = "Row {$rowNumber}: column count mismatch";

                    continue;
                }

                $row = array_map('trim', array_combine($headers, $values));
                $email = strtolower($row['email'] ?? '');

                if (empty($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $stats['rows_skipped']++;
                    $stats['errors'][] = "Row {$rowNumber}: invalid or missing email";

                    continue;
                }

                // Shopify's export splits the name and keeps the address
                // and a second phone under "Default Address" columns.
                $name = ($row['name'] ?? '') ?: trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? ''));
                $phone = $this->normalizePhone(($row['phone'] ?? '') ?: ($row['default_address_phone'] ?? ''));
                $details = array_filter([
                    'phone' => $phone,
                    'address' => mb_substr(implode(', ', array_filter([$row['default_address_address1'] ?? '', $row['default_address_address2'] ?? ''])), 0, 500),
                    'city' => $row['default_address_city'] ?? '',
                    'country' => $row['default_address_country_code'] ?? '',
                ]);

                $user = User::where('email', $email)->first();
                if ($user) {
                    if ($name !== '') {
                        $user->name = $name;
                    }
                    $user->save();

                    if ($details) {
                        Profile::updateOrCreate(['user_id' => $user->id], $details);
                    }
                    $stats['users_updated']++;
                } else {
                    $user = User::create([
                        'name' => $name !== '' ? $name : explode('@', $email)[0],
                        'email' => $email,
                        'password' => $placeholderHash,
                    ]);

                    if ($details) {
                        Profile::create(['user_id' => $user->id] + $details);
                    }
                    $stats['users_created']++;
                }
            }
        });

        return response()->json($stats);
    }

    /**
     * Normalizes a phone string. Excel/Google Sheets often turn long
     * numbers into scientific notation (e.g. "9.66533E+11" instead of
     * "966533123456"). When we see that pattern we expand it back into
     * an integer string. Otherwise we return the input unchanged.
     */
    private function normalizePhone(string $phone): string
    {
        $phone = ltrim(trim($phone), "' ");
        if ($phone === '') {
            return '';
        }
        // Strip the Excel-safe wrapper our exporter emits (="...").
        if (preg_match('/^="(.*)"$/', $phone, $m)) {
            $phone = $m[1];
        }
        // Match "9.66533E+11", "9.66533e+11", optionally negative.
        if (preg_match('/^-?\d+(\.\d+)?[eE][+-]?\d+$/', $phone)) {
            return number_format((float) $phone, 0, '.', '');
        }

        return $phone;
    }

    /**
     * POST /api/admin/reset-password
     * Resets a user's password and logs them out of all sessions.
     * Requires: email, newPassword (min 8 characters).
     */
    public function resetPassword(Request $request)
    {
        $request->validate(['email' => 'required|email', 'newPassword' => 'required|min:8|max:100']);

        $user = User::where('email', strtolower($request->email))->firstOrFail();
        $user->update(['password' => Hash::make($request->newPassword)]);
        $user->tokens()->delete(); // Invalidate all sessions

        return response()->json(['success' => true, 'message' => 'Password reset successfully']);
    }

    /**
     * POST /api/admin/change-password
     * Lets the currently logged-in admin change their own password.
     * Requires the current password for verification. Revokes all
     * other sessions but keeps the current token alive so the admin
     * stays signed in on this device.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'currentPassword' => 'required|string',
            'newPassword' => 'required|string|min:8|max:100|different:currentPassword',
        ]);

        $user = $request->user();

        if (! Hash::check($request->currentPassword, $user->password)) {
            return response()->json(['error' => 'Current password is incorrect'], 422);
        }

        $user->update(['password' => Hash::make($request->newPassword)]);

        $currentTokenId = $request->user()->currentAccessToken()?->id;
        if ($currentTokenId) {
            $user->tokens()->where('id', '!=', $currentTokenId)->delete();
        }

        return response()->json(['success' => true, 'message' => 'Password changed successfully']);
    }

    /**
     * POST /api/admin/make-admin
     * Gives a user the "admin" role so they can access the admin panel.
     * Returns an error if the user is already an admin.
     */
    public function makeAdmin(Request $request)
    {
        $request->validate(['userId' => 'required']);

        $exists = UserRole::where('user_id', $request->userId)->where('role', 'admin')->exists();
        if ($exists) {
            return response()->json(['error' => 'User is already an admin'], 400);
        }

        UserRole::create(['user_id' => $request->userId, 'role' => 'admin']);

        return response()->json(['success' => true, 'message' => 'Admin role assigned']);
    }

    /**
     * DELETE /api/admin/remove-admin/{userId}
     * Removes the "admin" role from a user.
     * You cannot remove your own admin role (safety check).
     */
    public function removeAdmin(Request $request, string $userId)
    {
        if ($userId === (string) $request->user()->id) {
            return response()->json(['error' => 'Cannot remove your own admin role'], 400);
        }

        UserRole::where('user_id', $userId)->where('role', 'admin')->delete();

        return response()->json(['success' => true, 'message' => 'Admin role removed']);
    }

    /**
     * GET /api/admin/notifications
     * Returns the latest 50 admin notifications, each annotated with
     * is_read for the current admin (per-user read state via read_by).
     */
    public function notifications(Request $request): JsonResponse
    {
        $userId = (string) $request->user()->id;

        $rows = AdminNotification::orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (AdminNotification $n) => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'message' => $n->message,
                'link' => $n->link,
                'data' => $n->data,
                'is_read' => $n->isReadBy($userId),
                'created_at' => $n->created_at,
            ]);

        return response()->json(['notifications' => $rows]);
    }

    /**
     * GET /api/admin/notifications/unread-count
     * Cheap endpoint polled every few seconds by the bell to update
     * the red dot. Counts notifications whose read_by JSON does not
     * contain the current admin's user id.
     */
    public function unreadNotificationCount(Request $request): JsonResponse
    {
        $userId = (string) $request->user()->id;

        $count = AdminNotification::where(function ($q) use ($userId) {
            $q->whereNull('read_by')
                ->orWhereJsonDoesntContain('read_by', $userId);
        })->count();

        return response()->json(['count' => $count]);
    }

    /**
     * POST /api/admin/notifications/{id}/read
     * Marks a single notification as read for the current admin.
     */
    public function markNotificationRead(Request $request, string $id): JsonResponse
    {
        $notification = AdminNotification::findOrFail($id);
        $notification->markReadBy((string) $request->user()->id);

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/admin/notifications/read-all
     * Marks every existing notification as read for the current admin.
     */
    public function markAllNotificationsRead(Request $request): JsonResponse
    {
        $userId = (string) $request->user()->id;

        AdminNotification::where(function ($q) use ($userId) {
            $q->whereNull('read_by')
                ->orWhereJsonDoesntContain('read_by', $userId);
        })->get()->each(fn (AdminNotification $n) => $n->markReadBy($userId));

        return response()->json(['success' => true]);
    }
}
