<?php

/**
 * ============================================================
 * CATEGORY CONTROLLER — CRUD for Product Categories
 * ============================================================
 * What this controller does:
 *   Manages product categories (like "Luxury Watches",
 *   "Sports Watches", etc.). Supports listing with product
 *   counts, creating/updating/deleting categories, and
 *   assigning or removing products from categories.
 *
 * API endpoints:
 *   GET    /api/categories              — List categories (active only, or all with ?all)
 *   GET    /api/categories/with-count   — List categories with product counts
 *   GET    /api/categories/{slug}       — Get a single category by its URL slug
 *   POST   /api/categories              — Create a new category (admin only)
 *   PUT    /api/categories/{id}         — Update a category (admin only)
 *   DELETE /api/categories/{id}         — Delete a category (admin only)
 *   POST   /api/categories/assign       — Assign products to a category
 *   POST   /api/categories/unassign     — Remove products from a category
 * ============================================================
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * GET /api/categories
     * Lists all categories sorted by sort_order then name.
     * By default only shows active categories. Add ?all to see all.
     * Add ?with_count to include how many active products each category has.
     */
    public function index(Request $request)
    {
        $query = Category::orderBy('sort_order')->orderBy('name');

        if (! $request->has('all')) {
            $query->where('is_active', true);
        }
        if ($request->has('with_count')) {
            // Count by category MEMBERSHIP (product_categories pivot) so a product
            // is counted under every category it belongs to — matches the storefront
            // listing. The pivot always includes each product's primary category.
            $query->withCount(['productMembers as products_count' => fn ($q) => $q->where('products.is_active', true)]);
        }

        $categories = $query->get()->map(function ($cat) {
            $cat->product_count = $cat->products_count ?? 0;

            return $cat;
        });

        return response()->json($categories);
    }

    /**
     * GET /api/categories/with-count
     * Returns active categories with the number of active products in each.
     * Used on the storefront to show category cards with product counts.
     */
    public function withCount()
    {
        $categories = Category::where('is_active', true)
            ->withCount(['productMembers as products_count' => fn ($q) => $q->where('products.is_active', true)])
            ->orderBy('sort_order')->orderBy('name')
            ->get()
            ->map(function ($cat) {
                $cat->product_count = $cat->products_count ?? 0;

                return $cat;
            });

        return response()->json($categories);
    }

    /**
     * GET /api/categories/{slug}
     * Finds and returns a single category by its URL-friendly slug.
     */
    public function show(string $slug)
    {
        return response()->json(Category::where('slug', $slug)->firstOrFail());
    }

    /**
     * POST /api/categories
     * Creates a new category. Requires name and slug.
     * Optionally supports parent_id for nested (sub) categories.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'slug' => 'required|string|max:255|unique:categories|regex:/^[a-z0-9-]+$/',
            'description' => 'nullable|string|max:5000',
            'image_url' => 'nullable|string|max:2048',
            'parent_id' => 'nullable|uuid|exists:categories,id',
            'is_active' => 'boolean',
            'exclude_from_shop' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        return response()->json(Category::create($validated), 201);
    }

    /**
     * PUT /api/categories/{id}
     * Updates an existing category. Only the fields you send will be changed.
     */
    public function update(Request $request, string $id)
    {
        $category = Category::findOrFail($id);
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'slug' => 'sometimes|string|max:255|regex:/^[a-z0-9-]+$/|unique:categories,slug,'.$id,
            'description' => 'nullable|string|max:5000',
            'image_url' => 'nullable|string|max:2048',
            'parent_id' => 'nullable|uuid|exists:categories,id',
            'is_active' => 'boolean',
            'exclude_from_shop' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);
        $category->update($validated);

        return response()->json($category);
    }

    /**
     * DELETE /api/categories/{id}
     * Permanently deletes a category by its ID.
     */
    public function destroy(string $id)
    {
        Category::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/categories/assign
     * Moves a list of products into a specific category.
     * Expects: category_id and an array of product_ids.
     */
    public function assignProducts(Request $request)
    {
        $request->validate([
            'category_id' => 'required|uuid|exists:categories,id',
            'product_ids' => 'required|array',
            'product_ids.*' => 'uuid|exists:products,id',
        ]);

        $category = Category::findOrFail($request->category_id);
        // Add these products to the category (pivot membership) without dropping
        // their other categories.
        $category->productMembers()->syncWithoutDetaching($request->product_ids);
        // Give a primary category to any product that doesn't have one yet.
        Product::whereIn('id', $request->product_ids)
            ->whereNull('category_id')
            ->update(['category_id' => $category->id]);

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/categories/unassign
     * Removes products from a category (sets their category_id to null).
     * Expects: category_id and an array of product_ids.
     */
    public function unassignProducts(Request $request)
    {
        $request->validate([
            'category_id' => 'required|uuid|exists:categories,id',
            'product_ids' => 'required|array',
            'product_ids.*' => 'uuid|exists:products,id',
        ]);

        $category = Category::findOrFail($request->category_id);
        // Drop the membership from the pivot.
        $category->productMembers()->detach($request->product_ids);
        // If this category was a product's primary, repoint the primary to one of
        // its remaining memberships (or null if none remain).
        Product::whereIn('id', $request->product_ids)
            ->where('category_id', $category->id)
            ->get()
            ->each(function (Product $p) {
                $p->category_id = $p->categoryMemberships()->first()?->id;
                $p->save();
            });

        return response()->json(['success' => true]);
    }
}
