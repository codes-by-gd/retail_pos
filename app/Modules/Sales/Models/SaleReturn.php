<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Settings\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Credit note for returned items. */
class SaleReturn extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'returned_at' => 'datetime',
        'taxable_total' => 'decimal:2',
        'cgst_total' => 'decimal:2',
        'sgst_total' => 'decimal:2',
        'igst_total' => 'decimal:2',
        'cess_total' => 'decimal:2',
        'round_off' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id')->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getReferenceNoAttribute(): string
    {
        return $this->credit_note_no;
    }
}
