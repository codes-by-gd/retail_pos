<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalePayment extends Model
{
    public const CASH = 'cash';

    public const CARD = 'card';

    public const UPI = 'upi';

    public const ACCOUNT = 'account';     // on the customer's account (credit / store credit)

    public const EXCHANGE = 'exchange';   // credit from a returned item, used in this bill

    public const POINTS = 'points';       // loyalty points used as payment (reference = number of points)

    public const LABELS = [
        self::CASH => 'Cash', self::CARD => 'Card', self::UPI => 'UPI', self::ACCOUNT => 'On account', self::EXCHANGE => 'Exchange credit', self::POINTS => 'Loyalty points',
    ];

    public const UPDATED_AT = null;

    protected $fillable = ['sale_id', 'method', 'amount', 'reference', 'sale_return_id'];

    protected $casts = ['amount' => 'decimal:2'];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
