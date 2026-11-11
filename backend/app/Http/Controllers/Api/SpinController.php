<?php

/**
 * ============================================================
 * SPIN CONTROLLER — Spin to Win popup
 * ============================================================
 * The prize is always picked here on the server, then the wheel
 * animates to it. The browser is only told the segment labels, so
 * the odds stay private (SPIN_ settings are also hidden from the
 * public settings endpoint).
 *
 * API endpoints:
 *   GET /api/spin/config   — Popup copy and wheel labels
 *   POST /api/spin         — Spin once for an email
 *   GET /api/admin/spin    — Settings and latest spins (admin)
 *   PUT /api/admin/spin    — Save settings (admin)
 * ============================================================
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Discount;
use App\Models\SiteSetting;
use App\Models\SpinResult;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SpinController extends Controller
{
    private const SETTING_KEY = 'SPIN_CONFIG';

    private const DEFAULTS = [
        'enabled' => false,
        'heading' => 'Spin to Win!',
        'heading_ar' => '',
        'subheading' => 'Try your luck and get a discount',
        'subheading_ar' => '',
        'delay_seconds' => 10,
        'valid_days' => 7,
        'segments' => [
            ['label' => '5%', 'type' => 'percentage', 'value' => 5, 'min_order' => null, 'weight' => 50],
            ['label' => '10%', 'type' => 'percentage', 'value' => 10, 'min_order' => null, 'weight' => 25],
            ['label' => '15%', 'type' => 'percentage', 'value' => 15, 'min_order' => null, 'weight' => 12],
            ['label' => '20%', 'type' => 'percentage', 'value' => 20, 'min_order' => null, 'weight' => 6],
            ['label' => '25%', 'type' => 'percentage', 'value' => 25, 'min_order' => null, 'weight' => 3],
            ['label' => '30%', 'type' => 'percentage', 'value' => 30, 'min_order' => null, 'weight' => 2],
            ['label' => '40%', 'type' => 'percentage', 'value' => 40, 'min_order' => null, 'weight' => 1],
            ['label' => '50%', 'type' => 'percentage', 'value' => 50, 'min_order' => null, 'weight' => 1],
        ],
    ];

    private function config(): array
    {
        $saved = json_decode((string) SiteSetting::where('key', self::SETTING_KEY)->value('value'), true);

        return is_array($saved) ? array_merge(self::DEFAULTS, $saved) : self::DEFAULTS;
    }

    public function publicConfig()
    {
        $c = $this->config();
        if (! $c['enabled']) {
            return response()->json(['enabled' => false]);
        }

        return response()->json([
            'enabled' => true,
            'heading' => $c['heading'],
            'heading_ar' => $c['heading_ar'],
            'subheading' => $c['subheading'],
            'subheading_ar' => $c['subheading_ar'],
            'delay_seconds' => $c['delay_seconds'],
            'valid_days' => $c['valid_days'],
            'segments' => array_map(fn ($s) => ['label' => $s['label']], $c['segments']),
        ]);
    }

    public function spin(Request $request)
    {
        $c = $this->config();
        if (! $c['enabled']) {
            return response()->json(['error' => 'not_available'], 404);
        }

        $validated = $request->validate(['email' => 'required|email:rfc|max:255']);
        $email = Str::lower(trim($validated['email']));

        if (SpinResult::where('email', $email)->exists()) {
            return response()->json(['error' => 'already_spun'], 409);
        }

        $index = $this->pickSegment($c['segments']);
        $segment = $c['segments'][$index];
        $code = 'SPIN-'.Str::upper(Str::random(8));

        try {
            DB::transaction(function () use ($c, $segment, $email, $code) {
                $discount = Discount::create([
                    'code' => $code,
                    'type' => $segment['type'],
                    'value' => $segment['value'],
                    'min_order_amount' => $segment['min_order'] ?? 0,
                    'max_uses' => 1,
                    'end_date' => now()->addDays((int) $c['valid_days']),
                    'is_active' => true,
                ]);
                SpinResult::create([
                    'email' => $email,
                    'prize_label' => $segment['label'],
                    'code' => $code,
                    'discount_id' => $discount->id,
                ]);
            });
        } catch (QueryException $e) {
            // Two requests for one email: the unique index let only one through,
            // and the rollback removed this one's discount code with it.
            if (SpinResult::where('email', $email)->exists()) {
                return response()->json(['error' => 'already_spun'], 409);
            }
            throw $e;
        }

        return response()->json([
            'index' => $index,
            'label' => $segment['label'],
            'code' => $code,
            'valid_days' => $c['valid_days'],
            'min_order' => $segment['min_order'] ?? null,
        ]);
    }

    public function adminShow()
    {
        return response()->json([
            'config' => $this->config(),
            'total' => SpinResult::count(),
            'results' => SpinResult::with('discount:id,used_count')
                ->latest()
                ->limit(50)
                ->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'email' => $r->email,
                    'prize' => $r->prize_label,
                    'code' => $r->code,
                    'used' => (int) ($r->discount->used_count ?? 0) > 0,
                    'created_at' => $r->created_at,
                ]),
        ]);
    }

    public function adminUpdate(Request $request)
    {
        $config = $request->validate([
            'enabled' => 'required|boolean',
            'heading' => 'required|string|max:120',
            'heading_ar' => 'nullable|string|max:120',
            'subheading' => 'nullable|string|max:200',
            'subheading_ar' => 'nullable|string|max:200',
            'delay_seconds' => 'required|integer|min:0|max:600',
            'valid_days' => 'required|integer|min:1|max:365',
            'segments' => 'required|array|min:2|max:12',
            'segments.*.label' => 'required|string|max:20',
            'segments.*.type' => 'required|in:percentage,fixed',
            'segments.*.value' => 'required|numeric|gt:0',
            'segments.*.min_order' => 'nullable|numeric|min:0',
            'segments.*.weight' => 'required|integer|min:1|max:1000',
        ]);

        foreach ($config['segments'] as $i => $segment) {
            if ($segment['type'] === 'percentage' && (float) $segment['value'] > 100) {
                return response()->json([
                    'message' => 'Segment '.($i + 1).': a percentage prize cannot be more than 100.',
                ], 422);
            }
        }

        SiteSetting::updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => json_encode($config, JSON_UNESCAPED_UNICODE)]
        );

        return response()->json(['success' => true, 'config' => $this->config()]);
    }

    private function pickSegment(array $segments): int
    {
        $roll = random_int(1, (int) array_sum(array_column($segments, 'weight')));
        foreach ($segments as $i => $segment) {
            $roll -= (int) $segment['weight'];
            if ($roll <= 0) {
                return $i;
            }
        }

        return 0;
    }
}
