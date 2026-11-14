<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Settings\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    public const IN_TRANSIT = 'in_transit';

    public const RECEIVED = 'received';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['reference_no', 'from_branch_id', 'to_branch_id', 'status', 'note', 'user_id', 'received_by', 'received_at'];

    protected $casts = ['received_at' => 'datetime'];

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id')->withTrashed();
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
