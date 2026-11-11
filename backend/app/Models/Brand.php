<?php
/**
 * ============================================================
 * Brand.php — Watch Brands
 * ============================================================
 * What this file does:
 *   Represents a watch brand (e.g., "Rolex", "Omega").
 *   Each brand can have many products. Stores the brand
 *   name, logo, and description in English and Arabic.
 *
 * Database table: brands
 * ============================================================
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name', 'slug', 'name_ar', 'description', 'description_ar',
        'logo_url', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // All products that belong to this brand
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
