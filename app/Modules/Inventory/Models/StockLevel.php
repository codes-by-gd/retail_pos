<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Settings\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Read-only for application code — write through StockService. */
class StockLevel extends Model
{
    protected $fillable = ['product_variant_id', 'branch_id', 'quantity', 'avg_cost'];

    protected $casts = ['quantity' => 'decimal:3', 'avg_cost' => 'decimal:4'];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
