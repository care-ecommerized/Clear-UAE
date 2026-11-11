<?php

/**
 * ============================================================
 * SocialImage — Picks the og:image for a link preview
 * ============================================================
 * Link-preview crawlers (Facebook/Instagram/Messenger, WhatsApp,
 * X, Telegram, LinkedIn…) show a thumbnail only when og:image is:
 *   - an ABSOLUTE https URL (a "/storage/…" path is ignored), and
 *   - a format and size they accept. WhatsApp and Instagram drop
 *     WebP and anything much over ~300KB — and product originals
 *     here are often 1MB+ WebP.
 *
 * So we point at the purpose-made "_og.jpg" rendition (1200px,
 * sRGB JPEG — see ImageResizer::generateOgImage) and fall back to
 * the original, then to the site-wide default card, so a share is
 * never left without a thumbnail.
 * ============================================================
 */

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SocialImage
{
    /**
     * This environment's own origin (APP_URL via Brand::url()); staging
     * resolves to its own host. The _og.jpg files live on each environment's
     * own disk, so previews must point there — a staging link pointing at
     * production would 404 until production is backfilled too. Falls back to
     * the current request's origin if APP_URL is unset.
     */
    public static function base(): string
    {
        $url = Brand::url();
        if ($url === '' && app()->bound('request')) {
            $url = request()->getSchemeAndHttpHost();
        }

        return rtrim($url, '/');
    }

    /**
     * Resolve the preview image for $image (a full URL or a storage path),
     * falling back to the site-wide card — never nothing.
     *
     * @return array{url:string, width:?int, height:?int, type:string}
     */
    public static function resolve(?string $image): array
    {
        return self::resolveOrNull($image) ?? self::default();
    }

    /**
     * Like resolve(), but null when $image is empty or is one of our uploads
     * whose file no longer exists — so a caller can try its next candidate.
     *
     * @return array{url:string, width:?int, height:?int, type:string}|null
     */
    public static function resolveOrNull(?string $image): ?array
    {
        $image = trim((string) $image);
        if ($image === '') {
            return null;
        }

        $absolute = Str::startsWith($image, 'http')
            ? $image
            : self::base().'/'.ltrim($image, '/');

        // Only our own uploads have renditions; pass anything else through.
        if (! preg_match('#/storage/(uploads/[^?\#]+)$#', $absolute, $m) || ! self::isOwnHost($absolute)) {
            return ['url' => $absolute, 'width' => null, 'height' => null, 'type' => self::mimeFor($absolute)];
        }

        $relative = $m[1];
        // Rebuild on this environment's origin: rows saved under another
        // environment's host (or with/without www.) point at URLs that 404, and a crawler shows nothing for a dead
        // image. (A missing file here returns 200 + the SPA's HTML, not a 404.)
        $absolute = self::base().'/storage/'.$relative;
        $og = preg_replace('/(_\d+\.webp|_email\.jpg|_og\.jpg)?\.(webp|jpe?g|png)$/i', '_og.jpg', $relative);

        try {
            $disk = Storage::disk('public');
            if ($og && $disk->exists($og)) {
                $path = $disk->path($og);
                $size = @getimagesize($path);

                return [
                    // Versioned by mtime: .htaccess serves .jpg as immutable for
                    // a year, so Cloudflare (and Meta's preview cache) would keep
                    // the first copy of a regenerated file forever. A new mtime
                    // is a new URL.
                    'url' => str_replace($relative, $og, $absolute).'?v='.@filemtime($path),
                    'width' => $size ? (int) $size[0] : null,
                    'height' => $size ? (int) $size[1] : null,
                    'type' => 'image/jpeg',
                ];
            }

            // No rendition yet. Only offer the original if it is really there:
            // a missing upload returns 200 + the SPA's HTML, not a 404, so a
            // crawler would silently get no picture.
            if (! $disk->exists($relative)) {
                return null;
            }
        } catch (\Throwable) {
            // Storage unavailable — fall through to the original.
        }

        return ['url' => $absolute, 'width' => null, 'height' => null, 'type' => self::mimeFor($absolute)];
    }

    /** Absolute URL only — for JSON payloads that just need the link. */
    public static function url(?string $image): string
    {
        return self::resolve($image)['url'];
    }

    /**
     * The site-wide card: the share image from Admin → Branding, else the
     * logo (Brand::ogImageUrl()). An uploaded card gets its _og.jpg
     * rendition and real dimensions when it has one.
     *
     * @return array{url:string, width:?int, height:?int, type:string}
     */
    public static function default(): array
    {
        $url = Brand::ogImageUrl();
        if (str_contains($url, '/storage/uploads/') && self::isOwnHost($url)) {
            if ($own = self::resolveOrNull($url)) {
                return $own;
            }
        }

        return ['url' => $url, 'width' => null, 'height' => null, 'type' => self::mimeFor($url)];
    }

    /** Relative, or this environment's own host (APP_URL or the request's), www. ignored. */
    private static function isOwnHost(string $url): bool
    {
        $host = self::bareHost((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return true;
        }

        $own = [self::bareHost((string) parse_url(self::base(), PHP_URL_HOST))];
        if (app()->bound('request')) {
            $own[] = self::bareHost(request()->getHost());
        }

        return in_array($host, array_filter($own), true);
    }

    private static function bareHost(string $host): string
    {
        return (string) preg_replace('/^www\./', '', strtolower($host));
    }

    private static function mimeFor(string $url): string
    {
        return match (strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }
}
