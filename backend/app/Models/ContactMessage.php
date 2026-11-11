<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactMessage extends Model
{
    use HasUuids;

    protected $fillable = [
        'name', 'email', 'phone', 'subject', 'message', 'ip_address', 'is_read',
        'reply_message', 'replied_at',
        'product_id', 'product_name', 'product_sku', 'product_url',
    ];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'replied_at' => 'datetime',
        ];
    }

    /**
     * The product this enquiry is about, when it came from a product page.
     *
     * Null for ordinary contact-form messages, and also null once a sold watch
     * is deleted — the product_name/sku/url snapshot on the row is what keeps
     * the enquiry answerable in that case, so prefer those for display.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
