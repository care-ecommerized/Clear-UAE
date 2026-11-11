<?php
/**
 * ============================================================
 * PRODUCT CSV CONTROLLER — CSV Import & Export for Products
 * ============================================================
 * What this controller does:
 *   Lets admins bulk-manage products using CSV (spreadsheet)
 *   files. You can export all products to a CSV, import
 *   products from a CSV (creating or updating them), and
 *   download a blank template CSV to fill in.
 *
 * How CSV rows work:
 *   - "product" rows create/update main products
 *   - "variation" rows create/update product variations
 *     (e.g. different colors or sizes of the same product)
 *
 * API endpoints:
 *   GET  /api/products/csv/export    — Download all products as CSV
 *   POST /api/products/csv/import    — Upload a CSV to create/update products
 *   GET  /api/products/csv/template  — Download a blank CSV template
 * ============================================================
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductCsvController extends Controller
{
    // These are the column names used in the CSV file
    private const CSV_HEADERS = [
        'row_type', 'name', 'slug', 'name_ar', 'description', 'description_ar',
        'short_description', 'short_description_ar', 'sku', 'price', 'original_price',
        'image_url', 'images', 'video_url', 'badge', 'size', 'notes_top', 'notes_middle',
        'notes_base', 'stock_quantity', 'brand_name', 'category_name', 'is_active',
        'is_featured', 'sort_order', 'variation_type', 'variation_value',
        // Appended at the END deliberately: the export and template rows below
        // are positional arrays, so inserting mid-list would silently shift
        // every column after it.
        'inquiry_only',
    ];

    /**
     * GET /api/products/csv/export
     * Downloads all products (and their variations) as a CSV file.
     * The file includes a UTF-8 BOM so it opens correctly in Excel.
     */
    public function export(): StreamedResponse
    {
        $products = Product::with(['brand', 'category', 'variations'])->orderBy('name')->get();

        return new StreamedResponse(function () use ($products) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM for Excel compatibility
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, self::CSV_HEADERS);

            foreach ($products as $product) {
                fputcsv($handle, [
                    'product',
                    $product->name,
                    $product->slug,
                    $product->name_ar ?? '',
                    $product->description ?? '',
                    $product->description_ar ?? '',
                    $product->short_description ?? '',
                    $product->short_description_ar ?? '',
                    $product->sku ?? '',
                    $product->price ?? '',
                    $product->original_price ?? '',
                    $product->image_url ?? '',
                    is_array($product->images) ? implode('|', $product->images) : '',
                    $product->video_url ?? '',
                    $product->badge ?? '',
                    $product->size ?? '',
                    $product->notes_top ?? '',
                    $product->notes_middle ?? '',
                    $product->notes_base ?? '',
                    $product->stock_quantity ?? 0,
                    $product->brand->name ?? '',
                    $product->category->name ?? '',
                    $product->is_active ? '1' : '0',
                    $product->is_featured ? '1' : '0',
                    $product->sort_order ?? '',
                    '', // variation_type
                    '', // variation_value
                    $product->inquiry_only ? '1' : '0',
                ]);

                foreach ($product->variations as $variation) {
                    fputcsv($handle, [
                        'variation',
                        '', // name
                        $product->slug, // parent ref
                        '', '', '', '', '', // ar fields, descriptions
                        $variation->sku ?? '',
                        $variation->price ?? '',
                        '', // original_price
                        $variation->image_url ?? '',
                        '', '', '', '', '', '', '', // images through notes_base
                        $variation->stock_quantity ?? 0,
                        '', '', // brand, category
                        $variation->is_active ? '1' : '0',
                        '', // is_featured
                        $variation->sort_order ?? '',
                        $variation->variation_type,
                        $variation->variation_value,
                        '', // inquiry_only — a product-level flag, not per-variation
                    ]);
                }
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="products-export-' . date('Y-m-d') . '.csv"',
            'Cache-Control' => 'no-cache, no-store',
        ]);
    }

    /**
     * POST /api/products/csv/import
     * Uploads a CSV file to create or update products in bulk.
     * If a product with the same slug already exists, it gets updated.
     * Returns stats: how many products/variations were created or updated.
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $file = $request->file('file');
        $content = file_get_contents($file->getRealPath());

        // Strip BOM
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        $lines = array_filter(explode("\n", $content), fn($line) => trim($line) !== '');
        if (count($lines) < 2) {
            return response()->json(['message' => 'CSV file is empty or has no data rows.'], 422);
        }

        $headerLine = array_shift($lines);
        $headers = str_getcsv($headerLine);
        $headers = array_map('trim', $headers);

        // Validate required headers
        $required = ['row_type', 'name', 'slug', 'price'];
        $missing = array_diff($required, $headers);
        if (!empty($missing)) {
            return response()->json([
                'message' => 'Missing required CSV columns: ' . implode(', ', $missing),
            ], 422);
        }

        $stats = [
            'products_created' => 0,
            'products_updated' => 0,
            'variations_created' => 0,
            'variations_updated' => 0,
            'brands_created' => 0,
            'categories_created' => 0,
            'errors' => [],
        ];

        // Cache for brand/category lookups
        $brandCache = [];
        $categoryCache = [];
        $lastProductSlug = null;

        DB::transaction(function () use ($lines, $headers, &$stats, &$brandCache, &$categoryCache, &$lastProductSlug) {
            foreach ($lines as $lineNum => $line) {
                $rowNumber = $lineNum + 2; // +1 for header, +1 for 1-based
                $values = str_getcsv($line);

                if (count($values) !== count($headers)) {
                    $stats['errors'][] = "Row {$rowNumber}: Column count mismatch (expected " . count($headers) . ", got " . count($values) . ")";
                    continue;
                }

                $row = array_combine($headers, $values);
                $row = array_map('trim', $row);
                $rowType = strtolower($row['row_type'] ?? '');

                if ($rowType === 'product') {
                    $this->importProduct($row, $rowNumber, $stats, $brandCache, $categoryCache);
                    $lastProductSlug = $row['slug'];
                } elseif ($rowType === 'variation') {
                    $parentSlug = !empty($row['slug']) ? $row['slug'] : $lastProductSlug;
                    $this->importVariation($row, $rowNumber, $parentSlug, $stats);
                } else {
                    $stats['errors'][] = "Row {$rowNumber}: Unknown row_type '{$rowType}'";
                }
            }
        });

        return response()->json($stats);
    }

    /**
     * GET /api/products/csv/template
     * Downloads a blank CSV template with example rows.
     * Use this as a starting point when preparing a CSV import file.
     */
    public function template(): StreamedResponse
    {
        return new StreamedResponse(function () {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, self::CSV_HEADERS);

            // Example product row
            fputcsv($handle, [
                'product', 'Rolex Submariner', 'rolex-submariner', 'رولكس سبمارينر',
                'Classic diving watch', 'ساعة غوص كلاسيكية', 'Iconic timepiece', 'قطعة أيقونية',
                'ROL-SUB-001', '4500.00', '5000.00',
                'https://example.com/rolex-sub.jpg', 'https://example.com/img1.jpg|https://example.com/img2.jpg',
                '', 'new', '41mm', '', '', '',
                '10', 'Rolex', 'Luxury Watches', '1', '1', '0', '', '', '0',
            ]);

            // Example variation row
            fputcsv($handle, [
                'variation', '', 'rolex-submariner', '', '', '', '', '',
                'ROL-SUB-BLK', '4600.00', '', 'https://example.com/rolex-sub-black.jpg',
                '', '', '', '', '', '', '',
                '5', '', '', '1', '', '1', 'color', 'Black', '',
            ]);

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="products-csv-template.csv"',
            'Cache-Control' => 'no-cache, no-store',
        ]);
    }

    /**
     * Processes a single "product" row from the CSV.
     * Creates the brand/category if they don't exist yet.
     * Updates the product if the slug already exists, otherwise creates a new one.
     */
    private function importProduct(array $row, int $rowNumber, array &$stats, array &$brandCache, array &$categoryCache): void
    {
        if (empty($row['name']) || empty($row['slug']) || empty($row['price'])) {
            $stats['errors'][] = "Row {$rowNumber}: Missing required field (name, slug, or price)";
            return;
        }

        $brandId = null;
        if (!empty($row['brand_name'])) {
            $brandName = $row['brand_name'];
            $cacheKey = mb_strtolower($brandName);
            if (!isset($brandCache[$cacheKey])) {
                $brand = Brand::whereRaw('LOWER(name) = ?', [$cacheKey])->first();
                if (!$brand) {
                    $brand = Brand::create([
                        'name' => $brandName,
                        'slug' => Str::slug($brandName),
                        'is_active' => true,
                    ]);
                    $stats['brands_created']++;
                }
                $brandCache[$cacheKey] = $brand->id;
            }
            $brandId = $brandCache[$cacheKey];
        }

        $categoryId = null;
        if (!empty($row['category_name'])) {
            $categoryName = $row['category_name'];
            $cacheKey = mb_strtolower($categoryName);
            if (!isset($categoryCache[$cacheKey])) {
                $category = Category::whereRaw('LOWER(name) = ?', [$cacheKey])->first();
                if (!$category) {
                    $category = Category::create([
                        'name' => $categoryName,
                        'slug' => Str::slug($categoryName),
                        'is_active' => true,
                    ]);
                    $stats['categories_created']++;
                }
                $categoryCache[$cacheKey] = $category->id;
            }
            $categoryId = $categoryCache[$cacheKey];
        }

        $images = !empty($row['images']) ? explode('|', $row['images']) : null;

        $data = [
            'name' => $row['name'],
            'name_ar' => $row['name_ar'] ?: null,
            'description' => $row['description'] ?: null,
            'description_ar' => $row['description_ar'] ?: null,
            'short_description' => $row['short_description'] ?: null,
            'short_description_ar' => $row['short_description_ar'] ?: null,
            'sku' => $row['sku'] ?: null,
            'price' => (float) $row['price'],
            'original_price' => !empty($row['original_price']) ? (float) $row['original_price'] : null,
            'image_url' => $row['image_url'] ?: null,
            'images' => $images,
            'video_url' => $row['video_url'] ?: null,
            'badge' => $row['badge'] ?: null,
            'size' => $row['size'] ?: null,
            'notes_top' => $row['notes_top'] ?: null,
            'notes_middle' => $row['notes_middle'] ?: null,
            'notes_base' => $row['notes_base'] ?: null,
            'stock_quantity' => (int) ($row['stock_quantity'] ?? 0),
            'brand_id' => $brandId,
            'category_id' => $categoryId,
            'is_active' => ($row['is_active'] ?? '1') === '1',
            'is_featured' => ($row['is_featured'] ?? '0') === '1',
            // Defaults to false (never null — the column is NOT NULL), so
            // re-importing a CSV exported before this column existed leaves
            // products normally priced rather than failing the import.
            'inquiry_only' => ($row['inquiry_only'] ?? '0') === '1',
            'sort_order' => !empty($row['sort_order']) ? (int) $row['sort_order'] : 0,
        ];

        $existing = Product::where('slug', $row['slug'])->first();
        if ($existing) {
            $existing->update($data);
            $stats['products_updated']++;
        } else {
            Product::create(array_merge($data, ['slug' => $row['slug']]));
            $stats['products_created']++;
        }
    }

    /**
     * Processes a single "variation" row from the CSV.
     * Links the variation to its parent product by slug.
     * Updates the variation if the same type+value already exists.
     */
    private function importVariation(array $row, int $rowNumber, ?string $parentSlug, array &$stats): void
    {
        if (empty($parentSlug)) {
            $stats['errors'][] = "Row {$rowNumber}: Variation row has no parent product slug";
            return;
        }

        if (empty($row['variation_type']) || empty($row['variation_value'])) {
            $stats['errors'][] = "Row {$rowNumber}: Variation missing type or value";
            return;
        }

        $product = Product::where('slug', $parentSlug)->first();
        if (!$product) {
            $stats['errors'][] = "Row {$rowNumber}: Parent product with slug '{$parentSlug}' not found";
            return;
        }

        $data = [
            'product_id' => $product->id,
            'variation_type' => $row['variation_type'],
            'variation_value' => $row['variation_value'],
            'price' => !empty($row['price']) ? (float) $row['price'] : null,
            'sku' => $row['sku'] ?: null,
            'image_url' => $row['image_url'] ?: null,
            'stock_quantity' => !empty($row['stock_quantity']) ? (int) $row['stock_quantity'] : null,
            'is_active' => ($row['is_active'] ?? '1') === '1',
            'sort_order' => !empty($row['sort_order']) ? (int) $row['sort_order'] : 0,
        ];

        $existing = ProductVariation::where('product_id', $product->id)
            ->where('variation_type', $row['variation_type'])
            ->where('variation_value', $row['variation_value'])
            ->first();

        if ($existing) {
            $existing->update($data);
            $stats['variations_updated']++;
        } else {
            ProductVariation::create($data);
            $stats['variations_created']++;
        }
    }
}
