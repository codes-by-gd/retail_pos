<?php

namespace App\Modules\Purchases\Models;

use App\Models\User;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Settings\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Debit note: goods sent back to the supplier. */
class PurchaseReturn extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'date', 'taxable_total' => 'decimal:2', 'cgst_total' => 'decimal:2', 'sgst_total' => 'decimal:2',
        'igst_total' => 'decimal:2', 'cess_total' => 'decimal:2', 'total' => 'decimal:2',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_id')->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
