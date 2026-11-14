<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** A sellable item (SKU). Stock, barcodes and prices live here. */
class ProductVariant extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'product_id', 'name', 'sku', 'purchase_price', 'mrp', 'selling_price', 'wholesale_price', 'reorder_level', 'is_active',
    ];

    protected $casts = [
        'purchase_price' => 'decimal:2',
        'mrp' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'wholesale_price' => 'decimal:2',
        'reorder_level' => 'decimal:3',
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Colour / size values of this variant. */
    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class);
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class);
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** Stock row for the user's current branch (eager-load with ->with('stockHere')). */
    public function stockHere(): HasOne
    {
        return $this->hasOne(StockLevel::class)->where('branch_id', current_branch()->id());
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('product_variants.is_active', true);
    }

    /** "Product name - Variant" */
    public function fullName(): string
    {
        return $this->name ? "{$this->product->name} - {$this->name}" : $this->product->name;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['sku', 'name', 'purchase_price', 'mrp', 'selling_price', 'wholesale_price', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
