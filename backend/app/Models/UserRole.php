<?php
/**
 * ============================================================
 * UserRole.php — User Role Assignments
 * ============================================================
 * What this file does:
 *   Links a user to a role (like "admin" or "user"). This is
 *   how the app knows if someone has admin privileges.
 *   One user can have multiple roles.
 *
 * Database table: user_roles
 * ============================================================
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class UserRole extends Model
{
    use HasUuids;

    public $timestamps = false; // This table has no created_at/updated_at columns
    protected $fillable = ['user_id', 'role']; // Fields that can be set

    // Each role entry belongs to one user
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
