<?php

namespace App\Modules\Contacts\Models;

use App\Modules\Loyalty\Services\LoyaltyService;
use App\Support\Gst\IndianStates;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** A customer, a supplier, or both. */
class Contact extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const CUSTOMER = 'customer';

    public const SUPPLIER = 'supplier';

    public const BOTH = 'both';

    protected $fillable = [
        'code', 'type', 'name', 'company_name', 'phone', 'alt_phone', 'email', 'gstin', 'state_code', 'address', 'city',
        'pincode', 'customer_group_id', 'credit_limit', 'payment_terms_days', 'date_of_birth', 'anniversary', 'notes',
        'is_walk_in', 'is_active',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'date_of_birth' => 'date',
        'anniversary' => 'date',
        'is_walk_in' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(ContactLedgerEntry::class);
    }

    /** Customers include "both"; so do suppliers. */
    public function scopeOfType(Builder $q, string $type): Builder
    {
        return $q->whereIn('contacts.type', [$type, self::BOTH]);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('contacts.is_active', true);
    }

    /** Adds a `balance` column (debit − credit). */
    public function scopeWithBalance(Builder $q): Builder
    {
        return $q->addSelect(['balance' => ContactLedgerEntry::query()
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0)')
            ->whereColumn('contact_ledger_entries.contact_id', 'contacts.id')]);
    }

    public function isCustomer(): bool
    {
        return in_array($this->type, [self::CUSTOMER, self::BOTH], true);
    }

    public function isSupplier(): bool
    {
        return in_array($this->type, [self::SUPPLIER, self::BOTH], true);
    }

    public function balance(): string
    {
        $raw = $this->ledger()->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as b')->value('b') ?? 0;

        // Same "1234.50" format on SQLite (returns floats) and MySQL (returns decimals).
        return (string) BigDecimal::of((string) $raw)->toScale(2, RoundingMode::HalfUp);
    }

    /** Customer as the POS cart expects it. Uses a preloaded `balance` column when present. */
    public static function forPos(self $c): array
    {
        return [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'company_name' => $c->company_name,
            'phone' => $c->phone,
            'gstin' => $c->gstin,
            'state_code' => $c->is_walk_in ? null : $c->state_code,
            'balance' => (float) ($c->getAttributes()['balance'] ?? $c->balance()),
            'credit_limit' => (float) $c->credit_limit,
            'price_type' => $c->group?->price_type ?? 'retail',
            'discount_percent' => (float) ($c->group?->discount_percent ?? 0),
            'is_walk_in' => $c->is_walk_in,
            // shown at the POS only while loyalty is switched on
            'points' => $c->is_walk_in || $c->type === self::SUPPLIER || ! LoyaltyService::enabled() ? 0
                : (int) ($c->getAttributes()['loyalty_points'] ?? app(LoyaltyService::class)->balance($c)),
        ];
    }

    public function stateName(): ?string
    {
        return IndianStates::name($this->state_code);
    }

    /** Next CUS-00001 / SUP-00001 style code. */
    public static function nextCode(string $type): string
    {
        $prefix = $type === self::SUPPLIER ? 'SUP' : 'CUS';
        $last = self::withTrashed()->where('code', 'like', "{$prefix}-%")->lockForUpdate()
            ->orderByDesc(DB::raw('LENGTH(code)'))->orderByDesc('code')->value('code');
        $n = $last ? (int) substr($last, 4) + 1 : 1;

        return sprintf('%s-%05d', $prefix, $n);
    }

    /**
     * Normalise Indian phone numbers to digits: "+91 98765-43210" → "9876543210", "0265 2345678" → "2652345678".
     */
    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone);
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return $digits;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    protected static function newFactory()
    {
        return ContactFactory::new();
    }
}
