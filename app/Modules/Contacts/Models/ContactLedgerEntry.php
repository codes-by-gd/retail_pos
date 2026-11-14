<?php

namespace App\Modules\Contacts\Models;

use App\Models\User;
use App\Modules\Contacts\Enums\LedgerEntryType;
use App\Modules\Settings\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Append-only. Write through ContactLedgerService. */
class ContactLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['contact_id', 'branch_id', 'date', 'type', 'debit', 'credit', 'reference_type', 'reference_id', 'note', 'user_id'];

    protected $casts = [
        'date' => 'date',
        'type' => LedgerEntryType::class,
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
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
