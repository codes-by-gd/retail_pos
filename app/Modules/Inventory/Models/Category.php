<?php

namespace App\Modules\Inventory\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['parent_id', 'name', 'code', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** All categories as [{value, label}] with parents first and children indented ("Food › Snacks"). */
    public static function options(): array
    {
        $all = self::query()->active()->orderBy('name')->get(['id', 'parent_id', 'name']);
        $byParent = $all->groupBy(fn ($c) => $c->parent_id ?? 0);
        $out = [];
        $walk = function ($parentId, $prefix) use (&$walk, &$out, $byParent) {
            foreach ($byParent->get($parentId, []) as $c) {
                $label = $prefix ? "{$prefix} › {$c->name}" : $c->name;
                $out[] = ['value' => $c->id, 'label' => $label];
                $walk($c->id, $label);
            }
        };
        $walk(0, '');

        return $out;
    }

    protected static function newFactory()
    {
        return CategoryFactory::new();
    }
}
