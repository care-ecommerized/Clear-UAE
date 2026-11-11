<?php

/**
 * ============================================================
 * GenerateOgImages — backfill social-share preview images
 * ============================================================
 * Writes the "_og.jpg" rendition (1200px sRGB JPEG, see
 * ImageResizer::generateOgImage) for every image a shared link can
 * preview: visible products' main image, plus categories.
 *
 * New uploads get one automatically (UploadController runs
 * generateVariants), so this is only needed once for existing
 * images, or with --force after changing the size/quality.
 *
 * Deliberately narrower than images:generate-variants, which walks
 * every file under uploads/ (tens of thousands on production).
 *
 *   php artisan images:generate-og
 *   php artisan images:generate-og --force
 * ============================================================
 */

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Services\ImageResizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class GenerateOgImages extends Command
{
    protected $signature = 'images:generate-og
                            {--force : Regenerate even if the _og.jpg already exists}';

    protected $description = 'Generate the _og.jpg social-share preview for product and category images';

    public function handle(ImageResizer $imageResizer): int
    {
        $force = (bool) $this->option('force');
        $disk = Storage::disk('public');

        $images = Product::visibleToCustomers()->get(['image_url', 'images'])
            ->map(fn (Product $p) => $p->image_url ?: (is_array($p->images) ? ($p->images[0] ?? null) : null))
            ->merge(Category::whereNotNull('image_url')->pluck('image_url'))
            ->filter()
            ->map(fn (string $url) => preg_match('#/?storage/(uploads/[^?\#]+)$#', $url, $m) ? $m[1] : null)
            ->filter()
            ->unique()
            ->values();

        $this->info("{$images->count()} image(s) referenced by products and categories");
        $bar = $this->output->createProgressBar($images->count());
        $bar->start();

        $ok = 0;
        $failed = [];
        foreach ($images as $relative) {
            if ($disk->exists($relative) && $imageResizer->generateOgImage($disk->path($relative), $force)) {
                $ok++;
            } else {
                $failed[] = $relative;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. {$ok} preview image(s) ensured, ".count($failed).' failed.');
        foreach ($failed as $f) {
            $this->warn("  failed: {$f}");
        }

        return self::SUCCESS;
    }
}
