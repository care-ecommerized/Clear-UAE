<?php

/**
 * ============================================================
 * AdminNotification.php — Admin Bell Notifications
 * ============================================================
 * What this model represents:
 *   One notification surfaced in the admin header bell. Created
 *   for events admins should be aware of (order placed, paid,
 *   cancelled, checkout abandoned). Read state is tracked per
 *   admin via the read_by JSON array of user IDs.
 *
 * Database table: admin_notifications
 * ============================================================
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AdminNotification extends Model
{
    use HasUuids;

    protected $fillable = [
        'type', 'title', 'message', 'link', 'data', 'read_by',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_by' => 'array',
        ];
    }

    public function isReadBy(string $userId): bool
    {
        return in_array($userId, $this->read_by ?? [], true);
    }

    public function markReadBy(string $userId): void
    {
        $readers = $this->read_by ?? [];
        if (! in_array($userId, $readers, true)) {
            $readers[] = $userId;
            $this->read_by = $readers;
            $this->save();
        }
    }
}
