<?php

/**
 * ============================================================
 * ImageResizer — Responsive WebP variant generator
 * ============================================================
 * For a source image (e.g. "uploads/products/abc.webp") this
 * writes smaller WebP copies next to it:
 *
 *   abc.webp        (original, untouched)
 *   abc_400.webp    (grid thumbnails / phones)
 *   abc_800.webp    (product detail / tablets)
 *   abc_1200.webp   (large & retina screens)
 *
 * The frontend points <img srcset> at these so the browser
 * downloads only the size it actually needs. We NEVER upscale
 * beyond the source width, and quality is kept visually lossless.
 *
 * Prefers the Imagick extension (best WebP quality); falls back
 * to GD when Imagick is unavailable. If neither can write WebP
 * the method returns an empty list and the caller carries on —
 * an upload must never fail because resizing failed.
 * ============================================================
 */

namespace App\Services;

use Illuminate\Support\Facades\Log;

class ImageResizer
{
    /** Widths (px) generated for the <img srcset>. */
    public const WIDTHS = [400, 800, 1200];

    /**
     * Per-width WebP encode quality. The small grid thumbnail (_400) is only
     * ever shown tiny, so aggressive compression is imperceptible and slashes
     * bytes; the larger, zoomable variants (_800/_1200) keep higher quality so
     * watch dial text/markings stay crisp (do not drop _800/_1200 below 72).
     * Combined with webp:method=6 (max encoder effort) this keeps variants
     * small without visible artifacts. Re-run `images:generate-variants --force`
     * after changing so existing variants are re-encoded.
     */
    private const WEBP_QUALITY_BY_WIDTH = [400 => 62, 800 => 72, 1200 => 74];

    /** Fallback quality for any width not in the map above. */
    private const WEBP_QUALITY = 72;

    /**
     * Width of the JPEG thumbnail used in emails.
     *
     * Emails cannot use the WebP variants: Outlook on Windows does not render
     * WebP at all. They also must not use the original — a 2 MB, 3000px photo
     * squeezed into a 48px cell downloads slowly and is downscaled crudely by
     * mail clients, which is what makes product shots look harsh in an inbox.
     */
    public const EMAIL_WIDTH = 400;

    /**
     * Width of the JPEG used as the social-share preview (og:image).
     *
     * Link-preview crawlers (Facebook/Instagram/Messenger, WhatsApp, X, …) are
     * far pickier than browsers: WhatsApp and Instagram silently drop WebP and
     * anything much over ~300KB, and the originals here are often 1MB+ WebP.
     * 1200px is Facebook's recommended width for the large link card.
     */
    public const OG_WIDTH = 1200;

    /**
     * Height cap for the social preview. Product shots here are tall portraits
     * (1200×2134 at full width, ~600KB); fitting into a 1200×1200 box keeps the
     * whole watch in frame and the file small.
     */
    public const OG_MAX_HEIGHT = 1200;

    /** Starting JPEG quality for the social preview; lowered if over the cap. */
    private const OG_JPEG_QUALITY = 82;

    /** Byte ceiling for the social preview — WhatsApp drops larger thumbnails. */
    private const OG_MAX_BYTES = 300 * 1024;

    /** Never step the quality below this chasing the byte cap. */
    private const OG_MIN_JPEG_QUALITY = 60;

    /** JPEG quality for the email thumbnail — small, but no visible artifacts. */
    private const EMAIL_JPEG_QUALITY = 82;

    /** Resolve the WebP quality to use for a given variant width. */
    private function qualityForWidth(int $width): int
    {
        return self::WEBP_QUALITY_BY_WIDTH[$width] ?? self::WEBP_QUALITY;
    }

