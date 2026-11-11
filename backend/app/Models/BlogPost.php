<?php

/**
 * ============================================================
 * BlogPost.php — Blog Posts
 * ============================================================
 * A post written in Admin → Blog. Customers only see posts that
 * are published and whose publish date has arrived.
 *
 * Database table: blog_posts
 * ============================================================
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BlogPost extends Model
{
    use HasUuids;

    protected $fillable = [
        'title', 'title_ar', 'slug', 'excerpt', 'excerpt_ar', 'tags', 'tags_ar', 'content', 'content_ar',
        'cover_image_url', 'is_published', 'published_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'tags_ar' => 'array',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function scopePublished($query)
    {
        return $query->where('is_published', true)
            ->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }
}
