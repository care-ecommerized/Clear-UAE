<?php

/**
 * ============================================================
 * BLOG CONTROLLER — Blog Posts
 * ============================================================
 * API endpoints:
 *   GET    /api/blog              — Published posts, newest first (?tag= filters)
 *   GET    /api/blog/tags         — Tags in use, most used first (?lang=ar)
 *   GET    /api/blog/{slug}       — One published post
 *   GET    /api/blog/{slug}/related — Up to 3 other posts, most shared tags first
 *   GET    /api/admin/blog        — All posts, including drafts (admin)
 *   POST   /api/admin/blog        — Create a post (admin)
 *   PUT    /api/admin/blog/{id}   — Update a post (admin)
 *   DELETE /api/admin/blog/{id}   — Delete a post (admin)
 * ============================================================
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BlogController extends Controller
{
    private const LIST_COLUMNS = ['id', 'title', 'title_ar', 'slug', 'excerpt', 'excerpt_ar', 'tags', 'tags_ar', 'cover_image_url', 'published_at', 'created_at'];

    public function index(Request $request)
    {
        $limit = max(1, min(48, $request->integer('limit', 12)));
        $tag = trim((string) $request->query('tag'));

        return response()->json(
            BlogPost::published()
                ->select(self::LIST_COLUMNS)
                ->when($tag !== '', fn ($query) => $query->where(
                    fn ($q) => $q->whereJsonContains('tags', $tag)->orWhereJsonContains('tags_ar', $tag)
                ))
                ->orderByRaw('COALESCE(published_at, created_at) DESC')
                ->paginate($limit)
        );
    }

    /** Arabic pages use a post's Arabic tags, or its English ones if it has none. */
    public function tags(Request $request)
    {
        $isAr = $request->query('lang') === 'ar';
        $counts = [];
        foreach (BlogPost::published()->get(['tags', 'tags_ar']) as $post) {
            foreach (($isAr ? ($post->tags_ar ?: $post->tags) : $post->tags) ?? [] as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }
        arsort($counts);

        return response()->json(array_keys($counts));
    }

    public function related(string $slug)
    {
        $post = BlogPost::published()->where('slug', $slug)->firstOrFail();
        $tags = $post->tags ?? [];

        return response()->json(
            BlogPost::published()
                ->select(self::LIST_COLUMNS)
                ->where('id', '!=', $post->id)
                ->orderByRaw('COALESCE(published_at, created_at) DESC')
                ->limit(100)
                ->get()
                ->sortByDesc(fn (BlogPost $other) => count(array_intersect($tags, $other->tags ?? [])))
                ->take(3)
                ->values()
        );
    }

    public function show(string $slug)
    {
        return response()->json(BlogPost::published()->where('slug', $slug)->firstOrFail());
    }

    public function adminIndex()
    {
        return response()->json(BlogPost::orderByDesc('created_at')->get());
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? '', $data['title']);
        $data = $this->stampPublishDate($data);

        return response()->json(BlogPost::create($data), 201);
    }

    public function update(Request $request, string $id)
    {
        $post = BlogPost::findOrFail($id);
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? '', $data['title'], $post->id);
        $data = $this->stampPublishDate($data, $post);
        $post->update($data);

        return response()->json($post);
    }

    public function destroy(string $id)
    {
        BlogPost::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'title_ar' => 'nullable|string|max:255',
            'slug' => 'nullable|string|max:255',
            'excerpt' => 'nullable|string|max:1000',
            'excerpt_ar' => 'nullable|string|max:1000',
            'tags' => 'nullable|array|max:10',
            'tags.*' => 'string|max:40',
            'tags_ar' => 'nullable|array|max:10',
            'tags_ar.*' => 'string|max:40',
            'content' => 'required|string',
            'content_ar' => 'nullable|string',
            'cover_image_url' => 'nullable|string|max:2048',
            'is_published' => 'boolean',
            'published_at' => 'nullable|date',
        ]);

        foreach (['tags', 'tags_ar'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = array_values(array_unique(array_filter(array_map('trim', $data[$field] ?? []))));
            }
        }

        return $data;
    }

    /** First publish with no date chosen goes out now and keeps that date. */
    private function stampPublishDate(array $data, ?BlogPost $post = null): array
    {
        if (! empty($data['is_published']) && empty($data['published_at'])) {
            $data['published_at'] = $post?->published_at ?? now();
        }

        return $data;
    }

    /** Arabic-only titles slug to nothing, so those fall back to a generated one. */
    private function uniqueSlug(string $requested, string $title, ?string $ignoreId = null): string
    {
        $base = Str::slug($requested !== '' ? $requested : $title) ?: 'post-'.Str::lower(Str::random(6));
        $slug = $base;
        $n = 2;
        while (BlogPost::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }
}
