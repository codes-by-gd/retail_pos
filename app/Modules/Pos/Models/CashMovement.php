<?php

namespace App\Modules\Pos\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cash put into or taken out of the drawer outside sales (change float top-up, petty cash, bank deposit). */
class CashMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['register_session_id', 'type', 'amount', 'reason', 'user_id'];

    protected $casts = ['amount' => 'decimal:2'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
