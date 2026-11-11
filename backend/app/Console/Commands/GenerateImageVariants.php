<?php

/**
 * ============================================================
 * GENERATE IMAGE VARIANTS — Responsive WebP backfill
 * ============================================================
 * What this command does:
 *   Walks the uploaded-image folders and generates the small
 *   responsive WebP copies (_400 / _800 / _1200) used by the
 *   storefront's <img srcset>. New uploads get these variants
 *   automatically (UploadController) — this command is for the
 *   images that already existed before the feature shipped.
 *
 * Idempotent: existing variants are skipped unless --force.
 *
 * Manual run:
 *   php artisan images:generate-variants               # all image folders
 *   php artisan images:generate-variants --force       # regenerate everything
 *   php artisan images:generate-variants --folder=products
 * ============================================================
 */

namespace App\Console\Commands;

use App\Services\ImageResizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class GenerateImageVariants extends Command
{
    protected $signature = 'images:generate-variants
                            {--folder=* : Limit to specific folders (products, categories, assets). Default: all image folders}
                            {--force : Regenerate variants even if they already exist}';

    protected $description = 'Generate responsive WebP variants (_400/_800/_1200) for existing uploaded images';

    /** Image folders under storage/app/public/uploads that the storefront resizes. */
    private const DEFAULT_FOLDERS = ['products', 'categories', 'assets'];

    public function handle(ImageResizer $imageResizer): int
    {
        $folders = $this->option('folder') ?: self::DEFAULT_FOLDERS;
        $force = (bool) $this->option('force');

        $disk = Storage::disk('public');
        $totalSources = 0;
        $totalVariants = 0;

        foreach ($folders as $folder) {
            $directory = "uploads/{$folder}";

            if (! $disk->exists($directory)) {
                $this->warn("Skipping {$directory} (not found)");

                continue;
            }

            // Original webp/jpg/png files — skip already-generated variants.
            // (JPG/PNG sources get WebP variants beside them: abc.jpg → abc_400.webp.)
            $sources = collect($disk->files($directory))
                ->filter(fn (string $path): bool => preg_match('/\.(webp|jpe?g|png)$/i', $path)
                    && ! $imageResizer->isVariantPath($path));

            $this->info("Processing {$directory} — {$sources->count()} source image(s)");
            $bar = $this->output->createProgressBar($sources->count());
            $bar->start();

            foreach ($sources as $path) {
                $written = $imageResizer->generateVariants($disk->path($path), $force);
                $totalSources++;
                $totalVariants += count($written);
                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);
        }

        $this->info("Done. {$totalSources} source image(s), {$totalVariants} variant(s) ensured.");

        return self::SUCCESS;
    }
}
