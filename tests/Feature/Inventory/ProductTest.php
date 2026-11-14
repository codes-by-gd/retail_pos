<?php

use App\Modules\Inventory\Models\Attribute;
use App\Modules\Inventory\Models\AttributeValue;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBarcode;
use App\Modules\Inventory\Models\ProductVariant;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\TaxRate;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Inventory\Services\BarcodeService;
use App\Modules\Settings\Models\Branch;
use Database\Seeders\DefaultCatalogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->admin = userWithRole('Admin', [$this->branch]);
    (new DefaultCatalogSeeder)->run();
});

function attrId(string $name): int
{
    return Attribute::where('name', $name)->value('id');
}

function productPayload(array $overrides = []): array
{
    return [
        'name' => 'Parle-G 250g', 'type' => 'single',
        'unit_id' => Unit::where('short_name', 'pcs')->value('id'),
        'tax_rate_id' => TaxRate::where('name', 'Garments')->value('id'),
        'hsn_code' => '1905', 'price_includes_tax' => true, 'track_stock' => true, 'is_active' => true,
        'variants' => [['purchase_price' => 22, 'mrp' => 30, 'selling_price' => 30, 'barcodes' => '', 'opening_stock' => 12]],
        ...$overrides,
    ];
}

it('creates a single product with auto SKU, EAN-13 barcode and opening stock', function () {
    $this->actingAs($this->admin)->post(route('products.store'), productPayload())->assertSessionHasNoErrors();

    $variant = ProductVariant::with('barcodes')->first();
    expect($variant->sku)->toBe(sprintf('P%05d', $variant->product_id))
        ->and(BarcodeService::isValidEan13($variant->barcodes->first()->barcode))->toBeTrue()
        ->and(StockMovement::first())->quantity->toBe('12.000')->type->value->toBe('opening')
        ->and(StockMovement::first()->branch_id)->toBe($this->branch->id);
});

it('creates a size × colour product with readable variant names', function () {
    [$colour, $size] = [attrId('Colour'), attrId('Shirt Size')];

    $this->actingAs($this->admin)->post(route('products.store'), productPayload([
        'name' => 'Formal Shirt', 'type' => 'variable', 'article_no' => 'PE-1042', 'material' => 'Cotton',
        'variants' => [
            ['options' => [$colour => 'Navy', $size => '40'], 'sku' => 'SH-N40', 'barcodes' => '111, 112', 'purchase_price' => 900, 'selling_price' => 1799],
            ['options' => [$colour => 'Navy', $size => '42'], 'barcodes' => '', 'purchase_price' => 900, 'selling_price' => 1799],
            ['options' => [$colour => 'Bottle Green', $size => '40'], 'barcodes' => '', 'purchase_price' => 900, 'selling_price' => 1899],
        ],
    ]))->assertSessionHasNoErrors();

    $product = Product::with('variants.barcodes', 'variants.attributeValues')->first();
    expect($product)->article_no->toBe('PE-1042')->material->toBe('Cotton')
        ->and($product->variants->pluck('name')->all())->toBe(['Navy / 40', 'Navy / 42', 'Bottle Green / 40'])
        ->and($product->variants[0]->barcodes->pluck('barcode')->all())->toBe(['111', '112'])
        ->and($product->variants[0]->attributeValues)->toHaveCount(2)
        // a colour typed in the form is added to the Colour list
        ->and(AttributeValue::where('attribute_id', $colour)->where('value', 'Bottle Green')->exists())->toBeTrue();
});

it('validates size / colour combinations', function () {
    [$colour, $size] = [attrId('Colour'), attrId('Size')];
    $post = fn (array $variants) => $this->actingAs($this->admin)->post(route('products.store'), productPayload(['type' => 'variable', 'variants' => $variants]));
    $row = fn (array $options) => ['options' => $options, 'purchase_price' => 1, 'selling_price' => 2];

    $post([$row([])])->assertSessionHasErrors('variants.0.options');
    $post([$row([$colour => 'Red', $size => 'M']), $row([$colour => 'red', $size => 'M'])])->assertSessionHasErrors('variants.1.options');
    $post([$row([$colour => 'Red', $size => 'M']), $row([$colour => 'Blue'])])->assertSessionHasErrors('variants');
    $post([$row([$colour => 'Red', $size => ''])])->assertSessionHasErrors('variants.0.options');

    $this->actingAs($this->admin)->post(route('products.store'), productPayload([
        'variants' => [['purchase_price' => 1, 'selling_price' => 2], ['purchase_price' => 1, 'selling_price' => 2]],
    ]))->assertSessionHasErrors('variants');

    expect(Product::count())->toBe(0);
});

