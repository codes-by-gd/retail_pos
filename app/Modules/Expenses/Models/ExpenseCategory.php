<?php

namespace App\Modules\Expenses\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Rent, salaries, electricity, packaging… */
class ExpenseCategory extends Model
{
    public const DEFAULTS = ['Rent', 'Salaries & wages', 'Electricity', 'Staff tea & refreshments', 'Transport & courier',
        'Packaging (bags, hangers, tags)', 'Alterations & tailoring', 'Shop maintenance & repairs', 'Marketing & ads',
        'Internet & phone', 'Bank & card charges', 'Other'];

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean'];

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }
}
