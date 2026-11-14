<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Settings\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockAdjustment extends Model
{
    public const REASONS = [
        'count' => 'Stock count correction',
        'damage' => 'Damaged',
        'expiry' => 'Expired',
        'theft' => 'Lost / theft',
        'internal_use' => 'Internal use',
        'found' => 'Found / excess',
        'other' => 'Other',
    ];

    protected $fillable = ['reference_no', 'branch_id', 'date', 'reason', 'note', 'user_id'];

    protected $casts = ['date' => 'date'];

    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
