<?php

namespace App\Modules\Purchases\Models;

use App\Models\User;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Settings\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    public const STATUSES = ['draft' => 'Draft', 'ordered' => 'Ordered', 'partially_received' => 'Partly received', 'received' => 'Received', 'cancelled' => 'Cancelled'];

    protected $guarded = ['id'];

    protected $casts = ['order_date' => 'date', 'expected_date' => 'date', 'estimated_total' => 'decimal:2'];

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
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

    public function isOpen(): bool
    {
        return in_array($this->status, ['ordered', 'partially_received'], true);
    }
}
