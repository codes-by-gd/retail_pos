<?php

namespace App\Modules\Contacts\Models;

use App\Models\User;
use App\Modules\Pos\Models\RegisterSession;
use App\Modules\Settings\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Money received from a customer (in) or paid to a supplier (out) outside a bill. */
class ContactPayment extends Model
{
    public const IN = 'in';

    public const OUT = 'out';

    public const METHODS = ['cash' => 'Cash', 'upi' => 'UPI', 'card' => 'Card', 'bank' => 'Bank transfer', 'cheque' => 'Cheque'];

    protected $guarded = ['id'];

    protected $casts = ['date' => 'date', 'amount' => 'decimal:2', 'unallocated' => 'decimal:2'];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(RegisterSession::class, 'register_session_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
