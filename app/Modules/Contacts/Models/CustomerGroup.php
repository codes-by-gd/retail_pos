<?php

namespace App\Modules\Contacts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerGroup extends Model
{
    public const PRICE_RETAIL = 'retail';

    public const PRICE_WHOLESALE = 'wholesale';

    protected $fillable = ['name', 'price_type', 'discount_percent'];

    protected $casts = ['discount_percent' => 'decimal:2'];

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }
}
