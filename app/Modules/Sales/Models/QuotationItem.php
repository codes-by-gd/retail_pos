<?php

namespace App\Modules\Sales\Models;

use App\Modules\Inventory\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'discount_value' => 'decimal:2', 'line_discount' => 'decimal:2',
        'bill_discount_share' => 'decimal:2', 'taxable_value' => 'decimal:2', 'cgst' => 'decimal:2', 'sgst' => 'decimal:2',
        'igst' => 'decimal:2', 'cess' => 'decimal:2', 'line_total' => 'decimal:2', 'gst_rate' => 'decimal:2',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    public function displayName(): string
    {
        return $this->variant_name ? "{$this->product_name} - {$this->variant_name}" : $this->product_name;
    }
}
