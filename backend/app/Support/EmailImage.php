<?php

/**
 * ============================================================
 * EmailImage — Picks the right product image URL for an email
 * ============================================================
 * Email templates must not link the original upload. A 2 MB,
 * 3000px photo dropped into a 48px cell is slow on mobile data
 * and is downscaled crudely by mail clients, which is what makes
 * product shots look harsh in an inbox.
 *
 * They also must not use the _400/_800 WebP variants: Outlook on
 * Windows cannot render WebP at all.
 *
 * So we point at a purpose-made "_email.jpg" thumbnail (400px,
 * sRGB, JPEG) and fall back to the original when one has not been
 * generated yet — an email must never show a broken image.
 * ============================================================
 */

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EmailImage
{
    /**
     * Absolute URL of the best image to use for $productImage in an email.
     *
     * Accepts either a full URL or a storage-relative path, matching what is
     * stored on order_items.product_image across the years.
     */
    public static function url(?string $productImage): ?string
    {
        $productImage = trim((string) $productImage);
        if ($productImage === '') {
            return null;
        }

        $absolute = Str::startsWith($productImage, 'http')
            ? $productImage
            : rtrim((string) config('app.url'), '/').'/'.ltrim($productImage, '/');

        // Only uploads have thumbnails; anything else is passed through.
        if (! preg_match('#/storage/(uploads/[^?\#]+)$#', $absolute, $m)) {
            return $absolute;
        }

        $relative = $m[1];
        if (preg_match('/(_\d+\.webp|_email\.jpg|_og\.jpg)$/i', $relative)) {
            return $absolute;
        }

        $thumb = preg_replace('/\.(webp|jpe?g|png)$/i', '_email.jpg', $relative);
        if ($thumb === null || $thumb === $relative) {
            return $absolute;
        }

        try {
            if (Storage::disk('public')->exists($thumb)) {
                return str_replace($relative, $thumb, $absolute);
            }
        } catch (\Throwable) {
            // Storage unavailable — fall through to the original.
        }

        return $absolute;
    }
}
