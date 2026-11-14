<?php

namespace App\Modules\Pos\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A parked bill. The payload is the POS cart state; prices are re-checked when it is recalled. */
class HeldCart extends Model
{
    protected $fillable = ['branch_id', 'user_id', 'label', 'payload'];

    protected $casts = ['payload' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
