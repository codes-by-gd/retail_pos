<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Settings\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Append-only stock ledger row. */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'product_variant_id', 'branch_id', 'type', 'quantity', 'unit_cost', 'balance_after',
        'reference_type', 'reference_id', 'note', 'user_id',
    ];

    protected $casts = [
        'type' => StockMovementType::class,
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'balance_after' => 'decimal:3',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
