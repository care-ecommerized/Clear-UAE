<?php
/**
 * ============================================================
 * Profile.php — User Profile Details
 * ============================================================
 * What this file does:
 *   Stores extra personal info for a user, like their full
 *   name, phone number, and avatar image. Each user has
 *   exactly one profile.
 *
 * Database table: profiles
 * ============================================================
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Profile extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['user_id', 'email', 'full_name', 'phone', 'address', 'city', 'country', 'avatar_url'];

    // The user this profile belongs to
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
