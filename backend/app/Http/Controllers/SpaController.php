<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Support\Brand as StoreBrand;
use App\Support\SocialImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ============================================================
 * SpaController — serves the React SPA shell with per-page SEO
 * ============================================================
 * The frontend is a single-page app: every route loads the same
 * index.html and React renders the page. Crawlers and social
 * scrapers (Facebook/WhatsApp/Twitter, Bing, the initial Google
 * pass) do NOT run JS, so they'd otherwise see an empty shell with
 * a generic title and no description/Open Graph/structured data.
 *
 * This controller reads the built index.html and injects route-aware
 * <title>, meta description, canonical, hreflang, Open Graph, Twitter,
 * and JSON-LD BEFORE sending it — so crawlers get real metadata while
 * real users still get the SPA (react-helmet manages tags on the
 * client after hydration). If anything fails it falls back to the raw
 * shell, so the site never breaks over SEO.
 * ============================================================
 */
class SpaController extends Controller
{
    /** Public origin, no trailing slash — set in index(). */
    private string $base = '';

    public function index(Request $request)
    {
        $this->base = SocialImage::base();

        // The deployed SPA shell lives at the web root (one level above Laravel's
        // base_path, which is .../backend). cp -r dist/* . puts it there.
        $indexPath = base_path('../index.html');
        if (! is_file($indexPath)) {
            return response('Frontend not found. Deploy the dist files to the web root.', 404);
        }

        $html = (string) file_get_contents($indexPath);

        try {
            if ($tags = $this->buildTags($request)) {
                $html = $this->stripPrerenderedSeo($html);
                // Tags go right after <title>, NOT before </head>: the prerendered
                // shell carries ~100KB of inline query state in <head>, and
                // WhatsApp only reads the start of a page — it saw the title but
                // never reached og:image, so it previewed the site icon instead.
                // Callback, not a replacement string: a title containing "$1"
                // or a backslash must be inserted literally.
                $title = '<title>'.$tags['title'].'</title>'.$tags['head'];
                $count = 0;
                $html = preg_replace_callback('#<title[^>]*>.*?</title>#is', fn () => $title, $html, 1, $count) ?? $html;
                if ($count === 0) {
                    $html = str_replace('</head>', $tags['head'].'</head>', $html);
                }
            }
        } catch (\Throwable $e) {
            // Never break a page over SEO — serve the raw shell as-is.
        }

        try {
            $html = $this->injectFavicon($html);
        } catch (\Throwable $e) {
            // Keep the bundled icons.
        }

        // This controller only serves routes WITHOUT a prerendered file (admin,
        // checkout, cart, account, …). The web-root index.html carries the
        // prerendered HOMEPAGE in its <body>, so those pages flashed the
        // homepage until React mounted. Empty #root for everything but home:
        // main.tsx then renders fresh (createRoot) instead of hydrating.
        try {
            $path = '/'.trim($request->path(), '/');
            if (! in_array($path, ['/', '/en', '/ar'], true)) {
                $html = $this->emptyPrerenderedRoot($html);
            }
        } catch (\Throwable $e) {
            // Worst case the old flash returns; the page still works.
        }

        // Inject a hero <link rel="preload"> for the homepage. Without this PSI
        // flags "LCP request discovery" as the top opportunity, because the real
        // hero URL only becomes known after React mounts. Done here (not in
        // buildTags()) because it depends on a DB lookup we cache; failures
        // silently no-op so the page never breaks.
        try {
            $path = '/'.trim($request->path(), '/');
            $isHome = $path === '/' || $path === '/en' || $path === '/ar' || $path === '/en/' || $path === '/ar/';
            if ($isHome) {
                $tag = $this->heroPreloadTag();
                if ($tag) {
                    $html = str_replace('</head>', "    {$tag}\n  </head>", $html);
                }
            }
        } catch (\Throwable $e) {
            // No preload is better than a broken homepage.
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }

    /**
     * Resolve the current route to { title, head-fragment } or null (leave shell as-is).
     *
     * @return array{title:string, head:string}|null
     */
    private function buildTags(Request $request): ?array
    {
        $path = '/'.trim($request->path(), '/');
        $locale = 'en';
        $rest = $path;
        if (preg_match('#^/(en|ar)(/.*)?$#', $path, $m)) {
            $locale = $m[1];
            $rest = $m[2] ?? '/';
        }
        $rest = '/'.trim($rest, '/');
        $isAr = $locale === 'ar';
        $canonical = $this->base.'/'.$locale.($rest === '/' ? '' : $rest);
        $store = ($isAr ? \App\Models\SiteSetting::where('key', 'STORE_NAME_AR')->value('value') : null) ?: StoreBrand::name();
        // " in <city>" when the admin set one in Branding, so the default copy
        // reads naturally for any store (and with no location when unset).
        $city = StoreBrand::get('store_city_en');
        $in = $city !== '' ? " in {$city}" : '';

        // ---- Product detail ----
        if (preg_match('#^/product/([^/?]+)#', $rest, $pm)) {
            $p = Product::visibleToCustomers()->with('brand')->where('slug', $pm[1])->first();
            if (! $p) {
                return null;
            }
            $name = $isAr && $p->name_ar ? $p->name_ar : $p->name;
            $desc = $this->clean($isAr && $p->description_ar ? $p->description_ar : ($p->short_description ?: $p->description))
                ?: "Shop {$name} at {$store} — authentic luxury products{$in}.";
            $img = SocialImage::resolve($p->image_url ?: (is_array($p->images) ? ($p->images[0] ?? null) : null));
            $inStock = $p->stock_quantity === null || (int) $p->stock_quantity > 0;
            $jsonld = [
                '@context' => 'https://schema.org', '@type' => 'Product',
                'name' => $name,
                'description' => mb_substr($desc, 0, 500),
                'sku' => $p->sku ?: $p->id,
                'brand' => ['@type' => 'Brand', 'name' => optional($p->brand)->name ?: $store],
                'offers' => [
                    '@type' => 'Offer', 'url' => $canonical, 'priceCurrency' => StoreBrand::currency(),
                    'price' => (string) (int) ceil((float) $p->price),
                    'availability' => 'https://schema.org/'.($inStock ? 'InStock' : 'OutOfStock'),
                ],
            ];
            $jsonld['image'] = [$img['url']];

            return $this->compose("{$name} | {$store}", $desc, $canonical, $img, 'product', $rest, $jsonld, $name);
        }

        // ---- Blog post ----
        if (preg_match('#^/blog/([^/?]+)#', $rest, $pm)) {
            $post = BlogPost::published()->where('slug', $pm[1])->first();
            if (! $post) {
                return null;
            }
            $name = $isAr && $post->title_ar ? $post->title_ar : $post->title;
            $desc = $this->clean($isAr && $post->excerpt_ar ? $post->excerpt_ar : ($post->excerpt ?: $post->content));
            $img = $post->cover_image_url ? SocialImage::resolve($post->cover_image_url) : SocialImage::default();
            $jsonld = [
                '@context' => 'https://schema.org', '@type' => 'BlogPosting',
                'headline' => $name,
                'description' => mb_substr($desc, 0, 500),
                'datePublished' => optional($post->published_at ?? $post->created_at)->toIso8601String(),
                'dateModified' => optional($post->updated_at)->toIso8601String(),
                'image' => [$img['url']],
                'publisher' => ['@type' => 'Organization', 'name' => $store],
            ];

            return $this->compose("{$name} | {$store}", $desc, $canonical, $img, 'article', $rest, $jsonld, $name);
        }

        // ---- Brand listing ----
        // /brand/:slug renders the Category page filtered by brand, so the
        // slug is a Brand, not a Category. Preview with one of its watches.
        if (preg_match('#^/brand/([^/?]+)#', $rest, $bm)) {
            $b = Brand::where('slug', $bm[1])->first();
            if ($b) {
                $name = $isAr && $b->name_ar ? $b->name_ar : $b->name;
                $desc = $this->clean($isAr && $b->description_ar ? $b->description_ar : $b->description)
                    ?: "Shop {$name} at {$store} — authentic luxury products{$in}.";

                return $this->compose("{$name} | {$store}", $desc, $canonical,
                    $this->listingImage('brand_id', $b->id, null), 'website', $rest, null, $name);
            }
        }

        // ---- Category listing ----
        if (preg_match('#^/(?:category|brand)/([^/?]+)#', $rest, $cm)) {
            $c = Category::where('slug', $cm[1])->first();
            if ($c) {
                $name = $isAr && $c->name_ar ? $c->name_ar : $c->name;
                $desc = $this->clean($isAr && $c->description_ar ? $c->description_ar : $c->description)
                    ?: "Shop {$name} at {$store} — authentic luxury products{$in}.";

                return $this->compose("{$name} | {$store}", $desc, $canonical,
                    $this->listingImage('category_id', $c->id, $c->image_url), 'website', $rest, null, $name);
            }
        }

        // ---- Homepage + known static pages ----
        // The site-wide title/description come from Admin → Branding → SEO when
        // set (per language); otherwise the generic copy below.
        $homeTitle = StoreBrand::get('seo_title_'.$locale, StoreBrand::get('seo_title_en', "{$store} — Luxury Products{$in}"));
        $homeDesc = StoreBrand::get('seo_description_'.$locale, StoreBrand::get('seo_description_en',
            "Discover authentic luxury products at {$store}. A curated collection{$in}."));
        $pages = [
            '/' => [$homeTitle, $homeDesc],
            '/products' => ["Shop All Products | {$store}", "Browse our full collection of authentic luxury products at {$store}."],
            '/collections' => ["Our Collection | {$store}", "Browse every collection at {$store}."],
            '/new-arrivals' => ["New Arrivals | {$store}", "The latest luxury products just added at {$store}."],
            '/brands' => ["Brands | {$store}", "Explore our collection by brand at {$store}."],
            '/blog' => ["Blog | {$store}", "News, style notes and new arrivals from {$store}."],
            '/about' => ["About Us | {$store}", "{$store} — your destination for authentic luxury products{$in}."],
            '/contact' => ["Contact Us | {$store}", "Get in touch with the {$store} team — questions, orders, and support."],
        ];
        $key = $rest === '/' ? '/' : rtrim($rest, '/');
        if (isset($pages[$key])) {
            [$t, $d] = $pages[$key];
            $jsonld = $key === '/' ? $this->organizationJsonLd() : null;

            return $this->compose($t, $d, $canonical, SocialImage::default(), 'website', $rest, $jsonld);
        }

        // ---- Any other SPA route: generic copy, correct canonical + hreflang ----
        return $this->compose($homeTitle, $homeDesc, $canonical, SocialImage::default(), 'website', $rest);
    }

    /** schema.org Organization for the homepage, from the Branding settings. */
    private function organizationJsonLd(): array
    {
        $org = [
            '@context' => 'https://schema.org', '@type' => 'Organization',
            'name' => StoreBrand::name(),
            'url' => $this->base,
            'logo' => StoreBrand::logoUrl(),
        ];
        if (($legal = StoreBrand::legalName()) !== $org['name']) {
            $org['legalName'] = $legal;
        }
        if ($email = StoreBrand::email()) {
            $org['email'] = $email;
        }
        if ($phone = StoreBrand::phone()) {
            $org['telephone'] = $phone;
        }
        $address = array_filter([
            'streetAddress' => StoreBrand::address(),
            'addressLocality' => StoreBrand::get('store_city_en'),
            'addressCountry' => StoreBrand::countryCode(),
        ]);
        if (isset($address['streetAddress']) || isset($address['addressLocality'])) {
            $org['address'] = ['@type' => 'PostalAddress'] + $address;
        }
        $sameAs = array_values(array_filter([
            StoreBrand::get('instagram_url'),
            StoreBrand::get('facebook_url'),
            StoreBrand::get('twitter_url'),
            StoreBrand::get('tiktok_url'),
        ], fn ($u) => preg_match('#^https?://#i', $u)));
        if ($sameAs) {
            $org['sameAs'] = $sameAs;
        }

        return $org;
    }

    /**
     * The web-root index.html is not a bare shell: react-snap overwrites it with
     * the PRERENDERED homepage, which already carries the homepage's Helmet
     * tags (marked data-rh="true") — its title, og:image, canonical, JSON-LD.
     * Left in, every page served here would hand crawlers the homepage's tags
     * first, and they read the first og:image they find. Only <head> is
     * touched; Helmet re-adds its tags on hydration for real visitors.
     */
    /**
     * Replace the prerendered markup inside <div id="root"> with nothing.
     * Everything from the root's opening tag up to the first <script> after
     * it (or </body>) is the rendered app; the scripts after it are kept.
     */
    private function emptyPrerenderedRoot(string $html): string
    {
        // Last match: an earlier one can only be text in a comment, and cutting
        // from there removes the doctype, viewport and most of <head>.
        $start = strrpos($html, '<div id="root">');
        if ($start === false) {
            return $html;
        }
        $end = stripos($html, '<script', $start);
        if ($end === false) {
            $end = stripos($html, '</body>', $start);
        }
        if ($end === false) {
            return $html;
        }

        return substr($html, 0, $start)."<div id=\"root\"></div>\n    ".substr($html, $end);
    }

    private function stripPrerenderedSeo(string $html): string
    {
        $end = stripos($html, '</head>');
        if ($end === false) {
            return $html;
        }

        $head = substr($html, 0, $end);
        $head = preg_replace('#<script\b[^>]*\bdata-rh="true"[^>]*>.*?</script>\s*#is', '', $head) ?? $head;
        $head = preg_replace('#<(?:meta|link)\b[^>]*\bdata-rh="true"[^>]*>\s*#i', '', $head) ?? $head;

        return $head.substr($html, $end);
    }

    /**
     * Preview image for a brand/category listing: the listing's own image if it
     * has one, else its top watch, else the site card — never nothing.
     *
     * @return array{url:string, width:?int, height:?int, type:string}
     */
    private function listingImage(string $column, string $id, ?string $ownImage): array
    {
        if ($own = SocialImage::resolveOrNull($ownImage)) {
            return $own;
        }

        $productImage = Product::visibleToCustomers()
            ->where($column, $id)
            ->whereNotNull('image_url')
            ->where('image_url', '!=', '')
            ->orderBy('sort_order')
            ->value('image_url');

        return SocialImage::resolve($productImage);
    }

    /**
     * @param  array{url:string, width:?int, height:?int, type:string}  $image
     * @return array{title:string, head:string}
     */
    private function compose(string $title, string $desc, string $canonical, array $image, string $ogType, string $rest, ?array $jsonld = null, ?string $imageAlt = null): array
    {
        $titleEsc = $this->esc($title);
        $descEsc = $this->esc(mb_substr(trim($desc), 0, 320));
        $img = $this->esc($image['url']);
        $can = $this->esc($canonical);
        $isAr = str_starts_with($canonical, $this->base.'/ar');
        $enUrl = $this->esc($this->base.'/en'.($rest === '/' ? '' : $rest));
        $arUrl = $this->esc($this->base.'/ar'.($rest === '/' ? '' : $rest));

        $h = "\n    <!-- server-injected SEO (crawlers/social) -->\n";
        $h .= '    <meta name="description" content="'.$descEsc."\">\n";
        $h .= '    <link rel="canonical" href="'.$can."\">\n";
        $h .= '    <link rel="alternate" hreflang="en" href="'.$enUrl."\">\n";
        $h .= '    <link rel="alternate" hreflang="ar" href="'.$arUrl."\">\n";
        $h .= '    <link rel="alternate" hreflang="x-default" href="'.$enUrl."\">\n";
        $h .= '    <meta property="og:type" content="'.$this->esc($ogType)."\">\n";
        $h .= '    <meta property="og:site_name" content="'.$this->esc(StoreBrand::name())."\">\n";
        $h .= '    <meta property="og:title" content="'.$titleEsc."\">\n";
        $h .= '    <meta property="og:description" content="'.$descEsc."\">\n";
        $h .= '    <meta property="og:url" content="'.$can."\">\n";
        $arLocale = 'ar_'.(preg_match('/^[A-Z]{2}$/', StoreBrand::countryCode()) ? StoreBrand::countryCode() : 'AE');
        $h .= '    <meta property="og:locale" content="'.($isAr ? $arLocale : 'en_US')."\">\n";
        $h .= '    <meta property="og:locale:alternate" content="'.($isAr ? 'en_US' : $arLocale)."\">\n";
        $h .= '    <meta property="og:image" content="'.$img."\">\n";
        $h .= '    <meta property="og:image:secure_url" content="'.$img."\">\n";
        $h .= '    <meta property="og:image:type" content="'.$this->esc($image['type'])."\">\n";
        // Width/height let Facebook/Instagram render the card on the very first
        // share instead of fetching the image asynchronously (which is why a
        // brand-new link often previews without a picture).
        if (! empty($image['width']) && ! empty($image['height'])) {
            $h .= '    <meta property="og:image:width" content="'.(int) $image['width']."\">\n";
            $h .= '    <meta property="og:image:height" content="'.(int) $image['height']."\">\n";
        }
        $h .= '    <meta property="og:image:alt" content="'.$this->esc($imageAlt ?: $title)."\">\n";
        $h .= '    <meta name="twitter:card" content="summary_large_image">'."\n";
        $h .= '    <meta name="twitter:title" content="'.$titleEsc."\">\n";
        $h .= '    <meta name="twitter:description" content="'.$descEsc."\">\n";
        $h .= '    <meta name="twitter:image" content="'.$img."\">\n";
        if ($jsonld) {
            $h .= '    <script type="application/ld+json">'.json_encode($jsonld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).'</script>'."\n";
        }
        $h .= '    ';

        return ['title' => $titleEsc, 'head' => $h];
    }

    /**
     * Point every icon link (icon, shortcut icon, apple-touch-icon) at the
     * Branding favicon when one is uploaded, so non-JS consumers (Google's
     * favicon fetcher, link-preview bots) see it too. sizes/type are dropped:
     * the upload may be PNG, JPG or WebP.
     */
    private function injectFavicon(string $html): string
    {
        $favicon = StoreBrand::get('favicon_url');
        if ($favicon === '') {
            return $html;
        }
        $href = preg_match('#^https?://#i', $favicon) ? $favicon : rtrim($this->base, '/').'/'.ltrim($favicon, '/');

        return preg_replace_callback(
            '#<link\b[^>]*\brel="(icon|shortcut icon|apple-touch-icon)"[^>]*>#i',
            fn ($m) => '<link rel="'.$m[1].'" href="'.$this->esc($href).'">',
            $html
        ) ?? $html;
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    /** Strip HTML + collapse whitespace from a description field. */
    private function clean(?string $s): string
    {
        if (! $s) {
            return '';
        }

        return trim((string) preg_replace('/\s+/', ' ', strip_tags($s)));
    }

    /**
     * Build a <link rel="preload"> for the homepage's hero image so the browser
     * can race the fetch against the JS bundles. Returns '' if we can't resolve
     * the URL or the cache lookup throws — never breaks the page.
     *
     * Cached for 5 minutes via the default cache store so admin theme changes
     * propagate quickly without hammering the DB on every request.
     */
    private function heroPreloadTag(): string
    {
        return Cache::remember('spa.home.hero_preload_tag', 300, function () {
            $heroUrl = null;
            // The homepage slider's first photo (Admin → Theme → Hero). The
            // prerendered HTML no longer contains slider photos, so this is
            // what lets the browser start fetching it before React mounts.
            $slides = json_decode((string) DB::table('site_settings')->whereRaw('LOWER(`key`) = ?', ['hero_slides'])->value('value'), true);
            if (is_array($slides) && ! empty($slides[0]['image']) && is_string($slides[0]['image'])) {
                $heroUrl = $slides[0]['image'];
            }
            foreach ($heroUrl ? [] : ['hero_image_url_en', 'hero_image_url', 'hero_image_url_ar'] as $key) {
                $heroUrl = DB::table('site_settings')->whereRaw('LOWER(`key`) = ?', [$key])->value('value');
                if ($heroUrl) {
                    break;
                }
            }
            if (! $heroUrl) {
                return '';
            }

            $href = $heroUrl;
            $srcsetAttr = '';
            if (preg_match('#/uploads/(?:products|categories|assets)/[^/]+\.webp$#i', $heroUrl)) {
                $stem = preg_replace('/\.webp$/i', '', $heroUrl);
                $href = $stem.'_800.webp';
                $srcsetAttr = sprintf(
                    ' imagesrcset="%s_400.webp 400w, %s_800.webp 800w, %s_1200.webp 1200w" imagesizes="(min-width: 768px) 34vw, 100vw"',
                    $stem, $stem, $stem
                );
            }

            return sprintf(
                '<link rel="preload" as="image" fetchpriority="high" href="%s"%s>',
                $this->esc($href),
                $srcsetAttr
            );
        });
    }
}
