<?php

namespace App\Modules\Purchases\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReturnItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:3', 'taxable_value' => 'decimal:2', 'cgst' => 'decimal:2', 'sgst' => 'decimal:2',
        'igst' => 'decimal:2', 'cess' => 'decimal:2', 'line_total' => 'decimal:2',
    ];

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class);
    }
}
