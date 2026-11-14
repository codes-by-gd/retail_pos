<?php

namespace Database\Factories;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductVariant;
use App\Modules\Inventory\Models\TaxRate;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Product with one variant. Use ->withVariant([...]) to override variant fields.
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->words(3, true)),
            'type' => Product::TYPE_SINGLE,
            'unit_id' => fn () => Unit::firstOrCreate(['short_name' => 'pcs'], ['name' => 'Pieces'])->id,
            'tax_rate_id' => fn () => TaxRate::firstOrCreate(['rate' => 18, 'cess_rate' => 0], ['name' => 'GST 18%'])->id,
            'hsn_code' => '1905',
            'price_includes_tax' => true,
            'track_stock' => true,
            'is_active' => true,
        ];
    }

    public function withVariant(array $attributes = []): static
    {
        return $this->afterCreating(function (Product $product) use ($attributes) {
            ProductVariant::create([
                'product_id' => $product->id,
                'sku' => 'SKU'.$product->id.'-'.fake()->unique()->numberBetween(1, 999999),
                'purchase_price' => 80,
                'mrp' => 120,
                'selling_price' => 100,
                ...$attributes,
            ]);
        });
    }
}
