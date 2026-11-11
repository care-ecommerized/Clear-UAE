<?php
/**
 * ============================================================
 * Collection.php — Product Collections
 * ============================================================
 * What this file does:
 *   Represents a curated group of products (e.g., "Summer
 *   Sale", "New Arrivals"). Products are linked to collections
 *   through a pivot table. Collections can be manually curated
 *   or automatically populated using rules.
 *
 * Database table: collections
 * ============================================================
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Collection extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name', 'slug', 'name_ar', 'description', 'description_ar',
        'image_url', 'is_active', 'is_automated', 'automation_rules', 'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_automated' => 'boolean',
        'automation_rules' => 'array',
    ];

    // All products in this collection (many-to-many via product_collections table)
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_collections');
    }
}
