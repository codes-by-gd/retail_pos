<?php

namespace App\Modules\Contacts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Which bill (sale or purchase) a payment settled, and how much. */
class PaymentAllocation extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['amount' => 'decimal:2'];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(ContactPayment::class, 'contact_payment_id');
    }

    public function allocatable(): MorphTo
    {
        return $this->morphTo();
    }
}
