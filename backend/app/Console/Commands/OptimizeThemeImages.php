<?php

namespace App\Console\Commands;

use App\Models\SiteSetting;
use App\Services\ImageResizer;
use Illuminate\Console\Command;

/**
 * Shrinks oversized THEME images (hero / logo / about), which are uploaded as
 * raw full-size JPG/PNG and weigh 1MB+ each — the biggest page-weight problem.
 * Re-encodes each to an optimized WebP at a sensible max width, writes it as a
 * NEW file (so there's no Cloudflare cache collision with the old URL), builds
 * responsive variants, and repoints the site_setting to the new file.
 *
 * Safe + idempotent: skips settings already pointing at an *_opt.webp.
 *
 *   php artisan images:optimize-theme         # do it
 *   php artisan images:optimize-theme --dry   # preview only
 */
class OptimizeThemeImages extends Command
{
    protected $signature = 'images:optimize-theme {--dry : preview without writing}';

    protected $description = 'Re-encode oversized theme images (hero/logo/about) to optimized WebP and repoint the settings.';

    /** setting key (lowercase) => [maxWidth, webpQuality] */
    private array $targets = [
        'hero_image_url' => [1920, 80],
        'hero_image_url_en' => [1920, 80],
        'hero_image_url_ar' => [1920, 80],
        'about_hero_image_url' => [1920, 80],
        'logo_url' => [512, 88],
        // The favicon points at the SAME uploaded logo file (a 1MB PNG that the
        // browser fetches on every page as the tab icon). Re-encoding it to the
        // same 512px WebP as the logo turns a ~1MB favicon into ~12KB.
        'favicon_url' => [512, 88],
    ];

    public function handle(ImageResizer $resizer): int
    {
        $dry = (bool) $this->option('dry');
        $savedKb = 0;

        foreach ($this->targets as $key => [$maxW, $q]) {
            $setting = SiteSetting::whereRaw('LOWER(`key`) = ?', [$key])->first();
            if (! $setting || ! $setting->value) {
                continue;
            }

            $url = $setting->value;
            if (! preg_match('#/storage/(uploads/[^?\s]+\.(?:jpe?g|png|webp))$#i', $url, $m)) {
                $this->line("skip {$key}: not a /storage/uploads image");

                continue;
            }
            $rel = $m[1];
            if (preg_match('/_opt\.webp$/i', $rel)) {
                $this->line("skip {$key}: already optimized");

                continue;
            }

            $abs = storage_path('app/public/'.$rel);
            if (! is_file($abs)) {
                $this->warn("missing file for {$key}: {$abs}");

                continue;
            }

            $beforeKb = (int) round(filesize($abs) / 1024);
            $destRel = preg_replace('/\.(?:jpe?g|png|webp)$/i', '_opt.webp', $rel);
            $destAbs = storage_path('app/public/'.$destRel);

            if ($dry) {
                $this->line("{$key}: {$beforeKb}KB  ->  would write {$destRel}");

                continue;
            }

            if ($resizer->reencodeToWebp($abs, $destAbs, $maxW, $q)) {
                $afterKb = (int) round(filesize($destAbs) / 1024);
                $savedKb += max(0, $beforeKb - $afterKb);
                $resizer->generateVariants($destAbs); // responsive srcset variants
                $setting->value = str_replace($rel, $destRel, $url);
                $setting->save();
                $this->info("{$key}: {$beforeKb}KB -> {$afterKb}KB  ({$destRel})");
            } else {
                $this->error("{$key}: re-encode FAILED");
            }
        }

        if (! $dry) {
            $this->newLine();
            $this->info("Done. Saved ~{$savedKb}KB across theme images.");
        }

        return self::SUCCESS;
    }
}
