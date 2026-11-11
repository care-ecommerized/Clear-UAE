<?php

/**
 * ============================================================
 * DISCOUNT CONTROLLER — Discount Code Management
 * ============================================================
 * What this controller does:
 *   Manages discount/coupon codes for the store. Customers
 *   can validate a code at checkout. Admins can create,
 *   update, and delete discount codes. Supports percentage
 *   and fixed-amount discounts with optional expiry dates.
 *
 * API endpoints:
 *   GET    /api/discounts/validate/{code}  — Check if a discount code is valid
 *   GET    /api/discounts                  — List all discount codes (admin)
 *   POST   /api/discounts                  — Create a new discount code (admin)
 *   PUT    /api/discounts/{id}             — Update a discount code (admin)
 *   DELETE /api/discounts/{id}             — Delete a discount code (admin)
 *   POST   /api/discounts/{id}/increment   — Increase the usage count by 1
 * ============================================================
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Discount;
use Illuminate\Http\Request;

class DiscountController extends Controller
{
    /**
     * GET /api/discounts/validate/{code}
     * Checks if a discount code is valid and not expired.
     * Returns the discount details (type, value, min order amount) if valid.
     */
    public function validate(Request $request, string $code)
    {
        $discount = Discount::valid()->where('code', strtoupper($code))->first();

        if (! $discount) {
            return response()->json(['valid' => false, 'error' => 'Invalid or expired discount code'], 404);
        }

        return response()->json([
            'valid' => true,
            'discount' => [
                'id' => $discount->id,
                'code' => $discount->code,
                'type' => $discount->type,
                'value' => $discount->value,
                'maxDiscountAmount' => $discount->max_discount_amount,
                'minOrderAmount' => $discount->min_order_amount,
            ],
        ]);
    }

    /**
     * GET /api/discounts
     * Lists all discount codes, newest first. Admin only.
     */
    public function index()
    {
        return response()->json(Discount::orderByDesc('created_at')->get());
    }

    /**
     * POST /api/discounts
     * Creates a new discount code. Type can be "percentage" or "fixed".
     * The code is automatically converted to uppercase.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:discounts',
            'type' => 'required|string|in:percentage,fixed',
            'value' => 'required|numeric|min:0',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'min_order_amount' => 'nullable|numeric|min:0',
            'max_uses' => 'nullable|integer|min:1',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'is_active' => 'boolean',
        ]);
        $validated['code'] = strtoupper($validated['code']);

        return response()->json(Discount::create($validated), 201);
    }

    /**
     * PUT /api/discounts/{id}
     * Updates an existing discount code. Only send the fields you want to change.
     */
    public function update(Request $request, string $id)
    {
        $discount = Discount::findOrFail($id);
        $validated = $request->validate([
            'code' => 'sometimes|string|max:50|unique:discounts,code,'.$id,
            'type' => 'sometimes|string|in:percentage,fixed',
            'value' => 'sometimes|numeric|min:0',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'min_order_amount' => 'nullable|numeric|min:0',
            'max_uses' => 'nullable|integer|min:1',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'is_active' => 'boolean',
        ]);
        if (isset($validated['code'])) {
            $validated['code'] = strtoupper($validated['code']);
        }
        $discount->update($validated);

        return response()->json($discount);
    }

    /**
     * DELETE /api/discounts/{id}
     * Permanently deletes a discount code.
     */
    public function destroy(string $id)
    {
        Discount::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/discounts/{id}/increment
     * Adds 1 to the usage count when a discount code is used at checkout.
     */
    public function incrementUsage(string $id)
    {
        Discount::where('id', $id)->increment('used_count');

        return response()->json(['success' => true]);
    }
}
