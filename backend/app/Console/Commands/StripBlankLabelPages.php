<?php

/**
 * ============================================================
 * STRIP BLANK LABEL PAGES — AWB PDF cleanup
 * ============================================================
 * Removes the blank trailing page from shipping labels that were
 * already downloaded before the stripping ran automatically.
 *
 * New labels are handled at download time (ShippingController);
 * this is only for the backlog.
 *
 * Safe to re-run: a label with no blank pages is left alone.
 *
 *   php artisan labels:strip-blank --dry-run
 *   php artisan labels:strip-blank
 * ============================================================
 */

namespace App\Console\Commands;

use App\Services\PdfBlankPageStripper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class StripBlankLabelPages extends Command
{
    protected $signature = 'labels:strip-blank
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Remove blank pages from already-downloaded AWB label PDFs';

    public function handle(PdfBlankPageStripper $stripper): int
    {
        if (! extension_loaded('imagick')) {
            $this->error('The imagick extension is required.');

            return self::FAILURE;
        }

        $disk = Storage::disk('public');
        $files = collect($disk->files('labels'))
            ->filter(fn (string $p): bool => str_ends_with(strtolower($p), '.pdf'));

        if ($files->isEmpty()) {
            $this->info('No label PDFs found.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $changed = $removed = $untouched = 0;

        foreach ($files as $path) {
            $absolute = $disk->path($path);

            if ($dryRun) {
                $pages = $this->pageCount($absolute);
                if ($pages > 1) {
                    $this->line("would check: {$path} ({$pages} pages)");
                    $changed++;
                } else {
                    $untouched++;
                }

                continue;
            }

            $dropped = $stripper->strip($absolute);
            if ($dropped > 0) {
                $changed++;
                $removed += $dropped;
            } else {
                $untouched++;
            }
        }

        $this->newLine();
        $verb = $dryRun ? 'multi-page' : 'cleaned';
        $this->info("files={$files->count()} {$verb}={$changed} pages_removed={$removed} untouched={$untouched}");

        return self::SUCCESS;
    }

    private function pageCount(string $absolutePath): int
    {
        try {
            $pdf = new \setasign\Fpdi\Fpdi;

            return $pdf->setSourceFile($absolutePath);
        } catch (\Throwable) {
            return 0;
        }
    }
}
