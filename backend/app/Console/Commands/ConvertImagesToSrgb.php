<?php

/**
 * ============================================================
 * CONVERT IMAGES TO sRGB — Fix over-saturated photos in email
 * ============================================================
 * What this command does:
 *   Rewrites ORIGINAL uploaded photos that carry a wide-gamut
 *   ICC profile (typically Display P3, from iPhone/Mac) into
 *   sRGB, remapping the pixels so they LOOK the same.
 *
 * Why:
 *   Browsers read the ICC profile and render P3 correctly — the
 *   website looks right. Email clients (Gmail, Outlook) ignore
 *   profiles and read P3 values as sRGB, which makes the same
 *   photo look over-saturated. Order-confirmation emails link to
 *   these originals, so they showed the over-contrast version.
 *
 * Safety:
 *   Converts via a temp file and only replaces the original once
 *   the result is a valid, non-empty image. Files already in sRGB
 *   (or with no profile at all) are skipped — running twice is a
 *   no-op. Use --dry-run first to see the scope.
 *
 * Manual run:
 *   php artisan images:to-srgb --dry-run          # report only
 *   php artisan images:to-srgb                    # convert
 *   php artisan images:to-srgb --folder=products
 * ============================================================
 */

namespace App\Console\Commands;

use App\Services\ImageResizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ConvertImagesToSrgb extends Command
{
    protected $signature = 'images:to-srgb
                            {--folder=* : Limit to specific folders (products, categories, assets). Default: all}
                            {--dry-run : Report what would change without writing anything}
                            {--limit=0 : Stop after N conversions (0 = no limit)}';

    protected $description = 'Convert wide-gamut (Display P3) originals to sRGB so they render correctly in email clients';

    private const DEFAULT_FOLDERS = ['products', 'categories', 'assets'];

    public function handle(ImageResizer $imageResizer): int
    {
        if (! extension_loaded('imagick')) {
            $this->error('The imagick extension is required for colour conversion.');

            return self::FAILURE;
        }

        $srgb = $this->srgbProfile();
        if ($srgb === null) {
            $this->error('sRGB profile not found at ' . resource_path('icc/sRGB.icc'));

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $folders = $this->option('folder') ?: self::DEFAULT_FOLDERS;
        $disk = Storage::disk('public');

        $scanned = $converted = $skipped = $failed = 0;

        foreach ($folders as $folder) {
            $directory = "uploads/{$folder}";
            if (! $disk->exists($directory)) {
                $this->warn("Skipping {$directory} — not found.");

                continue;
            }

            // Originals only. The _400/_800/_1200 variants are regenerated from
            // these by images:generate-variants --force, and the storefront (a
            // browser) renders them correctly either way.
            $files = collect($disk->files($directory))
                ->filter(fn (string $path): bool => (bool) preg_match('/\.(jpe?g|png|webp)$/i', $path))
                ->filter(fn (string $path): bool => ! preg_match('/_\d+\.webp$/i', $path));

            foreach ($files as $path) {
                if ($limit > 0 && $converted >= $limit) {
                    break 2;
                }

                $scanned++;
                $absolute = $disk->path($path);

                $profile = $this->iccDescription($absolute);
                if ($profile === null || $this->isSrgb($profile)) {
                    $skipped++;

                    continue;
                }

                if ($dryRun) {
                    $this->line("would convert: {$path}  [{$profile}]");
                    $converted++;

                    continue;
                }

                // Same implementation the upload path uses, so a backfilled
                // photo and a freshly uploaded one come out identical.
                if ($imageResizer->convertFileToSrgb($absolute)) {
                    $converted++;
                    if ($converted % 25 === 0) {
                        $this->info("converted {$converted}…");
                    }
                } else {
                    $failed++;
                    $this->warn("failed: {$path}");
                }
            }
        }

        $verb = $dryRun ? 'would convert' : 'converted';
        $this->newLine();
        $this->info("scanned={$scanned} {$verb}={$converted} skipped={$skipped} failed={$failed}");

        if (! $dryRun && $converted > 0) {
            $this->newLine();
            $this->comment('Originals are now sRGB. To bring the responsive variants in line:');
            $this->comment('  php artisan images:generate-variants --force');
        }

        return self::SUCCESS;
    }

    /** ICC profile description, or null when the file carries no profile. */
    private function iccDescription(string $absolutePath): ?string
    {
        try {
            $image = new \Imagick($absolutePath);
            $icc = $image->getImageProfiles('icc', true)['icc'] ?? null;
            $description = null;

            if (! empty($icc)) {
                // ImageMagick already parses the ICC 'desc' tag — ask it rather
                // than pattern-matching the binary, which picked up neighbouring
                // tag signatures ("prt", "tpt") instead of the profile name.
                $description = trim((string) $image->getImageProperty('icc:description'));
                if ($description === '') {
                    $description = 'unknown profile';
                }
            }

            $image->clear();
            $image->destroy();

            return $description;
        } catch (\Throwable) {
            return null;
        }
    }

    private function isSrgb(string $description): bool
    {
        return stripos($description, 'srgb') !== false;
    }

    private function srgbProfile(): ?string
    {
        $path = resource_path('icc/sRGB.icc');

        return is_file($path) ? (file_get_contents($path) ?: null) : null;
    }
}
