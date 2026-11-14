<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['stock_transfer_id', 'product_variant_id', 'quantity', 'unit_cost'];

    protected $casts = ['quantity' => 'decimal:3', 'unit_cost' => 'decimal:4'];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }
}
