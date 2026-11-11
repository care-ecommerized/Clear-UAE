<?php
/**
 * ============================================================
 * DeliveryType.php — Delivery Method Options
 * ============================================================
 * What this file does:
 *   Represents a delivery method customers can choose from
 *   (e.g., "Standard Delivery", "Express Delivery"). Stores
 *   the name, description, and estimated delivery days.
 *
 * Database table: delivery_types
 * ============================================================
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryType extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['name', 'label', 'description', 'estimated_days', 'is_active', 'sort_order'];

    protected $casts = ['is_active' => 'boolean'];
}
