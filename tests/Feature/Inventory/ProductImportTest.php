<?php

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductVariant;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Settings\Models\Branch;
use Database\Seeders\DefaultCatalogSeeder;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->admin = userWithRole('Admin', [$this->branch]);
    (new DefaultCatalogSeeder)->run();
});

function csvFile(array $rows): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'csv');
    $h = fopen($path, 'w');
    foreach ($rows as $r) {
        fputcsv($h, $r, escape: '');
    }
    fclose($h);

    return new UploadedFile($path, 'products.csv', 'text/csv', null, true);
}

it('imports new products, updates existing SKUs and reports bad rows', function () {
    $existing = makeVariant(['sku' => 'OLD-1', 'selling_price' => 10]);

    $file = csvFile([
        ['name', 'sku', 'category', 'brand', 'material', 'unit', 'gst', 'hsn_code', 'purchase_price', 'mrp', 'selling_price', 'opening_stock'],
        ['Silk Saree', '', 'Women > Sarees', 'House Brand', 'Silk', 'pcs', '5', '5007', '2800', '4999', '4499', '6'],
        ['Old thing', 'OLD-1', '', '', '', 'pcs', '', '', '5', '', '12', ''],
        ['Bad price', '', '', '', '', 'pcs', '', '', '5', '10', '20', ''],
        ['Bad unit', '', '', '', '', 'crate', '', '', '5', '', '20', ''],
    ]);

    $this->actingAs($this->admin)->post(route('products.import.store'), ['file' => $file])
        ->assertSessionHas('import_result', fn ($r) => $r['created'] === 1 && $r['updated'] === 1 && count($r['errors']) === 2
            && $r['errors'][0]['row'] === 4 && $r['errors'][1]['row'] === 5);

    $saree = Product::where('name', 'Silk Saree')->first();
    expect($saree->category->parent->name)->toBe('Women')
        ->and($saree->material)->toBe('Silk')
        ->and($saree->taxRate->name)->toBe('GST 5%')
        ->and(app(StockService::class)->quantity($saree->variants->first()->id, $this->branch->id))->toBe('6.000')
        ->and(ProductVariant::where('sku', 'OLD-1')->value('selling_price'))->toBe('12.00');
});

it('groups rows by article number into one product with sizes and colours', function () {
    $file = csvFile([
        ['name', 'article_no', 'category', 'brand', 'unit', 'gst', 'purchase_price', 'mrp', 'selling_price', 'opening_stock', 'Colour', 'Shirt Size'],
        ['Oxford Shirt', 'PE-1042', 'Men > Shirts', 'Peter England', 'pcs', 'Garments', '1100', '1999', '1799', '5', 'White', '40'],
        ['Oxford Shirt', 'PE-1042', 'Men > Shirts', 'Peter England', 'pcs', 'Garments', '1100', '1999', '1799', '3', 'White', '42'],
        ['Oxford Shirt', 'PE-1042', 'Men > Shirts', 'Peter England', 'pcs', 'Garments', '1100', '1999', '1799', '2', 'Lavender', '40'],
        ['Polo', 'AS-9', '', '', 'pcs', '', '500', '999', '899', '1', 'Red', ''],
    ]);

    $this->actingAs($this->admin)->post(route('products.import.store'), ['file' => $file])
        ->assertSessionHas('import_result', fn ($r) => $r['created'] === 2 && $r['errors'] === []);

    // A product may vary by only one attribute (colour only)
    expect(Product::where('article_no', 'AS-9')->first()->variants()->pluck('name')->all())->toBe(['Red']);

    $shirt = Product::where('article_no', 'PE-1042')->with('variants')->first();
    expect($shirt->type)->toBe('variable')
        ->and($shirt->taxRate->name)->toBe('Garments')
        ->and($shirt->variants->pluck('name')->all())->toBe(['White / 40', 'White / 42', 'Lavender / 40'])
        ->and(app(StockService::class)->quantity($shirt->variants[2]->id, $this->branch->id))->toBe('2.000');

    // Same article again is refused instead of creating a duplicate product
    $this->actingAs($this->admin)->post(route('products.import.store'), ['file' => csvFile([
        ['name', 'article_no', 'selling_price', 'Colour', 'Shirt Size'], ['Oxford Shirt', 'PE-1042', '1799', 'Pink', '40'],
    ])])->assertSessionHas('import_result', fn ($r) => $r['created'] === 0 && str_contains($r['errors'][0]['message'], 'already exists'));
});

it('rejects a file without the required header', function () {
    $this->actingAs($this->admin)->post(route('products.import.store'), ['file' => csvFile([['foo', 'bar'], ['1', '2']])])
        ->assertSessionHas('import_result', fn ($r) => $r['created'] === 0 && count($r['errors']) === 1);
});

it('exports products as CSV', function () {
    makeVariant(['sku' => 'EXP-1'], ['name' => 'Exported item'], $this->branch, 3);

    $response = $this->actingAs($this->admin)->get(route('products.export'))->assertOk();

    expect($response->streamedContent())->toContain('Exported item')->toContain('EXP-1');
});
