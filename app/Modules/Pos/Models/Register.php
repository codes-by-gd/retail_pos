<?php

namespace App\Modules\Pos\Models;

use App\Modules\Settings\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A cash counter in a branch. */
class Register extends Model
{
    protected $fillable = ['branch_id', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(RegisterSession::class);
    }

    public function openSession(): HasOne
    {
        return $this->hasOne(RegisterSession::class)->where('status', RegisterSession::OPEN);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }
}
