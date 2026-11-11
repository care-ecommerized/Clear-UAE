<?php

/**
 * ============================================================
 * DemoSeeder.php — Optional demo catalogue
 * ============================================================
 * Fills a fresh store with an unbranded demo catalogue so a buyer can
 * see the storefront working straight after install:
 *
 *   - 4 made-up brands, 5 categories (EN + AR)
 *   - 24 luxury watch & jewelry products (EN + AR), prices in AED,
 *     stock levels, some on sale, a few featured, one sold out
 *   - 2 demo customer accounts, 3 demo discount codes
 *
 * Product photos are placeholders drawn with PHP GD at seed time
 * (soft gradient + a simple watch / ring / necklace / bracelet + the
 * product name) and saved to storage/app/public/uploads/products/demo-*.jpg,
 * with the usual responsive variants via ImageResizer.
 *
 * The data lives in database/demo/catalogue.php.
 *
 * Idempotent: rows are matched by slug / code / email and never
 * duplicated or overwritten; images are only drawn when missing.
 * Works on MySQL and SQLite.
 *
 * Run: php artisan db:seed --class=DemoSeeder
 * ============================================================
 */

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Discount;
use App\Models\Product;
use App\Models\Profile;
use App\Models\User;
use App\Models\UserRole;
use App\Services\ImageResizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    /** Password of the demo customer accounts. */
    public const CUSTOMER_PASSWORD = 'DemoCustomer123';

    /** Folder (on the public disk) the placeholder photos are written to. */
    public const IMAGE_DIR = 'uploads/products';

    private const IMAGE_SIZE = 1200;

    /** Set false to skip drawing photos (e.g. a slow host); products keep working with a null image. */
    public static bool $generateImages = true;

    /** Set false to skip the responsive WebP variants. */
    public static bool $generateVariants = true;

    private const METALS = [
        'gold' => ['#D4AF61', '#9C7A34'],
        'rose' => ['#E2A890', '#A86A57'],
        'steel' => ['#C9CDD1', '#7E858B'],
        'silver' => ['#E4E7EA', '#9AA1A7'],
    ];

    public function run(): void
    {
        // Drawing ~24 photos + variants takes ~30s — more than a web installer's
        // default max_execution_time on most shared hosts.
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        $data = require database_path('demo/catalogue.php');

        $brands = $this->seedBrands($data['brands']);
        $categories = $this->seedCategories($data['categories']);
        $palettes = collect($data['categories'])->pluck('palette', 'slug')->all();

        $created = 0;
        $firstImageByCategory = [];
        foreach ($data['products'] as $row) {
            $imageUrl = $this->productImage($row, $palettes[$row['category']] ?? ['#EEEEEE', '#BBBBBB']);
            $firstImageByCategory[$row['category']] ??= $imageUrl;

            $product = Product::firstOrCreate(['slug' => $row['slug']], [
                'name' => $row['name'],
                'name_ar' => $row['name_ar'],
                'short_description' => $row['short'],
                'short_description_ar' => $row['short_ar'],
                'description' => $row['description'],
                'description_ar' => $row['description_ar'],
                'price' => $row['price'],
                'original_price' => $row['original_price'],
                'sku' => $row['sku'],
                'image_url' => $imageUrl,
                'images' => $imageUrl ? [$imageUrl] : [],
                'badge' => $row['badge'],
                'size' => $row['size'],
                'stock_quantity' => $row['stock'],
                'category_id' => $categories[$row['category']]->id,
                'brand_id' => $brands[$row['brand']]->id,
                'is_active' => true,
                'is_featured' => $row['featured'],
                'inquiry_only' => false,
                'sort_order' => 0,
            ]);

            if ($product->wasRecentlyCreated) {
                $created++;
            } elseif (! $product->image_url && $imageUrl) {
                // Re-run after images were skipped (or the files went missing).
                $product->update(['image_url' => $imageUrl, 'images' => [$imageUrl]]);
            }

            // The storefront category filter reads the pivot, not category_id.
            $product->categoryMemberships()->syncWithoutDetaching([$categories[$row['category']]->id]);
        }

        // Category cards on the homepage use the category image.
        foreach ($categories as $slug => $category) {
            if (! $category->image_url && ! empty($firstImageByCategory[$slug])) {
                $category->update(['image_url' => $firstImageByCategory[$slug]]);
            }
        }

        $this->seedCustomers($data['customers']);
        $this->seedDiscounts($data['discounts']);

        $this->command?->info("Demo products created: $created (".count($data['products']).' in catalogue)');
    }

    /** @return array<string, Brand> keyed by slug */
    private function seedBrands(array $rows): array
    {
        $brands = [];
        foreach ($rows as $i => $row) {
            $brands[$row['slug']] = Brand::firstOrCreate(['slug' => $row['slug']], [
                'name' => $row['name'],
                'name_ar' => $row['name_ar'],
                'description' => $row['description'],
                'description_ar' => $row['description_ar'],
                'is_active' => true,
                'sort_order' => count($rows) - $i,
            ]);
        }

        return $brands;
    }

    /** @return array<string, Category> keyed by slug */
    private function seedCategories(array $rows): array
    {
        $categories = [];
        foreach ($rows as $row) {
            $categories[$row['slug']] = Category::firstOrCreate(['slug' => $row['slug']], [
                'name' => $row['name'],
                'name_ar' => $row['name_ar'],
                'description' => $row['description'],
                'description_ar' => $row['description_ar'],
                'is_active' => true,
                'exclude_from_shop' => false,
                'sort_order' => $row['sort_order'],
            ]);
        }

        return $categories;
    }

    private function seedCustomers(array $rows): void
    {
        foreach ($rows as $row) {
            $user = User::where('email', $row['email'])->first();
            if (! $user) {
                $user = User::create([
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'password' => Hash::make(self::CUSTOMER_PASSWORD),
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            Profile::firstOrCreate(['user_id' => $user->id], [
                'email' => $row['email'],
                'full_name' => $row['name'],
                'phone' => $row['phone'],
            ]);
            UserRole::firstOrCreate(['user_id' => $user->id, 'role' => 'user']);
        }
    }

    private function seedDiscounts(array $rows): void
    {
        foreach ($rows as $row) {
            Discount::firstOrCreate(['code' => $row['code']], [
                'type' => $row['type'],
                'value' => $row['value'],
                'max_discount_amount' => $row['max_discount_amount'],
                'min_order_amount' => $row['min_order_amount'],
                'max_uses' => $row['max_uses'],
                'used_count' => 0,
                'is_active' => true,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Placeholder product photos (PHP GD)
    // ------------------------------------------------------------------

    /** Public URL of the product's placeholder photo, drawing it if missing. Null when GD is unavailable. */
    private function productImage(array $row, array $palette): ?string
    {
        if (! self::$generateImages || ! function_exists('imagecreatetruecolor') || ! function_exists('imagejpeg')) {
            return null;
        }

        $path = self::IMAGE_DIR.'/demo-'.Str::slug($row['slug']).'.jpg';
        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            try {
                $disk->put($path, $this->drawProduct($row, $palette));
            } catch (\Throwable $e) {
                Log::warning('DemoSeeder: could not draw demo image', ['slug' => $row['slug'], 'error' => $e->getMessage()]);

                return null;
            }

            if (self::$generateVariants) {
                try {
                    app(ImageResizer::class)->generateVariants($disk->path($path));
                } catch (\Throwable $e) {
                    // Variants are an optimisation; the original still works.
                    Log::warning('DemoSeeder: variant generation failed', ['path' => $path, 'error' => $e->getMessage()]);
                }
            }
        }

        return asset('storage/'.$path);
    }

    /** Draw one 1200×1200 product photo and return the JPEG bytes. */
    private function drawProduct(array $row, array $palette): string
    {
        $size = self::IMAGE_SIZE;
        $img = imagecreatetruecolor($size, $size);
        imagealphablending($img, true);

        // Soft vertical gradient background.
        [$r1, $g1, $b1] = $this->rgb($palette[0]);
        [$r2, $g2, $b2] = $this->rgb($palette[1]);
        for ($y = 0; $y < $size; $y++) {
            $t = $y / ($size - 1);
            $c = imagecolorallocate($img, (int) ($r1 + ($r2 - $r1) * $t), (int) ($g1 + ($g2 - $g1) * $t), (int) ($b1 + ($b2 - $b1) * $t));
            imageline($img, 0, $y, $size - 1, $y, $c);
        }

        // Gentle light glow behind the object.
        for ($r = 900; $r > 0; $r -= 30) {
            $glow = imagecolorallocatealpha($img, 255, 255, 255, 124);
            imagefilledellipse($img, $size / 2, (int) ($size * 0.44), $r, $r, $glow);
        }

        $dark = $this->luminance($palette[0]) < 0.45;
        $metal = self::METALS[$row['metal'] ?? 'steel'] ?? self::METALS['steel'];

        match ($row['kind'] ?? 'watch') {
            'ring' => $this->drawRing($img, $metal, $row['gem'] ?? '#FFFFFF'),
            'necklace' => $this->drawNecklace($img, $metal, $row['gem'] ?? '#FFFFFF'),
            'bracelet' => $this->drawBracelet($img, $metal, $row['gem'] ?? '#FFFFFF'),
            default => $this->drawWatch($img, $metal, $row['dial'] ?? '#F2EEE4', $dark),
        };

        $this->drawCaption($img, $row['name'], $dark);

        ob_start();
        imagejpeg($img, null, 86);
        $bytes = (string) ob_get_clean();
        unset($img);

        return $bytes;
    }

    private function drawWatch(\GdImage $img, array $metal, string $dial, bool $darkBg): void
    {
        $cx = 600;
        $cy = 520;
        $strap = $this->color($img, $darkBg ? '#2A2A2A' : '#3B2A22');
        $strapEdge = $this->color($img, $darkBg ? '#3A3A3A' : '#4C372D');

        // Strap above and below the case.
        imagefilledrectangle($img, $cx - 130, 60, $cx + 130, $cy, $strap);
        imagefilledrectangle($img, $cx - 130, $cy, $cx + 130, 980, $strap);
        imagefilledrectangle($img, $cx - 130, 60, $cx - 118, 980, $strapEdge);
        imagefilledrectangle($img, $cx + 118, 60, $cx + 130, 980, $strapEdge);

        // Lugs, case, crown.
        $light = $this->color($img, $metal[0]);
        $shade = $this->color($img, $metal[1]);
        imagefilledrectangle($img, $cx - 150, $cy - 300, $cx + 150, $cy + 300, $shade);
        imagefilledellipse($img, $cx, $cy, 560, 560, $shade);
        imagefilledellipse($img, $cx - 6, $cy - 6, 540, 540, $light);
        imagefilledrectangle($img, $cx + 265, $cy - 28, $cx + 305, $cy + 28, $shade);

        // Dial.
        $dialColor = $this->color($img, $dial);
        imagefilledellipse($img, $cx, $cy, 470, 470, $dialColor);
        $ink = $this->luminance($dial) < 0.5 ? $this->color($img, '#F2F2F2') : $this->color($img, '#1E1E1E');
        $index = $this->color($img, $metal[0]);

        // Hour indices.
        imagesetthickness($img, 10);
        for ($h = 0; $h < 12; $h++) {
            $a = deg2rad($h * 30 - 90);
            $outer = 205;
            $inner = $h % 3 === 0 ? 160 : 180;
            imageline($img,
                (int) ($cx + cos($a) * $inner), (int) ($cy + sin($a) * $inner),
                (int) ($cx + cos($a) * $outer), (int) ($cy + sin($a) * $outer),
                $h % 3 === 0 ? $index : $ink);
        }

        // Hands at 10:10.
        imagesetthickness($img, 14);
        $hour = deg2rad(10 * 30 + 5 - 90);
        imageline($img, $cx, $cy, (int) ($cx + cos($hour) * 120), (int) ($cy + sin($hour) * 120), $ink);
        imagesetthickness($img, 9);
        $minute = deg2rad(2 * 30 - 90);
        imageline($img, $cx, $cy, (int) ($cx + cos($minute) * 185), (int) ($cy + sin($minute) * 185), $ink);
        imagesetthickness($img, 1);
        imagefilledellipse($img, $cx, $cy, 30, 30, $index);
    }

    private function drawRing(\GdImage $img, array $metal, string $gem): void
    {
        $cx = 600;
        $cy = 600;
        $shade = $this->color($img, $metal[1]);
        $light = $this->color($img, $metal[0]);

        // Band: a thick ellipse, with a polished highlight on the back half.
        $this->ellipseBand($img, $cx, $cy, 504, 444, 44, $shade);
        $this->ellipseBand($img, $cx, $cy, 494, 434, 22, $light, 180, 360);

        // Setting and stone on top.
        imagefilledrectangle($img, $cx - 34, $cy - 250, $cx + 34, $cy - 190, $shade);
        $this->drawGem($img, $cx, $cy - 300, 95, $gem);
    }

    private function drawNecklace(\GdImage $img, array $metal, string $gem): void
    {
        $cx = 600;
        $light = $this->color($img, $metal[0]);
        $shade = $this->color($img, $metal[1]);

        // Chain: beads along a U-shaped curve.
        for ($i = 0; $i <= 60; $i++) {
            $a = M_PI * ($i / 60);
            $x = (int) ($cx - cos($a) * 340);
            $y = (int) (150 + sin($a) * 520);
            imagefilledellipse($img, $x, $y, 22, 22, $shade);
            imagefilledellipse($img, $x - 2, $y - 2, 14, 14, $light);
        }

        // Pendant hanging from the lowest point.
        imagesetthickness($img, 6);
        imageline($img, $cx, 670, $cx, 720, $shade);
        imagesetthickness($img, 1);
        imagefilledellipse($img, $cx, 800, 190, 190, $shade);
        imagefilledellipse($img, $cx, 800, 170, 170, $light);
        $this->drawGem($img, $cx, 800, 70, $gem);
    }

    private function drawBracelet(\GdImage $img, array $metal, string $gem): void
    {
        $cx = 600;
        $cy = 560;
        $light = $this->color($img, $metal[0]);
        $shade = $this->color($img, $metal[1]);

        $this->ellipseBand($img, $cx, $cy, 670, 410, 50, $shade);
        $this->ellipseBand($img, $cx, $cy, 660, 400, 30, $light, 0, 180);

        // Stones along the front half.
        $stone = $this->color($img, $gem);
        $sparkle = imagecolorallocatealpha($img, 255, 255, 255, 60);
        for ($i = 1; $i < 14; $i++) {
            $a = M_PI * ($i / 14);
            $x = (int) ($cx - cos($a) * 310);
            $y = (int) ($cy + sin($a) * 180);
            imagefilledellipse($img, $x, $y, 30, 30, $stone);
            imagefilledellipse($img, $x - 5, $y - 5, 10, 10, $sparkle);
        }
    }

    /**
     * A thick elliptical band drawn as nested 1px arcs — GD's thick arcs
     * (imagesetthickness + imagearc) come out jagged.
     */
    private function ellipseBand(\GdImage $img, int $cx, int $cy, int $width, int $height, int $thickness, int $color, int $start = 0, int $end = 360): void
    {
        for ($k = 0; $k <= $thickness * 2; $k++) {
            // Each ring drawn twice, 1px apart, to close the pinholes between rings.
            imagearc($img, $cx, $cy, $width - $k, $height - $k, $start, $end, $color);
            imagearc($img, $cx + 1, $cy, $width - $k, $height - $k, $start, $end, $color);
            imagearc($img, $cx, $cy + 1, $width - $k, $height - $k, $start, $end, $color);
        }
    }

    /** A faceted stone: diamond-shaped polygon with a highlight facet. */
    private function drawGem(\GdImage $img, int $cx, int $cy, int $r, string $hex): void
    {
        $base = $this->color($img, $hex);
        $edge = $this->color($img, $this->mix($hex, '#000000', 0.35));
        $hi = imagecolorallocatealpha($img, 255, 255, 255, 50);

        $outline = [$cx - $r, $cy - (int) ($r * 0.25), $cx - (int) ($r * 0.5), $cy - (int) ($r * 0.7),
            $cx + (int) ($r * 0.5), $cy - (int) ($r * 0.7), $cx + $r, $cy - (int) ($r * 0.25), $cx, $cy + $r];
        imagefilledpolygon($img, $outline, $edge);
        $inner = [$cx - (int) ($r * 0.85), $cy - (int) ($r * 0.25), $cx - (int) ($r * 0.42), $cy - (int) ($r * 0.6),
            $cx + (int) ($r * 0.42), $cy - (int) ($r * 0.6), $cx + (int) ($r * 0.85), $cy - (int) ($r * 0.25), $cx, $cy + (int) ($r * 0.85)];
        imagefilledpolygon($img, $inner, $base);
        imagefilledpolygon($img, [$cx - (int) ($r * 0.42), $cy - (int) ($r * 0.6), $cx, $cy - (int) ($r * 0.25),
            $cx - (int) ($r * 0.85), $cy - (int) ($r * 0.25)], $hi);
    }

    /** Product name centred near the bottom (TrueType when a font is available). */
    private function drawCaption(\GdImage $img, string $text, bool $darkBg): void
    {
        $color = $darkBg ? $this->color($img, '#F4EFE6') : $this->color($img, '#2B2B2B');
        $font = $this->fontPath();

        if ($font && function_exists('imagettftext')) {
            $sizePt = 34;
            do {
                $box = imagettfbbox($sizePt, 0, $font, $text);
                $width = abs($box[2] - $box[0]);
                $sizePt -= 2;
            } while ($width > 1040 && $sizePt > 14);
            imagettftext($img, $sizePt + 2, 0, (int) ((self::IMAGE_SIZE - $width) / 2), 1110, $color, $font, $text);

            return;
        }

        // Built-in bitmap font fallback (ASCII only).
        $ascii = Str::ascii($text);
        $width = imagefontwidth(5) * strlen($ascii);
        imagestring($img, 5, (int) ((self::IMAGE_SIZE - $width) / 2), 1100, $ascii, $color);
    }

    private function fontPath(): ?string
    {
        foreach ([
            base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf'),
            base_path('vendor/tecnickcom/tcpdf/fonts/DejaVuSans.ttf'),
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function color(\GdImage $img, string $hex): int
    {
        [$r, $g, $b] = $this->rgb($hex);

        return imagecolorallocate($img, $r, $g, $b);
    }

    /** @return array{int, int, int} */
    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private function luminance(string $hex): float
    {
        [$r, $g, $b] = $this->rgb($hex);

        return (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    }

    private function mix(string $a, string $b, float $t): string
    {
        [$r1, $g1, $b1] = $this->rgb($a);
        [$r2, $g2, $b2] = $this->rgb($b);

        return sprintf('#%02X%02X%02X', (int) ($r1 + ($r2 - $r1) * $t), (int) ($g1 + ($g2 - $g1) * $t), (int) ($b1 + ($b2 - $b1) * $t));
    }
}
