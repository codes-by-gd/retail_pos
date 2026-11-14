<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A variant dimension such as Colour, Size, Waist or Kids age. */
class Attribute extends Model
{
    protected $fillable = ['name', 'sort_order'];

    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class)->orderBy('sort_order')->orderBy('id');
    }

    /** [{id, name, values: [{id, value}]}] for forms and filters. */
    public static function options(): array
    {
        return self::query()->with('values:id,attribute_id,value')->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (self $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'values' => $a->values->map(fn ($v) => ['id' => $v->id, 'value' => $v->value])->values(),
            ])->all();
    }
}
