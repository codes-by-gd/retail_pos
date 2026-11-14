<?php

use App\Modules\Inventory\Models\Attribute;
use App\Modules\Inventory\Models\AttributeValue;
use App\Modules\Inventory\Models\TaxRate;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Inventory\Services\ProductService;
use App\Modules\Settings\Models\Branch;
use Database\Seeders\DefaultCatalogSeeder;

beforeEach(function () {
    (new DefaultCatalogSeeder)->run();
    $this->admin = userWithRole('Admin', [Branch::factory()->create()]);
});

it('seeds clothing masters: garment GST, units and size/colour lists', function () {
    $garments = TaxRate::where('name', 'Garments')->first();

    expect($garments->isPriceBased())->toBeTrue()
        ->and($garments->label())->toBe('Garments — 5% up to ₹2,500, 18% above')
        ->and(Attribute::orderBy('sort_order')->pluck('name')->all())->toBe(['Colour', 'Size', 'Shirt Size', 'Waist', 'Kids Age'])
        ->and(Unit::where('short_name', 'mtr')->value('allow_decimal'))->toBeTrue();
});

it('picks the GST rate from the taxable value of one piece', function () {
    $garments = TaxRate::where('name', 'Garments')->first();
    $flat = TaxRate::where('name', 'GST 18%')->first();

    expect($garments->rateFor(999))->toBe('5.00')
        ->and($garments->rateFor('2500.00'))->toBe('5.00')     // "up to" includes the limit
        ->and($garments->rateFor('2500.01'))->toBe('18.00')
        ->and($flat->rateFor(100000))->toBe('18.00');
});

it('validates and protects price-based GST rates', function () {
    $this->actingAs($this->admin)->post(route('tax-rates.store'), ['name' => 'X', 'rate' => 5, 'cess_rate' => 0, 'threshold_amount' => 1000, 'is_active' => true])
        ->assertSessionHasErrors('rate_above');

    $garments = TaxRate::where('name', 'Garments')->first();
    makeVariant(product: ['tax_rate_id' => $garments->id]);

    $this->actingAs($this->admin)->put(route('tax-rates.update', $garments), [
        'name' => 'Garments', 'rate' => 5, 'cess_rate' => 0, 'threshold_amount' => 1000, 'rate_above' => 12, 'is_active' => true,
    ])->assertSessionHas('error');
    expect($garments->fresh()->threshold_amount)->toBe('2500.00');
});

it('renames variants when a size or colour is renamed, and protects values in use', function () {
    $colour = Attribute::where('name', 'Colour')->first();
    $navy = $colour->values()->where('value', 'Navy')->first();

    $product = app(ProductService::class)->create([
        'name' => 'Polo', 'type' => 'variable', 'unit_id' => Unit::where('short_name', 'pcs')->value('id'),
        'tax_rate_id' => TaxRate::where('name', 'Garments')->value('id'), 'price_includes_tax' => true, 'track_stock' => true, 'is_active' => true,
        'variants' => [['options' => [$colour->id => 'Navy'], 'purchase_price' => 1, 'selling_price' => 2, 'barcodes' => []]],
    ]);

    $values = $colour->values()->get()->map(fn ($v) => ['id' => $v->id, 'value' => $v->id === $navy->id ? 'Navy Blue' : $v->value])->all();
    $this->actingAs($this->admin)->put(route('attributes.update', $colour), ['name' => 'Colour', 'sort_order' => 0, 'values' => $values])
        ->assertSessionHas('success');
    expect($product->variants()->first()->name)->toBe('Navy Blue');

    $withoutNavy = array_values(array_filter($values, fn ($v) => $v['id'] !== $navy->id));
    $this->actingAs($this->admin)->put(route('attributes.update', $colour), ['name' => 'Colour', 'sort_order' => 0, 'values' => $withoutNavy])
        ->assertSessionHas('error');
    $this->actingAs($this->admin)->delete(route('attributes.destroy', $colour))->assertSessionHas('error');

    expect(AttributeValue::find($navy->id))->not->toBeNull();
});

it('creates an attribute and rejects duplicate values', function () {
    $this->actingAs($this->admin)->post(route('attributes.store'), ['name' => 'Footwear Size', 'values' => [['value' => '6'], ['value' => '7']]])
        ->assertSessionHasNoErrors();
    expect(Attribute::where('name', 'Footwear Size')->first()->values->pluck('value')->all())->toBe(['6', '7']);

    $this->actingAs($this->admin)->post(route('attributes.store'), ['name' => 'Cup', 'values' => [['value' => 'A'], ['value' => 'a']]])
        ->assertSessionHasErrors('values');
});

it('shows the stock matrix data on the product page', function () {
    $this->actingAs($this->admin);
    $colour = Attribute::where('name', 'Colour')->value('id');
    $size = Attribute::where('name', 'Size')->value('id');

    $product = app(ProductService::class)->create([
        'name' => 'Tee', 'type' => 'variable', 'unit_id' => Unit::where('short_name', 'pcs')->value('id'),
        'tax_rate_id' => TaxRate::where('name', 'Garments')->value('id'), 'price_includes_tax' => true, 'track_stock' => true, 'is_active' => true,
        'variants' => [
            ['options' => [$colour => 'Black', $size => 'M'], 'purchase_price' => 1, 'selling_price' => 2, 'barcodes' => [], 'opening_stock' => 3],
            ['options' => [$colour => 'Black', $size => 'S'], 'purchase_price' => 1, 'selling_price' => 2, 'barcodes' => []],
        ],
    ], current_branch()->id());

    $this->get(route('products.show', $product))->assertInertia(fn ($p) => $p
        ->where('attributes.0.name', 'Colour')
        ->where('attributes.1.values', ['S', 'M'])           // in list order, not creation order
        ->where('variants.0.options.'.$size, 'M'));
});