it('updates prices, keeps barcodes and soft-deletes removed sizes', function () {
    $size = attrId('Size');
    $this->actingAs($this->admin)->post(route('products.store'), productPayload([
        'type' => 'variable', 'name' => 'T-shirt',
        'variants' => [['options' => [$size => 'S'], 'purchase_price' => 1, 'selling_price' => 2], ['options' => [$size => 'M'], 'purchase_price' => 1, 'selling_price' => 2]],
    ]));
    $product = Product::with('variants.barcodes')->first();
    [$s, $m] = $product->variants;

    $this->actingAs($this->admin)->put(route('products.update', $product), productPayload([
        'type' => 'variable', 'name' => 'T-shirt',
        'variants' => [['id' => $s->id, 'options' => [$size => 'S'], 'sku' => $s->sku, 'barcodes' => $s->barcodes->first()->barcode, 'purchase_price' => 1, 'selling_price' => 5]],
    ]))->assertSessionHasNoErrors();

    expect($s->fresh())->name->toBe('S')->selling_price->toBe('5.00')
        ->and($s->fresh()->barcodes->pluck('barcode')->all())->toBe($s->barcodes->pluck('barcode')->all())
        ->and(ProductVariant::find($m->id))->toBeNull()
        ->and(ProductVariant::withTrashed()->find($m->id)->trashed())->toBeTrue();
});

it('rejects duplicate barcodes and SKUs', function () {
    $existing = makeVariant(['sku' => 'TAKEN']);
    ProductBarcode::create(['product_variant_id' => $existing->id, 'barcode' => '8901234567890']);

    $this->actingAs($this->admin)->post(route('products.store'), productPayload([
        'variants' => [['sku' => 'TAKEN', 'barcodes' => '8901234567890', 'purchase_price' => 1, 'selling_price' => 2]],
    ]))->assertSessionHasErrors(['variants.0.sku', 'variants.0.barcodes']);
});

it('does not allow selling above MRP', function () {
    $this->actingAs($this->admin)->post(route('products.store'), productPayload([
        'variants' => [['purchase_price' => 10, 'mrp' => 30, 'selling_price' => 35]],
    ]))->assertSessionHasErrors('variants.0.selling_price');
});

it('does not let one product claim another product variant id', function () {
    $other = makeVariant();
    $this->actingAs($this->admin)->post(route('products.store'), productPayload());
    $product = Product::latest('id')->first();

    $this->actingAs($this->admin)->put(route('products.update', $product), productPayload([
        'variants' => [['id' => $other->id, 'purchase_price' => 1, 'selling_price' => 2]],
    ]))->assertSessionHasErrors('variants.0.id');
});

it('stores and replaces the product image', function () {
    Storage::fake('public');

    $this->actingAs($this->admin)->post(route('products.store'), productPayload(['image' => UploadedFile::fake()->image('p.jpg')]));
    $product = Product::first();
    Storage::disk('public')->assertExists($product->image_path);

    $this->actingAs($this->admin)->post(route('products.update', $product), [
        ...productPayload(['remove_image' => true]), '_method' => 'PUT',
        'variants' => [['id' => $product->variants()->first()->id, 'purchase_price' => 1, 'selling_price' => 2]],
    ])->assertSessionHasNoErrors();
    expect($product->fresh()->image_path)->toBeNull();
});

it('soft deletes products with permission only', function () {
    $v = makeVariant();
    $keeper = userWithRole('Stock Keeper', [$this->branch]);

    $this->actingAs($keeper)->delete(route('products.destroy', $v->product_id))->assertForbidden();
    $this->actingAs($this->admin)->delete(route('products.destroy', $v->product_id))->assertRedirect(route('products.index'));

    expect(Product::find($v->product_id))->toBeNull()->and(Product::withTrashed()->find($v->product_id))->not->toBeNull();
});

it('lists products with stock for the current branch and searches by barcode', function () {
    $v = makeVariant(branch: $this->branch, stock: 7, product: ['name' => 'Findable Soap']);
    ProductBarcode::create(['product_variant_id' => $v->id, 'barcode' => '777']);
    makeVariant(product: ['name' => 'Other thing']);

    $this->actingAs($this->admin)->get(route('products.index', ['search' => '777']))
        ->assertInertia(fn ($page) => $page->has('products.data', 1)
            ->where('products.data.0.name', 'Findable Soap')
            ->where('products.data.0.stock', 7));
});

it('lets cashiers view but not create products', function () {
    $cashier = userWithRole('Cashier', [$this->branch]);

    $this->actingAs($cashier)->get(route('products.index'))->assertOk();
    $this->actingAs($cashier)->post(route('products.store'), productPayload())->assertForbidden();
});

it('finds variants by exact barcode first in the search API', function () {
    $a = makeVariant(product: ['name' => 'Milk 123']);
    $b = makeVariant(product: ['name' => 'Bread']);
    ProductBarcode::create(['product_variant_id' => $b->id, 'barcode' => '123']);

    $this->actingAs($this->admin)->getJson(route('variants.search', ['q' => '123']))
        ->assertOk()
        ->assertJsonPath('data.0.id', $b->id)
        ->assertJsonPath('data.1.id', $a->id);
});
