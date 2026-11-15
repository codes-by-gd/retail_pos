<?php

namespace App\Modules\Purchases\Models;

use App\Models\User;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\PaymentAllocation;
use App\Modules\Settings\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Goods received with the supplier's bill. Immutable once saved — correct with a purchase return (debit note). */
class Purchase extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'supplier_invoice_date' => 'date', 'received_date' => 'date', 'is_inter_state' => 'boolean',
        'subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'taxable_total' => 'decimal:2', 'cgst_total' => 'decimal:2',
        'sgst_total' => 'decimal:2', 'igst_total' => 'decimal:2', 'cess_total' => 'decimal:2', 'other_charges' => 'decimal:2',
        'round_off' => 'decimal:2', 'grand_total' => 'decimal:2', 'paid_amount' => 'decimal:2', 'due_amount' => 'decimal:2',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    public function allocations(): MorphMany
    {
        return $this->morphMany(PaymentAllocation::class, 'allocatable');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_id')->withTrashed();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
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
