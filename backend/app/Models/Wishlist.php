<?php
/**
 * ============================================================
 * Wishlist.php — Saved/Wishlisted Items
 * ============================================================
 * What this file does:
 *   Represents a product that a user has saved to their
 *   wishlist. Links a user to a product they want to
 *   remember or buy later.
 *
 * Database table: wishlist
 * ============================================================
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wishlist extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;
    protected $table = 'wishlist';

    protected $fillable = ['user_id', 'product_id'];

    // The product that was wishlisted
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // The user who wishlisted this product
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
