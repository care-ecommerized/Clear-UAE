<?php

/**
 * ============================================================
 * NewsletterSubscriber.php — Homepage newsletter signups
 * ============================================================
 * Database table: newsletter_subscribers
 * ============================================================
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NewsletterSubscriber extends Model
{
    use HasUuids;

    protected $fillable = ['email', 'source'];
}
