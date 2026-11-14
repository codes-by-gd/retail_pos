<?php

use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\TaxRate;

beforeEach(function () {
    $this->admin = userWithRole('Admin');
});

it('creates nested categories and blocks cycles', function () {
    $this->actingAs($this->admin)->post(route('categories.store'), ['name' => 'Food', 'is_active' => true]);
    $food = Category::first();
    $this->actingAs($this->admin)->post(route('categories.store'), ['name' => 'Snacks', 'parent_id' => $food->id, 'is_active' => true]);
    $snacks = Category::where('name', 'Snacks')->first();

    expect(Category::options())->toContain(['value' => $snacks->id, 'label' => 'Food › Snacks']);

    $this->actingAs($this->admin)->put(route('categories.update', $food), ['name' => 'Food', 'parent_id' => $snacks->id, 'is_active' => true])
        ->assertSessionHas('error');
    expect($food->fresh()->parent_id)->toBeNull();
});

it('will not delete catalog entries that are in use', function () {
    $v = makeVariant(product: ['brand_id' => Brand::create(['name' => 'Amul'])->id]);

    $this->actingAs($this->admin)->delete(route('brands.destroy', $v->product->brand_id))->assertSessionHas('error');
    $this->actingAs($this->admin)->delete(route('tax-rates.destroy', $v->product->tax_rate_id))->assertSessionHas('error');
    $this->actingAs($this->admin)->delete(route('units.destroy', $v->product->unit_id))->assertSessionHas('error');

    expect(Brand::count())->toBe(1);
});

it('will not change the percentage of a GST rate in use', function () {
    $v = makeVariant();
    $rate = $v->product->taxRate;

    $this->actingAs($this->admin)->put(route('tax-rates.update', $rate), ['name' => 'GST 18%', 'rate' => 12, 'cess_rate' => 0, 'is_active' => true])
        ->assertSessionHas('error');
    $this->actingAs($this->admin)->put(route('tax-rates.update', $rate), ['name' => 'Standard 18', 'rate' => 18, 'cess_rate' => 0, 'is_active' => true])
        ->assertSessionHas('success');

    expect(TaxRate::find($rate->id))->rate->toBe('18.00')->name->toBe('Standard 18');
});

it('requires catalog.manage', function () {
    $this->actingAs(userWithRole('Cashier'))->get(route('categories.index'))->assertForbidden();
});
