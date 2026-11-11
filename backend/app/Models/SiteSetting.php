<?php
/**
 * ============================================================
 * SiteSetting.php — Site Configuration (Key-Value Pairs)
 * ============================================================
 * What this file does:
 *   Stores site-wide settings as simple key-value pairs.
 *   For example: currency rates, store name, contact info,
 *   or feature flags. Admins can update these from the
 *   admin dashboard.
 *
 * Database table: site_settings
 * ============================================================
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['key', 'value'];

    protected static function booted(): void
    {
        // Brand caches the identity settings; any change must show immediately.
        static::saved(fn () => \App\Support\Brand::flush());
        static::deleted(fn () => \App\Support\Brand::flush());
    }
}
