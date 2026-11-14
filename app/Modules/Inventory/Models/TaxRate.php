<?php

namespace App\Modules\Inventory\Models;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A GST rate. Optionally price-based (garments): when the taxable value of one piece
 * exceeds `threshold_amount`, `rate_above` applies instead of `rate`.
 * e.g. Garments: 5% up to ₹2,500 per piece, 18% above.
 */
class TaxRate extends Model
{
    protected $fillable = ['name', 'rate', 'cess_rate', 'threshold_amount', 'rate_above', 'is_active'];

    protected $casts = [
        'rate' => 'decimal:2',
        'cess_rate' => 'decimal:2',
        'threshold_amount' => 'decimal:2',
        'rate_above' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function isPriceBased(): bool
    {
        return $this->threshold_amount !== null && $this->rate_above !== null;
    }

    /** GST % for one piece with this taxable value (after discount, excluding GST). */
    public function rateFor(string|int|float $taxableValuePerUnit): string
    {
        if ($this->isPriceBased() && BigDecimal::of((string) $taxableValuePerUnit)->isGreaterThan($this->threshold_amount)) {
            return (string) $this->rate_above;
        }

        return (string) $this->rate;
    }

    /** "GST 18%" or "Garments — 5% up to ₹2,500, 18% above" */
    public function label(): string
    {
        if (! $this->isPriceBased()) {
            return $this->name;
        }

        return sprintf('%s — %s%% up to ₹%s, %s%% above', $this->name, (float) $this->rate,
            number_format((float) $this->threshold_amount), (float) $this->rate_above);
    }
}
