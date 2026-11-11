<?php
/**
 * ============================================================
 * BRAND CONTROLLER — CRUD for Watch Brands
 * ============================================================
 * What this controller does:
 *   Manages watch brands (like Rolex, Omega, etc.).
 *   Admins can create, update, and delete brands.
 *   The public storefront can list and view brands.
 *
 * API endpoints:
 *   GET    /api/brands        — List all brands (active only, or all with ?all)
 *   GET    /api/brands/{slug} — Get a single brand by its URL slug
 *   POST   /api/brands        — Create a new brand (admin only)
 *   PUT    /api/brands/{id}   — Update a brand (admin only)
 *   DELETE /api/brands/{id}   — Delete a brand (admin only)
 * ============================================================
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    /**
     * GET /api/brands
     * Lists all brands sorted by sort_order then name.
     * By default only shows active brands. Add ?all to see inactive ones too.
     */
    public function index(Request $request)
    {
        $query = Brand::orderBy('sort_order')->orderBy('name');
        if (!$request->has('all')) {
            $query->where('is_active', true);
        }
        return response()->json($query->get());
    }

    /**
     * GET /api/brands/{slug}
     * Finds and returns a single brand by its URL-friendly slug (e.g. "rolex").
     */
    public function show(string $slug)
    {
        return response()->json(Brand::where('slug', $slug)->firstOrFail());
    }

    /**
     * POST /api/brands
     * Creates a new brand. Requires name and slug.
     * Slug must be lowercase letters, numbers, and hyphens only.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'slug' => 'required|string|max:255|unique:brands|regex:/^[a-z0-9-]+$/',
            'logo_url' => 'nullable|string|max:2048',
            'description' => 'nullable|string|max:5000',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);
        return response()->json(Brand::create($validated), 201);
    }

    /**
     * PUT /api/brands/{id}
     * Updates an existing brand. Only the fields you send will be changed.
     */
    public function update(Request $request, string $id)
    {
        $brand = Brand::findOrFail($id);
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'slug' => 'sometimes|string|max:255|regex:/^[a-z0-9-]+$/|unique:brands,slug,' . $id,
            'logo_url' => 'nullable|string|max:2048',
            'description' => 'nullable|string|max:5000',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);
        $brand->update($validated);
        return response()->json($brand);
    }

    /**
     * DELETE /api/brands/{id}
     * Permanently deletes a brand by its ID.
     */
    public function destroy(string $id)
    {
        Brand::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }
}
