<?php

/**
 * ============================================================
 * PRODUCT CONTROLLER — Handles Product API Requests
 * ============================================================
 * What this file does:
 *   Handles all product-related API calls:
 *   - List all products (with filters & pagination)
 *   - Get a single product's details
 *   - Search products
 *   - Create/update/delete products (admin only)
 *
 * API endpoints handled:
 *   GET  /api/products        → List products
 *   GET  /api/products/{id}   → Single product details
 *   POST /api/products        → Create product (admin)
 *   PUT  /api/products/{id}   → Update product (admin)
 * ============================================================
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Support\SocialImage;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    // GET /api/products
    public function index(Request $request)
    {
        $query = Product::with(['brand', 'category', 'variations', 'categoryMemberships:id,name,slug']);

        // A category flagged `exclude_from_shop` keeps its own browsable page but
        // its products stay out of the general catalogue. So the exclusion applies
        // to every customer listing EXCEPT one explicitly scoped to that category —
        // that request is the category's own shelf, which must still return its
        // stock. Resolved once here because both branches below need to know.
        $requestedCategory = $request->filled('category')
            ? Category::where('slug', $request->category)->first()
            : null;
        $isExcludedCategoryShelf = (bool) $requestedCategory?->exclude_from_shop;

        if ($request->has('all')) {
            // Admin listing — newest first so freshly added products appear at the top.
            // No visibility filter so scheduled/inactive products are still editable.
            $query->orderByDesc('created_at');
        } elseif ($request->boolean('best_selling')) {
            // Ranks by real units sold (paid, non-cancelled orders) instead of the
            // usual stock/recency ordering — used by the homepage "Best Selling"
            // section. Ranked in a separate aggregate query (rather than a
            // GROUP BY on the main products query) because MySQL's
            // ONLY_FULL_GROUP_BY here rejects `select products.*` even though
            // products.id is the PK; the ranked id list is then applied via
            // ORDER BY CASE so the main query stays a plain, ungrouped select.
            $rankedIds = \DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.payment_status', 'paid')
                ->where('orders.status', '!=', 'cancelled')
                ->groupBy('order_items.product_id')
                ->orderByRaw('SUM(order_items.quantity) DESC')
                ->pluck('order_items.product_id')
                ->filter()
                ->values()
                ->all();

            if (empty($rankedIds)) {
                // No qualifying sales yet (e.g. a fresh staging DB) — fall back to
                // the normal customer ordering rather than returning nothing, so
                // the homepage "Best Selling" section still has real products to
                // preview. This naturally stops being used once real paid orders
                // exist, at which point actual sales rank takes over above.
                $query->orderByRaw('(stock_quantity <= 0) asc')
                    ->orderByDesc('sort_order')
                    ->orderByDesc('created_at')
                    ->visibleToCustomers();

                if (! $isExcludedCategoryShelf) {
                    $query->excludingHiddenCategories();
                }
            } else {
                // CASE rather than MySQL's FIELD() so SQLite installs work too.
                $whens = implode(' ', array_map(fn ($i) => "WHEN ? THEN {$i}", array_keys($rankedIds)));
                $query->whereIn('products.id', $rankedIds)
                    ->orderByRaw("CASE products.id {$whens} END", array_values($rankedIds))
                    ->visibleToCustomers();

                if (! $isExcludedCategoryShelf) {
                    $query->excludingHiddenCategories();
                }
            }
        } elseif ($request->boolean('newest')) {
            // Homepage "New Arrivals": strictly newest first — each new watch
            // pushes the older ones along. "New" = when it went live, so a
            // scheduled drop counts from its publish date, not its upload date.
            // Sold watches are left out entirely (the shop grid only sinks them
            // to the bottom): stock 0, or marked "Sold Out" by the admin. Stock
            // left empty means untracked, i.e. still available.
            $query->orderByRaw('COALESCE(published_at, created_at) DESC')
                ->visibleToCustomers()
                ->where(fn ($q) => $q->whereNull('stock_quantity')->orWhere('stock_quantity', '>', 0))
                ->where(fn ($q) => $q->whereNull('badge')->orWhere('badge', '!=', 'out'));

            if (! $isExcludedCategoryShelf) {
                $query->excludingHiddenCategories();
            }
        } else {
            // Customer listing — in-stock products first so sold-out items sink to
            // the bottom of every grid (looks cleaner/more professional), then by
            // admin display priority (sort_order, higher pins to the top — lets
            // admin float a product to the front of the listing), then newest
            // uploads first within each group.
            // Hide inactive and scheduled-for-future products.
            $query->orderByRaw('(stock_quantity <= 0) asc')
                ->orderByDesc('sort_order')
                ->orderByDesc('created_at')
                ->visibleToCustomers();

            if (! $isExcludedCategoryShelf) {
                $query->excludingHiddenCategories();
            }
        }

        if ($request->filled('category')) {
            // Filter by category MEMBERSHIP (product_categories pivot), so a product
            // shows under every category it belongs to — not just its primary one.
            // The pivot always contains the primary category_id too (kept in sync on
            // save + backfilled), so this is a superset of the old category_id filter.
            $query->whereHas('categoryMemberships', fn ($q) => $q->where('slug', $request->category));
        }
        if ($request->filled('badge')) {
            $query->where('badge', $request->badge);
        }
        if ($request->filled('brandId')) {
            $query->where('brand_id', $request->brandId);
        }
        if ($request->boolean('featured')) {
            $query->where('is_featured', true);
        }

        if ($request->filled('search')) {
            $term = '%'.$request->search.'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('name_ar', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', $term));
            });
        }

        $limit = $request->integer('limit', 500);
        $offset = $request->integer('offset', 0);

        $products = $query->limit($limit)->offset($offset)->get();

        // "New Arrivals" fallback: badge=new is a manual admin tag, and right now
        // NO product (dev, staging, or production) has ever been tagged with it —
        // which silently empties the homepage's New Arrivals section everywhere,
        // not just here. Until an admin starts tagging arrivals, fall back to the
        // most recently added in-stock products, which is what "new arrivals"
        // means anyway. Stops applying the moment a real badge=new tag exists.
        if ($request->input('badge') === 'new' && $products->isEmpty()) {
            $fallback = Product::with(['brand', 'category', 'variations', 'categoryMemberships:id,name,slug'])
                ->visibleToCustomers();

            if (! $isExcludedCategoryShelf) {
                $fallback->excludingHiddenCategories();
            }
            if ($request->filled('category')) {
                $fallback->whereHas('categoryMemberships', fn ($q) => $q->where('slug', $request->category));
            }

            $products = $fallback->orderByDesc('created_at')->limit($limit)->offset($offset)->get();
        }

        // Format to match frontend expectations
        $products->transform(function ($p) {
            $p->brands = $p->brand;
            $p->categories = $p->category;
            // Flat list of category ids this product belongs to (for the admin
            // multi-category picker). Always includes the primary category_id.
            $p->category_ids = $p->relationLoaded('categoryMemberships')
                ? $p->categoryMemberships->pluck('id')->values()
                : [];
            unset($p->brand, $p->category, $p->categoryMemberships);

            return $p;
        });

        return response()->json($products);
    }

    // GET /api/products/next-drop
    // When the next scheduled product goes live, for the header countdown.
    // Only the time is public: the product itself stays hidden until launch.
    public function nextDrop()
    {
        $next = Product::where('is_active', true)
            ->where('published_at', '>', now())
            ->orderBy('published_at')
            ->first(['id', 'published_at']);

        return response()->json(['at' => $next?->published_at->toIso8601String()]);
    }

    // GET /api/products/{idOrSlug}
    // Public detail endpoint — hides scheduled-for-future + inactive
    // products so deep links to a staged watch return 404 until launch.
    public function show(string $idOrSlug)
    {
        $isUuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $idOrSlug);

        $product = Product::with(['brand', 'category', 'variations'])
            ->when($isUuid, fn ($q) => $q->where('id', $idOrSlug))
            ->when(! $isUuid, fn ($q) => $q->where('slug', $idOrSlug))
            ->visibleToCustomers()
            ->firstOrFail();

        $product->brands = $product->brand;
        $product->categories = $product->category;
        // Absolute, crawler-friendly JPEG for the page's og:image.
        $product->og_image_url = SocialImage::url(
            $product->image_url ?: (is_array($product->images) ? ($product->images[0] ?? null) : null)
        );

        return response()->json($product);
    }

    // GET /api/products/{id}/related
    public function related(string $id, Request $request)
    {
        $product = Product::find($id);
        // No primary-category requirement: the ranking is by brand and date, and
        // a watch filed only through the multi-category pivot (category_id NULL —
        // the admin form allows it) used to get no recommendations at all.
        if (! $product) {
            return response()->json([]);
        }

        // Never recommend excluded stock from a normal product page. When the seed
        // product is itself in an excluded category we skip the filter, so its page
        // can still surface siblings from the same shelf instead of nothing.
        $seedIsExcluded = $product->categoryMemberships()
            ->where('exclude_from_shop', true)
            ->exists();

        // "You May Also Like" (client request 2026-10-20): it used to list the
        // first few watches of the same category in admin sort order — the same
        // handful on every page. Now, across the whole visible catalogue: watches
        // of the same brand first; when the brand has none (or too few) available,
        // the newest arrivals fill the row. Available before sold-out throughout.
        $limit = max(1, min(24, $request->integer('limit', 5)));
        $candidates = Product::with(['brand', 'category', 'variations'])
            ->where('id', '!=', $id)
            ->visibleToCustomers()
            ->when(! $seedIsExcluded, fn ($q) => $q->excludingHiddenCategories())
            ->get();

        $brands = \App\Models\Brand::query()->pluck('name')->all();
        $product->loadMissing('brand');
        $seedBrand = $this->brandKey($product, $brands);

        $related = $candidates
            ->map(fn (Product $p) => [
                'p' => $p,
                // Same rules as New Arrivals: stock left empty means untracked
                // (still available); the admin's "Sold Out" badge counts as sold.
                'available' => ($p->stock_quantity === null || $p->stock_quantity > 0)
                    && $p->badge !== 'out',
                'sameBrand' => $seedBrand !== '' && $this->brandKey($p, $brands) === $seedBrand,
                // "New" = when it went live, so a scheduled drop counts from its
                // publish date, not its upload date (as on the homepage).
                'wentLive' => ($p->published_at ?? $p->created_at)?->getTimestamp() ?? 0,
            ])
            ->sort(fn ($a, $b) => [! $a['available'], ! $a['sameBrand'], -$a['wentLive']]
                <=> [! $b['available'], ! $b['sameBrand'], -$b['wentLive']])
            ->take($limit)
            ->pluck('p')
            ->values();

        // Same shape as index()/show(): the storefront reads `brands` and
        // `categories`. Without this, "You May Also Like" cards lost the
        // admin-assigned brand and fell back to guessing it from the title
        // (e.g. "LV watch with pink dial" showed no brand at all).
        $related->each(function ($p) {
            $p->brands = $p->brand;
            $p->categories = $p->category;
        });

        return response()->json($related);
    }

    /**
     * A watch's brand for matching: the brand picked in admin, else the brand
     * named in the title (most watches have none picked — the storefront does
     * the same in src/lib/brands.ts). Earliest name in the title wins, then the
     * longest ("Christian Dior" over "Dior"). '' when none is found.
     *
     * @param  array<int, string>  $brandNames
     */
    private function brandKey(Product $p, array $brandNames): string
    {
        $norm = fn (string $s) => strtoupper(trim(preg_replace('/\s+/', ' ',
            \Illuminate\Support\Str::ascii($s))));

        if ($p->brand && $p->brand->name) {
            return $this->canonicalBrand($norm($p->brand->name));
        }

        $title = ' '.preg_replace('/[^A-Z0-9&]+/', ' ', $norm((string) $p->name)).' ';
        $aliases = ['BVLGARI' => 'BULGARI', 'DIOR' => 'CHRISTIAN DIOR', 'LV' => 'LOUIS VUITTON',
            'YSL' => 'YVES SAINT LAURENT', 'SAINT LAURENT' => 'YVES SAINT LAURENT', 'TIFFANY' => 'TIFFANY & CO.'];
        $names = [];
        foreach ($brandNames as $b) {
            $names[$norm($b)] = $this->canonicalBrand($norm($b));
        }
        foreach ($aliases as $alias => $brand) {
            $names[$alias] = $brand;
        }
        unset($names['GENEVE']); // a place next to another brand ("Omega Genève")

        $best = null;
        foreach ($names as $needle => $brand) {
            $clean = trim(preg_replace('/[^A-Z0-9&]+/', ' ', $needle));
            if ($clean === '') {
                continue;
            }
            $pos = strpos($title, ' '.$clean.' ');
            if ($pos === false) {
                continue;
            }
            if (! $best || $pos < $best[1] || ($pos === $best[1] && strlen($clean) > $best[2])) {
                $best = [$brand, $pos, strlen($clean)];
            }
        }
        if ($best) {
            return $best[0];
        }

        return preg_match('/ GENEV[AE] /', $title) ? 'GENEVE' : '';
    }

    /** Fold spelling variants of one brand to a single key. */
    private function canonicalBrand(string $name): string
    {
        return match ($name) {
            'BVLGARI' => 'BULGARI',
            'DIOR' => 'CHRISTIAN DIOR',
            'HERMES', 'HERMÈS' => 'HERMES',
            default => $name,
        };
    }

    // POST /api/products (admin)
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'slug' => 'required|string|max:255|unique:products|regex:/^[a-z0-9-]+$/',
            'description' => 'nullable|string|max:10000',
            'description_ar' => 'nullable|string|max:10000',
            'short_description' => 'nullable|string|max:1000',
            'short_description_ar' => 'nullable|string|max:1000',
            // An enquiry-only watch may have no price yet — its price is never
            // shown or charged, so 0 is meaningful there. A normally priced
            // product still has to carry a real figure.
            'price' => ['required', 'numeric', $request->boolean('inquiry_only') ? 'min:0' : 'min:0.01'],
            'original_price' => 'nullable|numeric|min:0.01',
            'stock_quantity' => 'integer|min:0',
            'image_url' => 'nullable|string|max:2048',
            'images' => 'nullable|array',
            'images.*' => 'string|max:2048',
            'video_url' => 'nullable|string|max:2048',
            'sku' => 'nullable|string|max:255',
            'badge' => 'nullable|string|in:new,sale,out,featured',
            'category_id' => 'nullable|uuid|exists:categories,id',
            'brand_id' => 'nullable|uuid|exists:brands,id',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'inquiry_only' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
            // Scheduled publish — accepts a full ISO datetime or null
            // (publish immediately). When set in the future, the product
            // is hidden from customer-facing endpoints until that moment.
            'published_at' => 'nullable|date',
            // Full set of categories this product belongs to (many-to-many).
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'uuid|exists:categories,id',
        ]);

        $categoryIds = $validated['category_ids'] ?? [];
        unset($validated['category_ids']);

        $product = Product::create($validated);
        $this->syncCategoryMemberships($product, $categoryIds);

        return response()->json($product, 201);
    }

    /**
     * Sync a product's category memberships (product_categories pivot). The
     * primary category_id is always folded in so the pivot is a superset —
     * the storefront listing and category counts both read the pivot.
     */
    private function syncCategoryMemberships(Product $product, array $categoryIds): void
    {
        $ids = collect($categoryIds)
            ->push($product->category_id)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $product->categoryMemberships()->sync($ids);
    }

    // PUT /api/products/{id} (admin)
    public function update(Request $request, string $id)
    {
        $product = Product::findOrFail($id);
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'slug' => 'sometimes|string|max:255|regex:/^[a-z0-9-]+$/|unique:products,slug,'.$id,
            'description' => 'nullable|string|max:10000',
            'description_ar' => 'nullable|string|max:10000',
            'short_description' => 'nullable|string|max:1000',
            'short_description_ar' => 'nullable|string|max:1000',
            'price' => ['sometimes', 'numeric', $request->boolean('inquiry_only') ? 'min:0' : 'min:0.01'],
            'original_price' => 'nullable|numeric|min:0.01',
            'stock_quantity' => 'integer|min:0',
            'image_url' => 'nullable|string|max:2048',
            'images' => 'nullable|array',
            'images.*' => 'string|max:2048',
            'video_url' => 'nullable|string|max:2048',
            'sku' => 'nullable|string|max:255',
            'badge' => 'nullable|string|in:new,sale,out,featured',
            'category_id' => 'nullable|uuid|exists:categories,id',
            'brand_id' => 'nullable|uuid|exists:brands,id',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'inquiry_only' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
            'published_at' => 'nullable|date',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'uuid|exists:categories,id',
        ]);

        $hasCategoryIds = $request->has('category_ids');
        $categoryIds = $validated['category_ids'] ?? [];
        unset($validated['category_ids']);

        $product->update($validated);

        if ($hasCategoryIds) {
            $this->syncCategoryMemberships($product, $categoryIds);
        } elseif ($request->has('category_id') && $product->category_id) {
            // Primary changed without an explicit membership list — keep the
            // pivot consistent by ensuring the new primary is a member.
            $product->categoryMemberships()->syncWithoutDetaching([$product->category_id]);
        }

        return response()->json($product);
    }

    // DELETE /api/products/{id} (admin)
    public function destroy(string $id)
    {
        Product::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }
}
