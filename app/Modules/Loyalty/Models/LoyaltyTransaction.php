<?php

namespace App\Modules\Loyalty\Models;

use App\Models\User;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Sales\Models\Sale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyTransaction extends Model
{
    public const EARN = 'earn';          // points for a bill

    public const REDEEM = 'redeem';      // points used as payment

    public const REVERSE = 'reverse';    // earned points taken back (return / cancellation)

    public const RESTORE = 'restore';    // redeemed points given back (cancellation)

    public const EXPIRE = 'expire';

    public const ADJUST = 'adjust';      // manual correction / goodwill

    public const LABELS = [self::EARN => 'Earned', self::REDEEM => 'Redeemed', self::REVERSE => 'Taken back',
        self::RESTORE => 'Given back', self::EXPIRE => 'Expired', self::ADJUST => 'Adjustment'];

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['expires_at' => 'date', 'points' => 'integer', 'remaining' => 'integer'];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
