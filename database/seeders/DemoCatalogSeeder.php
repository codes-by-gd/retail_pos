<?php

namespace Database\Seeders;

use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Attribute;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\TaxRate;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Inventory\Services\ProductService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Settings\Models\Branch;
use Illuminate\Database\Seeder;

/** Demo clothing catalog with stock in every branch. Skipped if products already exist. */
class DemoCatalogSeeder extends Seeder
{
    public function run(ProductService $products, StockService $stock): void
    {
        if (Product::withTrashed()->exists()) {
            return;
        }

        $tree = [
            'Men' => ['Shirts', 'T-Shirts', 'Jeans', 'Trousers', 'Ethnic Wear', 'Innerwear'],
            'Women' => ['Sarees', 'Kurtis', 'Tops', 'Jeans', 'Leggings', 'Dress Materials'],
            'Kids' => ['Boys', 'Girls', 'Infants'],
            'Accessories' => ['Belts', 'Socks', 'Handkerchiefs'],
            'Fabrics' => ['Shirting', 'Suiting'],
        ];
        $cat = [];
        foreach ($tree as $parent => $children) {
            $p = Category::firstOrCreate(['name' => $parent, 'parent_id' => null]);
            foreach ($children as $child) {
                $cat["{$parent} > {$child}"] = Category::firstOrCreate(['name' => $child, 'parent_id' => $p->id])->id;
            }
        }
        // Tailoring / alteration services
        $cat['Services'] = Category::firstOrCreate(['name' => 'Services', 'parent_id' => null])->id;

        $brand = fn (string $name) => Brand::firstOrCreate(['name' => $name])->id;
        $unit = fn (string $short) => Unit::where('short_name', $short)->value('id');
        $garments = TaxRate::where('name', 'Garments')->value('id');
        $gst = fn (int $rate) => TaxRate::where('name', "GST {$rate}%")->value('id');
        $attr = fn (string $name) => Attribute::where('name', $name)->value('id');

        $branches = Branch::active()->orderBy('id')->pluck('id');

        // Builds variant rows for every combination of the given attribute values.
        $matrix = function (array $dims, array $prices) use ($attr) {
            $combos = [[]];
            foreach ($dims as $attribute => $values) {
                $next = [];
                foreach ($combos as $combo) {
                    foreach ($values as $value) {
                        $next[] = $combo + [$attr($attribute) => $value];
                    }
                }
                $combos = $next;
            }

            return array_map(fn ($options) => [...$prices, 'options' => $options, 'reorder_level' => 2, 'barcodes' => []], $combos);
        };

        $items = [
            // name, article, category, brand, material, unit, tax, hsn, dims, [purchase, mrp, selling]
            ['Slim Fit Formal Shirt', 'PE-SH-1042', 'Men > Shirts', 'Peter England', 'Cotton', 'pcs', $garments, '6205',
                ['Colour' => ['White', 'Sky Blue'], 'Shirt Size' => ['38', '40', '42', '44']], [1100, 1999, 1799]],
            ['511 Slim Fit Jeans', 'LV-511-DB', 'Men > Jeans', "Levi's", 'Denim', 'pcs', $garments, '6203',
                ['Colour' => ['Navy', 'Black'], 'Waist' => ['30', '32', '34', '36']], [1900, 3299, 2999]],
            ['Round Neck Cotton T-Shirt', 'JK-2714', 'Men > T-Shirts', 'Jockey', 'Cotton', 'pcs', $garments, '6109',
                ['Colour' => ['Black', 'Navy', 'Grey'], 'Size' => ['S', 'M', 'L', 'XL', 'XXL']], [330, 599, 549]],
            ['Slim Fit Chinos', 'AS-CH-220', 'Men > Trousers', 'Allen Solly', 'Cotton Stretch', 'pcs', $garments, '6203',
                ['Colour' => ['Khaki', 'Navy'], 'Waist' => ['30', '32', '34', '36']], [1300, 2499, 2299]],
            ['Cotton Kurta Pyjama Set', 'MV-KS-88', 'Men > Ethnic Wear', 'Manyavar', 'Cotton Silk', 'set', $garments, '6211',
                ['Size' => ['M', 'L', 'XL', 'XXL']], [2100, 3999, 3499]],
            ['Printed Anarkali Kurti', 'BB-KT-310', 'Women > Kurtis', 'Biba', 'Rayon', 'pcs', $garments, '6204',
                ['Colour' => ['Maroon', 'Teal'], 'Size' => ['S', 'M', 'L', 'XL']], [950, 1899, 1699]],
            ['Cotton Lycra Leggings', 'GC-LG-01', 'Women > Leggings', 'Go Colors', 'Cotton Lycra', 'pcs', $garments, '6104',
                ['Colour' => ['Black', 'White', 'Maroon', 'Navy'], 'Size' => ['Free Size']], [220, 499, 399]],
            ['Girls Cotton Frock', 'HB-FR-12', 'Kids > Girls', 'House Brand', 'Cotton', 'pcs', $garments, '6209',
                ['Colour' => ['Pink', 'Yellow'], 'Kids Age' => ['2-3Y', '3-4Y', '4-5Y', '5-6Y']], [380, 799, 699]],
            ['Boys Denim Shorts', 'HB-SH-07', 'Kids > Boys', 'House Brand', 'Denim', 'pcs', $garments, '6203',
                ['Kids Age' => ['4-5Y', '6-7Y', '8-9Y', '10-12Y']], [420, 899, 799]],
        ];

        $qtyFor = fn (int $i) => [6, 4, 8, 3, 5, 1, 7, 2][$i % 8];   // varied stock, some low

        foreach ($items as $n => [$name, $article, $category, $brandName, $material, $unitShort, $taxId, $hsn, $dims, [$cost, $mrp, $price]]) {
            $variants = $matrix($dims, ['purchase_price' => $cost, 'mrp' => $mrp, 'selling_price' => $price]);
            foreach ($variants as $i => &$v) {
                $v['opening_stock'] = $qtyFor($i + $n);
            }
            unset($v);

            $product = $products->create([
                'name' => $name, 'article_no' => $article, 'type' => Product::TYPE_VARIABLE, 'category_id' => $cat[$category],
                'brand_id' => $brand($brandName), 'material' => $material, 'unit_id' => $unit($unitShort), 'tax_rate_id' => $taxId,
                'hsn_code' => $hsn, 'price_includes_tax' => true, 'track_stock' => true, 'is_active' => true, 'variants' => $variants,
            ], $branches->first());
            $this->stockOtherBranches($stock, $product, $branches, $cost);
        }

        // Single items: sarees, fabric by the metre, accessories, and a non-stock service.
        $singles = [
            ['Banarasi Silk Saree', 'SR-BN-501', 'Women > Sarees', 'House Brand', 'Silk', 'pcs', $gst(5), '5007', [2800, 4999, 4499], 6, true],
            ['Cotton Shirting Fabric', 'FB-SHT-40', 'Fabrics > Shirting', 'Raymond', 'Cotton', 'mtr', $gst(5), '5208', [180, null, 290], 120.5, true],
            ['Wool Blend Suiting Fabric', 'FB-SUT-11', 'Fabrics > Suiting', 'Raymond', 'Wool Blend', 'mtr', $gst(5), '5112', [420, null, 650], 85.5, true],
            ['Genuine Leather Belt', 'WC-BLT-09', 'Accessories > Belts', 'Woodland', 'Leather', 'pcs', $gst(18), '4203', [450, 999, 899], 12, true],
            ['Cotton Ankle Socks (Pack of 3)', 'JK-SK-7', 'Accessories > Socks', 'Jockey', 'Cotton', 'pack', $garments, '6115', [160, 349, 299], 30, true],
            ['Alteration Charges', null, 'Services', null, null, 'pcs', $gst(5), '998821', [0, null, 100], 0, false],
        ];

        foreach ($singles as [$name, $article, $category, $brandName, $material, $unitShort, $taxId, $hsn, [$cost, $mrp, $price], $qty, $track]) {
            $product = $products->create([
                'name' => $name, 'article_no' => $article, 'type' => Product::TYPE_SINGLE, 'category_id' => $cat[$category],
                'brand_id' => $brandName ? $brand($brandName) : null, 'material' => $material, 'unit_id' => $unit($unitShort),
                'tax_rate_id' => $taxId, 'hsn_code' => $hsn, 'price_includes_tax' => true, 'track_stock' => $track, 'is_active' => true,
                'variants' => [['purchase_price' => $cost, 'mrp' => $mrp, 'selling_price' => $price, 'reorder_level' => $unitShort === 'mtr' ? 20 : 3,
                    'barcodes' => [], 'opening_stock' => $qty]],
            ], $branches->first());
            if ($track) {
                $this->stockOtherBranches($stock, $product, $branches, $cost);
            }
        }
    }

    /** Other branches get roughly half the stock. */
    private function stockOtherBranches(StockService $stock, Product $product, $branches, $cost): void
    {
        foreach ($branches->skip(1) as $branchId) {
            foreach ($product->variants()->get() as $i => $variant) {
                $qty = $product->unit->allow_decimal ? 40 : [3, 2, 4, 0, 1][$i % 5];
                if ($qty > 0) {
                    $stock->move($variant, $branchId, $qty, StockMovementType::Opening, $cost, note: 'Opening stock');
                }
            }
        }
    }
}