    /**
     * Convert an image to sRGB, remapping the pixels through its embedded
     * profile so it LOOKS the same afterwards.
     *
     * iPhone/Mac photos are usually Display P3 (wide gamut). We used to keep
     * that profile, which browsers honour — the site looked right. But email
     * clients (Gmail, Outlook) ignore ICC profiles entirely and read P3 pixel
     * values as if they were sRGB, which is what made product photos in order
     * emails look over-saturated. Converting to sRGB is correct in both:
     * colour-managed viewers get an accurate profile, and non-managed ones
     * get pixels that were already sRGB to begin with.
     *
     * Must run BEFORE stripImage(), while the source profile is still attached
     * — that profile is what the conversion reads from.
     */
    private function convertToSrgb(\Imagick $image, ?string $sourceIcc): void
    {
        // Untagged images are already treated as sRGB everywhere; nothing to do.
        if (empty($sourceIcc)) {
            return;
        }

        $srgb = $this->srgbProfile();
        if ($srgb === null) {
            return;
        }

        try {
            $image->profileImage('icc', $srgb);
        } catch (\Throwable $e) {
            // Conversion failed — better to carry on and write the variant than
            // to fail the upload over a colour profile.
            Log::warning('ImageResizer: sRGB conversion failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Rewrite a file in place as sRGB, remapping the pixels through its
     * embedded profile so it looks identical.
     *
     * Needed on the ORIGINAL, not just the variants: order emails link to the
     * original upload, and email clients ignore ICC profiles — a Display P3
     * original renders over-saturated there no matter how the variants are
     * encoded.
     *
     * No-ops (returns false) when the file is untagged or already sRGB, so it
     * is safe to call on every upload and safe to run repeatedly.
     */
    public function convertFileToSrgb(string $absolutePath): bool
    {
        if (! is_file($absolutePath) || ! extension_loaded('imagick')) {
            return false;
        }
        if (! preg_match('/\.(jpe?g|png|webp)$/i', $absolutePath)) {
            return false;
        }

        $srgb = $this->srgbProfile();
        if ($srgb === null) {
            return false;
        }

        $temp = $absolutePath . '.srgb.tmp';
        $image = null;

        try {
            $image = new \Imagick($absolutePath);
            $icc = $image->getImageProfiles('icc', true)['icc'] ?? null;

            // Untagged images are already treated as sRGB by every renderer.
            if (empty($icc)) {
                return false;
            }
            if (stripos(trim((string) $image->getImageProperty('icc:description')), 'srgb') !== false) {
                return false;
            }

            $image->setFirstIterator();
            $image->profileImage('icc', $srgb);
            $image->stripImage();

            $ok = $image->writeImage($temp);

            // Never replace a good photo with a truncated one.
            if (! $ok || ! is_file($temp) || filesize($temp) === 0 || @getimagesize($temp) === false) {
                @unlink($temp);

                return false;
            }

            return rename($temp, $absolutePath);
        } catch (\Throwable $e) {
            @unlink($temp);
            Log::warning('ImageResizer: sRGB file conversion failed', [
                'path' => $absolutePath,
                'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            if ($image instanceof \Imagick) {
                $image->clear();
                $image->destroy();
            }
        }
    }

    /** The bundled sRGB IEC61966-2.1 profile, read once per process. */
    private function srgbProfile(): ?string
    {
        static $profile = null;
        static $loaded = false;

        if ($loaded) {
            return $profile;
        }
        $loaded = true;

        $path = resource_path('icc/sRGB.icc');
        $profile = is_file($path) ? (file_get_contents($path) ?: null) : null;

        if ($profile === null) {
            Log::warning('ImageResizer: sRGB profile missing', ['path' => $path]);
        }

        return $profile;
    }

    /**
     * Generate the resized WebP variants for one source image.
     *
     * @return list<int> The widths actually written (or already present).
     */
    public function generateVariants(string $absolutePath, bool $force = false): array
    {
        if (! is_file($absolutePath)) {
            return [];
        }

        // Skip files that are already variants ("name_400.webp") so a backfill
        // run never produces "name_400_400.webp".
        if ($this->isVariantPath($absolutePath)) {
            return [];
        }

        // Variants are always WebP, but the source may be webp, jpg or png —
        // legacy product photos were uploaded as multi-MB JPGs and used to be
        // served raw ("abc.jpg" now gets "abc_400.webp" etc. beside it).
        if (! preg_match('/\.(webp|jpe?g|png)$/i', $absolutePath)) {
            return [];
        }

        $dimensions = @getimagesize($absolutePath);
        if ($dimensions === false || ($dimensions[0] ?? 0) < 1) {
            return [];
        }
        $sourceWidth = (int) $dimensions[0];

        $written = [];
        foreach (self::WIDTHS as $width) {
            $variantPath = $this->variantPath($absolutePath, $width);

            // A non-empty existing variant is good; a 0-byte one is the debris
            // of a prior failed write (Imagick can leave an empty file when it
            // aborts) — treat it as missing so this run regenerates it.
            if (! $force && is_file($variantPath) && filesize($variantPath) > 0) {
                $written[] = $width;

                continue;
            }

            // Never upscale: cap the target at the source width.
            $targetWidth = min($width, $sourceWidth);

            if ($this->writeResized($absolutePath, $variantPath, $targetWidth)) {
                $written[] = $width;
            }
        }

        // Email thumbnail and social-share preview ride along with the
        // responsive variants so a single backfill (or upload) produces
        // everything the site, the emails and link previews need.
        $this->generateEmailThumbnail($absolutePath, $force);
        $this->generateOgImage($absolutePath, $force);

        return $written;
    }

    /**
     * Re-encode ANY supported image (jpg/png/webp/gif) into an optimized WebP,
     * capped at $maxWidth (never upscaled), at the given quality. Used to shrink
     * oversized theme/hero/logo images that were uploaded as raw JPG/PNG. Writes
     * to $destination and returns true on success. Preserves transparency.
     */
    public function reencodeToWebp(string $source, string $destination, int $maxWidth, int $quality = 80): bool
    {
        if (! is_file($source)) {
            return false;
        }
        $dim = @getimagesize($source);
        if ($dim === false || ($dim[0] ?? 0) < 1) {
            return false;
        }
        $targetWidth = min($maxWidth, (int) $dim[0]);

        if (extension_loaded('imagick')) {
            try {
                $image = new \Imagick($source);
                $image->setFirstIterator();
                // Convert to sRGB (see convertToSrgb) so the result renders the
                // same in browsers AND in email clients, which ignore profiles.
                $icc = $image->getImageProfiles('icc', true)['icc'] ?? null;
                $sourceWidth = max(1, $image->getImageWidth());
                $targetHeight = (int) round($image->getImageHeight() * ($targetWidth / $sourceWidth));
                $image->resizeImage($targetWidth, max(1, $targetHeight), \Imagick::FILTER_LANCZOS, 1);
                $image->setImageFormat('webp');
                $image->setOption('webp:method', '6');
                $image->setImageCompressionQuality($quality);
                $this->convertToSrgb($image, $icc);
                $image->stripImage();
                $ok = $image->writeImage($destination);
                $image->clear();
                $image->destroy();

                return $ok;
            } catch (\Throwable $e) {
                Log::warning('ImageResizer: reencode (imagick) failed', ['source' => $source, 'error' => $e->getMessage()]);

                return false;
            }
        }

        if (function_exists('imagewebp')) {
            $src = null;
            $dst = null;
            try {
                $mime = $dim['mime'] ?? '';
                $src = match ($mime) {
                    'image/webp' => imagecreatefromwebp($source),
                    'image/jpeg' => imagecreatefromjpeg($source),
                    'image/png' => imagecreatefrompng($source),
                    'image/gif' => imagecreatefromgif($source),
                    default => null,
                };
                if (! $src) {
                    return false;
                }
                $sw = max(1, imagesx($src));
                $sh = max(1, imagesy($src));
                $th = max(1, (int) round($sh * ($targetWidth / $sw)));
                $dst = imagecreatetruecolor($targetWidth, $th);
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $targetWidth, $th, $sw, $sh);

                return imagewebp($dst, $destination, $quality);
            } catch (\Throwable $e) {
                Log::warning('ImageResizer: reencode (gd) failed', ['source' => $source, 'error' => $e->getMessage()]);

                return false;
            } finally {
                if ($src) {
                    imagedestroy($src);
                }
                if ($dst) {
                    imagedestroy($dst);
                }
            }
        }

        return false;
    }

    /**
     * Delete the variants belonging to a source image (used when an upload is removed).
     */
    public function deleteVariants(string $absolutePath): void
    {
        foreach (self::WIDTHS as $width) {
            $variantPath = $this->variantPath($absolutePath, $width);
            if (is_file($variantPath)) {
                @unlink($variantPath);
            }
        }
    }

    public function isVariantPath(string $path): bool
    {
        return (bool) preg_match('/(_\d+\.webp|_email\.jpg|_og\.jpg)$/i', $path);
    }

    /**
     * Path of the email thumbnail beside a source image:
     * "abc.jpg" → "abc_email.jpg".
     */
    public function emailThumbnailPath(string $path): string
    {
        return preg_replace('/\.(webp|jpe?g|png)$/i', '_email.jpg', $path);
    }

    /**
     * Path of the social-share preview beside a source image:
     * "abc.webp" → "abc_og.jpg".
     */
    public function ogImagePath(string $path): string
    {
        return preg_replace('/\.(webp|jpe?g|png)$/i', '_og.jpg', $path);
    }

    /**
     * Write the small JPEG used by email templates.
     *
     * JPEG rather than WebP for Outlook, and generated here rather than letting
     * the mail client downscale the original, so the resampling is done well.
     * Never upscales.
     */
    public function generateEmailThumbnail(string $absolutePath, bool $force = false): bool
    {
        return $this->writeJpegRendition(
            $absolutePath,
            $this->emailThumbnailPath($absolutePath),
            self::EMAIL_WIDTH,
            null,
            self::EMAIL_JPEG_QUALITY,
            null,
            $force,
        );
    }

    /**
     * Write the JPEG used as og:image / twitter:image for link previews:
     * fits a 1200×1200 box and stays under OG_MAX_BYTES. Never upscales.
     */
    public function generateOgImage(string $absolutePath, bool $force = false): bool
    {
        return $this->writeJpegRendition(
            $absolutePath,
            $this->ogImagePath($absolutePath),
            self::OG_WIDTH,
            self::OG_MAX_HEIGHT,
            self::OG_JPEG_QUALITY,
            self::OG_MAX_BYTES,
            $force,
        );
    }

    /**
     * Resize $absolutePath to fit $maxWidth (and $maxHeight when given), flatten
     * onto white, convert to sRGB and save as a baseline JPEG at $destination.
     * With $maxBytes, steps the quality down until the file fits (not below
     * OG_MIN_JPEG_QUALITY). Idempotent unless $force.
     */
    private function writeJpegRendition(string $absolutePath, string $destination, int $maxWidth, ?int $maxHeight, int $quality, ?int $maxBytes, bool $force): bool
    {
        if (! is_file($absolutePath) || $this->isVariantPath($absolutePath)) {
            return false;
        }
        if (! preg_match('/\.(webp|jpe?g|png)$/i', $absolutePath)) {
            return false;
        }

        if (! $force && is_file($destination) && filesize($destination) > 0) {
            return true;
        }

        $dimensions = @getimagesize($absolutePath);
        if ($dimensions === false || ($dimensions[0] ?? 0) < 1) {
            return false;
        }
        $sourceW = (int) $dimensions[0];
        $sourceH = max(1, (int) ($dimensions[1] ?? 1));
        $scale = min(1.0, $maxWidth / $sourceW, $maxHeight ? $maxHeight / $sourceH : 1.0);
        $targetWidth = max(1, (int) round($sourceW * $scale));

        if (extension_loaded('imagick') && $this->writeJpegWithImagick($absolutePath, $destination, $targetWidth, $quality, $maxBytes)) {
            return true;
        }

        // Imagick is missing or failed — on this shared host it intermittently
        // does on big phone photos (4032px), and nothing retried, so ~70% of
        // products had no _og.jpg and shared links showed only a spinner. GD
        // can't remap colour profiles, but a slightly-off thumbnail beats none.
        return function_exists('imagejpeg')
            && $this->writeJpegWithGd($absolutePath, $destination, $targetWidth, $quality, $maxBytes);
    }

    private function writeJpegWithImagick(string $absolutePath, string $destination, int $targetWidth, int $quality, ?int $maxBytes): bool
    {
        $image = null;

        try {
            $image = new \Imagick($absolutePath);
            $image->setFirstIterator();

            $icc = $image->getImageProfiles('icc', true)['icc'] ?? null;

            $sourceWidth = max(1, $image->getImageWidth());
            $targetHeight = (int) round($image->getImageHeight() * ($targetWidth / $sourceWidth));
            $image->resizeImage($targetWidth, max(1, $targetHeight), \Imagick::FILTER_LANCZOS, 1);

            // Flatten onto white before dropping alpha — a transparent PNG
            // would otherwise get a black background once saved as JPEG.
            $image->setImageBackgroundColor('white');
            $image = $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);

            $this->convertToSrgb($image, $icc);
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality($quality);
            $image->stripImage();

            $temp = $destination.'.tmp';
            $ok = $image->writeImage($temp);

            while ($ok && $maxBytes && $quality > self::OG_MIN_JPEG_QUALITY && $this->freshSize($temp) > $maxBytes) {
                $quality = max(self::OG_MIN_JPEG_QUALITY, $quality - 8);
                $image->setImageCompressionQuality($quality);
                $ok = $image->writeImage($temp);
            }

            if (! $ok || $this->freshSize($temp) === 0) {
                @unlink($temp);

                return false;
            }

            return rename($temp, $destination);
        } catch (\Throwable $e) {
            @unlink($destination.'.tmp');
            Log::warning('ImageResizer: JPEG rendition failed', [
                'source' => $absolutePath,
                'destination' => $destination,
                'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            if ($image instanceof \Imagick) {
                $image->clear();
                $image->destroy();
            }
        }
    }

    /** GD fallback for writeJpegRendition(): same size, white background and byte cap. */
    private function writeJpegWithGd(string $absolutePath, string $destination, int $targetWidth, int $quality, ?int $maxBytes): bool
    {
        // A 4032px photo decodes to ~65MB of pixels in GD.
        $previousLimit = ini_get('memory_limit');
        @ini_set('memory_limit', '512M');

        $sourceImage = null;
        $destinationImage = null;
        $temp = $destination.'.tmp';

        try {
            $sourceImage = match (getimagesize($absolutePath)['mime'] ?? '') {
                'image/webp' => imagecreatefromwebp($absolutePath),
                'image/jpeg' => imagecreatefromjpeg($absolutePath),
                'image/png' => imagecreatefrompng($absolutePath),
                default => null,
            };
            if (! $sourceImage) {
                return false;
            }

            $sourceWidth = max(1, imagesx($sourceImage));
            $sourceHeight = max(1, imagesy($sourceImage));
            $targetHeight = max(1, (int) round($sourceHeight * ($targetWidth / $sourceWidth)));

            // Flatten onto white — JPEG has no alpha, so transparency would turn black.
            $destinationImage = imagecreatetruecolor($targetWidth, $targetHeight);
            imagefill($destinationImage, 0, 0, imagecolorallocate($destinationImage, 255, 255, 255));
            imagealphablending($destinationImage, true);
            imagecopyresampled($destinationImage, $sourceImage, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
            imageinterlace($destinationImage, false);

            $ok = imagejpeg($destinationImage, $temp, $quality);
            while ($ok && $maxBytes && $quality > self::OG_MIN_JPEG_QUALITY && $this->freshSize($temp) > $maxBytes) {
                $quality = max(self::OG_MIN_JPEG_QUALITY, $quality - 8);
                $ok = imagejpeg($destinationImage, $temp, $quality);
            }

            if (! $ok || $this->freshSize($temp) === 0) {
                @unlink($temp);

                return false;
            }

            return rename($temp, $destination);
        } catch (\Throwable $e) {
            @unlink($temp);
            Log::warning('ImageResizer: GD JPEG rendition failed', [
                'source' => $absolutePath,
                'destination' => $destination,
                'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            if ($sourceImage) {
                imagedestroy($sourceImage);
            }
            if ($destinationImage) {
                imagedestroy($destinationImage);
            }
            @ini_set('memory_limit', (string) $previousLimit);
        }
    }

    /** filesize() without PHP's stat cache — the file is rewritten in place. */
    private function freshSize(string $path): int
    {
        clearstatcache(true, $path);

        return is_file($path) ? (int) filesize($path) : 0;
    }

    private function variantPath(string $path, int $width): string
    {
        // Variants are always .webp regardless of the source format:
        // "abc.webp" → "abc_400.webp", "abc.jpg" → "abc_400.webp".
        return preg_replace('/\.(webp|jpe?g|png)$/i', "_{$width}.webp", $path);
    }

    private function writeResized(string $source, string $destination, int $targetWidth): bool
    {
        // Try Imagick first (best WebP quality), then GD. Either engine can
        // fail intermittently on a shared host (memory/resource limits) and
        // leave a 0-byte file behind, so treat "wrote nothing" as failure,
        // delete the debris, and let the next engine try.
        if (extension_loaded('imagick')
            && $this->wroteNonEmpty($destination, fn () => $this->resizeWithImagick($source, $destination, $targetWidth))) {
            return true;
        }

        if (function_exists('imagewebp')
            && $this->wroteNonEmpty($destination, fn () => $this->resizeWithGd($source, $destination, $targetWidth))) {
            return true;
        }

        // Never leave an empty/partial variant on disk — it would be served as
        // a broken image AND skipped as "already present" on the next backfill.
        if (is_file($destination) && filesize($destination) === 0) {
            @unlink($destination);
        }

        return false;
    }

    /**
     * Run a writer callback and confirm it produced a non-empty file. If the
     * callback returns false or the output is 0 bytes, the empty file is
     * removed so a fallback engine (or a later re-run) can regenerate it.
     */
    private function wroteNonEmpty(string $destination, callable $writer): bool
    {
        $ok = (bool) $writer();
        if ($ok && is_file($destination) && filesize($destination) > 0) {
            return true;
        }
        if (is_file($destination)) {
            @unlink($destination);
        }

        return false;
    }

    private function resizeWithImagick(string $source, string $destination, int $targetWidth): bool
    {
        try {
            $image = new \Imagick($source);
            $image->setFirstIterator();

            // Remap wide-gamut pixels into sRGB while the source profile is
            // still attached (see convertToSrgb), THEN strip metadata. Stripping
            // without converting was the original over-saturation bug; keeping
            // the P3 profile fixed browsers but not email clients.
            $icc = $image->getImageProfiles('icc', true)['icc'] ?? null;

            $sourceWidth = max(1, $image->getImageWidth());
            $targetHeight = (int) round($image->getImageHeight() * ($targetWidth / $sourceWidth));

            $image->resizeImage($targetWidth, max(1, $targetHeight), \Imagick::FILTER_LANCZOS, 1);
            $image->setImageFormat('webp');
            $image->setOption('webp:method', '6');
            $image->setImageCompressionQuality($this->qualityForWidth($targetWidth));

            $this->convertToSrgb($image, $icc);
            // Safe to drop everything now: the pixels ARE sRGB, and an untagged
            // image is treated as sRGB by every browser and email client.
            $image->stripImage();

            $ok = $image->writeImage($destination);

            $image->clear();
            $image->destroy();

            return $ok;
        } catch (\Throwable $e) {
            Log::warning('ImageResizer: Imagick resize failed', ['source' => $source, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function resizeWithGd(string $source, string $destination, int $targetWidth): bool
    {
        $sourceImage = null;
        $destinationImage = null;

        try {
            $info = getimagesize($source);
            $mime = $info['mime'] ?? '';

            $sourceImage = match ($mime) {
                'image/webp' => imagecreatefromwebp($source),
                'image/jpeg' => imagecreatefromjpeg($source),
                'image/png' => imagecreatefrompng($source),
                'image/gif' => imagecreatefromgif($source),
                default => null,
            };

            if (! $sourceImage) {
                return false;
            }

            $sourceWidth = max(1, imagesx($sourceImage));
            $sourceHeight = max(1, imagesy($sourceImage));
            $targetHeight = max(1, (int) round($sourceHeight * ($targetWidth / $sourceWidth)));

            $destinationImage = imagecreatetruecolor($targetWidth, $targetHeight);
            // Preserve transparency for PNG/WebP sources.
            imagealphablending($destinationImage, false);
            imagesavealpha($destinationImage, true);

            imagecopyresampled(
                $destinationImage,
                $sourceImage,
                0, 0, 0, 0,
                $targetWidth, $targetHeight,
                $sourceWidth, $sourceHeight
            );

            return imagewebp($destinationImage, $destination, $this->qualityForWidth($targetWidth));
        } catch (\Throwable $e) {
            Log::warning('ImageResizer: GD resize failed', ['source' => $source, 'error' => $e->getMessage()]);

            return false;
        } finally {
            if ($sourceImage) {
                imagedestroy($sourceImage);
            }
            if ($destinationImage) {
                imagedestroy($destinationImage);
            }
        }
    }
}
