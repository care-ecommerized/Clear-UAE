<?php

/**
 * ============================================================
 * SpinResult.php — Spin to Win plays
 * ============================================================
 * Records who spun, what they won and the discount code created
 * for them. Shown under "Latest spins" in Admin → Spin to Win.
 *
 * Database table: spin_results
 * ============================================================
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpinResult extends Model
{
    use HasUuids;

    protected $fillable = ['email', 'prize_label', 'code', 'discount_id'];

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }
}
