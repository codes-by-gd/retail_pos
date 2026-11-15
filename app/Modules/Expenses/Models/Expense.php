<?php

namespace App\Modules\Expenses\Models;

use App\Models\User;
use App\Modules\Pos\Models\RegisterSession;
use App\Modules\Settings\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** A shop running cost paid in cash (optionally from a register drawer), UPI, card, bank or cheque. */
class Expense extends Model
{
    use LogsActivity;

    public const METHODS = ['cash' => 'Cash', 'upi' => 'UPI', 'card' => 'Card', 'bank' => 'Bank transfer', 'cheque' => 'Cheque'];

    protected $guarded = ['id'];

    protected $casts = ['date' => 'date', 'amount' => 'decimal:2', 'gst_amount' => 'decimal:2'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(RegisterSession::class, 'register_session_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['reference_no', 'date', 'expense_category_id', 'amount', 'method', 'paid_to', 'register_session_id'])
            ->logOnlyDirty()->dontLogEmptyChanges();
    }
}
