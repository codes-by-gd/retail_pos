<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Settings\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends Model
{
    public const STATUSES = ['draft' => 'Draft', 'sent' => 'Sent', 'accepted' => 'Accepted', 'rejected' => 'Rejected', 'converted' => 'Converted to bill'];

    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'date', 'valid_until' => 'date', 'converted_at' => 'datetime', 'is_inter_state' => 'boolean',
        'bill_discount_value' => 'decimal:2', 'subtotal' => 'decimal:2', 'line_discount_total' => 'decimal:2', 'bill_discount_total' => 'decimal:2',
        'taxable_total' => 'decimal:2', 'cgst_total' => 'decimal:2', 'sgst_total' => 'decimal:2', 'igst_total' => 'decimal:2',
        'cess_total' => 'decimal:2', 'round_off' => 'decimal:2', 'grand_total' => 'decimal:2',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
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

    public function isExpired(): bool
    {
        return $this->valid_until && $this->valid_until->isPast() && ! $this->valid_until->isToday() && in_array($this->status, ['draft', 'sent'], true);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'sent'], true);
    }
}
