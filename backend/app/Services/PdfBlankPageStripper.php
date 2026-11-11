<?php

/**
 * ============================================================
 * PdfBlankPageStripper — Drop empty pages from a PDF
 * ============================================================
 * Topex returns every 4x6 AWB as a two-page PDF where the second
 * page is blank, so printing a label wasted a sheet each time.
 *
 * Rather than "keep page 1", this removes only pages that are
 * actually blank and keeps everything else. That matters for a
 * multi-piece shipment, where a later page could be a real second
 * label — dropping it would leave a parcel unlabelled.
 *
 * Blankness is measured by rasterising the page and checking how
 * close it is to pure white, so it does not depend on how the
 * producer structured the PDF.
 *
 * Everything is best-effort: if Imagick, Ghostscript or FPDI is
 * missing or throws, the original file is left untouched. A label
 * with a spare page still ships; a corrupted one does not.
 * ============================================================
 */

namespace App\Services;

use Illuminate\Support\Facades\Log;
use setasign\Fpdi\Fpdi;

class PdfBlankPageStripper
{
    /**
     * Mean luminance above which a page counts as blank (1.0 = pure white).
     * Topex's empty page measures ~0.999; a page with a label on it measures
     * ~0.87, so the gap is wide and this threshold is not delicate.
     */
    private const BLANK_THRESHOLD = 0.995;

    /** Rasterise at a low DPI — enough to spot ink, cheap to render. */
    private const PROBE_DPI = 60;

    /**
     * Rewrite $absolutePath without its blank pages.
     *
     * @return int Number of pages removed (0 if nothing changed or it failed).
     */
    public function strip(string $absolutePath): int
    {
        if (! is_file($absolutePath) || ! extension_loaded('imagick')) {
            return 0;
        }

        try {
            $reader = new Fpdi;
            $pageCount = $reader->setSourceFile($absolutePath);

            // Nothing to gain, and never leave a zero-page PDF behind.
            if ($pageCount < 2) {
                return 0;
            }

            $keep = [];
            for ($page = 1; $page <= $pageCount; $page++) {
                if (! $this->isBlank($absolutePath, $page - 1)) {
                    $keep[] = $page;
                }
            }

            // No blanks found, or (defensively) every page read as blank —
            // in the latter case the probe is untrustworthy, so change nothing.
            if (count($keep) === $pageCount || count($keep) === 0) {
                return 0;
            }

            $pdf = new Fpdi;
            $pdf->setSourceFile($absolutePath);
            foreach ($keep as $page) {
                $template = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($template);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($template);
            }

            // Write via a temp file so a failure part-way cannot destroy the
            // label we already have.
            $temp = $absolutePath.'.stripped.tmp';
            $pdf->Output('F', $temp);

            if (! is_file($temp) || filesize($temp) === 0) {
                @unlink($temp);

                return 0;
            }

            if (! rename($temp, $absolutePath)) {
                @unlink($temp);

                return 0;
            }

            return $pageCount - count($keep);
        } catch (\Throwable $e) {
            Log::warning('PdfBlankPageStripper: failed, keeping original', [
                'file' => $absolutePath,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Is page $index (0-based) effectively blank?
     *
     * The page is flattened onto white first: a PDF page has no background of
     * its own, and leaving the alpha channel in place makes every page average
     * ~0.5 grey no matter how much ink is on it.
     */
    private function isBlank(string $absolutePath, int $index): bool
    {
        $image = null;

        try {
            $image = new \Imagick;
            $image->setResolution(self::PROBE_DPI, self::PROBE_DPI);
            $image->readImage($absolutePath.'['.$index.']');
            $image->setImageBackgroundColor('white');
            $flattened = $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $flattened->transformImageColorspace(\Imagick::COLORSPACE_GRAY);

            $mean = $flattened->getImageChannelMean(\Imagick::CHANNEL_ALL)['mean'] / 65535;

            $flattened->clear();
            $flattened->destroy();

            return $mean > self::BLANK_THRESHOLD;
        } catch (\Throwable $e) {
            // Could not read the page — treat it as content so it is kept.
            return false;
        } finally {
            if ($image instanceof \Imagick) {
                $image->clear();
                $image->destroy();
            }
        }
    }
}
