<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Attribute;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBarcode;
use App\Modules\Inventory\Models\ProductVariant;
use App\Modules\Inventory\Models\TaxRate;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV import/export of products, one row per variant (size/colour).
 *
 * Import:
 *  - Columns named after an attribute (Colour, Size, Waist…) hold that variant's values.
 *  - Rows with the same article_no (or the same name when article_no is empty) become ONE product;
 *    if any of them has attribute values it is a variable product.
 *  - A row whose SKU already exists only updates that variant's prices.
 */
class ProductCsvService
{
    /** Fixed columns; attribute columns are added dynamically. */
    public const COLUMNS = [
        'name', 'article_no', 'sku', 'barcode', 'category', 'brand', 'material', 'unit', 'gst', 'hsn_code',
        'purchase_price', 'mrp', 'selling_price', 'wholesale_price', 'reorder_level', 'opening_stock',
    ];

    public function __construct(private ProductService $products) {}

    public function export(int $branchId): StreamedResponse
    {
        $attributes = Attribute::orderBy('sort_order')->orderBy('name')->get(['id', 'name']);

        return response()->streamDownload(function () use ($branchId, $attributes) {
            $out = fopen('php://output', 'w');
            $columns = array_diff(self::COLUMNS, ['opening_stock']);
            fputcsv($out, [...$columns, ...$attributes->pluck('name'), 'stock'], escape: '');

            ProductVariant::query()
                ->with(['product.category.parent', 'product.brand', 'product.unit', 'product.taxRate', 'barcodes', 'attributeValues',
                    'stockLevels' => fn ($q) => $q->where('branch_id', $branchId)])
                ->whereHas('product')
                ->orderBy('product_id')->orderBy('id')
                ->chunk(500, function ($variants) use ($out, $attributes) {
                    foreach ($variants as $v) {
                        $p = $v->product;
                        $values = $v->attributeValues->pluck('value', 'attribute_id');
                        fputcsv($out, [
                            $p->name, $p->article_no, $v->sku, $v->barcodes->pluck('barcode')->implode('|'),
                            $p->category ? ($p->category->parent ? $p->category->parent->name.' > ' : '').$p->category->name : null,
                            $p->brand?->name, $p->material, $p->unit->short_name, $p->taxRate->name, $p->hsn_code,
                            $v->purchase_price, $v->mrp, $v->selling_price, $v->wholesale_price, $v->reorder_level,
                            ...$attributes->map(fn ($a) => $values[$a->id] ?? null),
                            $v->stockLevels->first()?->quantity ?? 0,
                        ], escape: '');
                    }
                });

            fclose($out);
        }, 'products-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, [...self::COLUMNS, 'Colour', 'Size'], escape: '');
            $shirt = ['Slim Fit Oxford Shirt', 'PE-1042', '', '', 'Men > Shirts', 'Peter England', 'Cotton', 'pcs', 'Garments', '6205', '1100', '1999', '1799', '', '5'];
            foreach ([['White', 'M', 10], ['White', 'L', 8], ['Sky Blue', 'M', 6]] as [$colour, $size, $stock]) {
                fputcsv($out, [...$shirt, $stock, $colour, $size], escape: '');
            }
            fputcsv($out, ['Cotton Socks (Pack of 3)', '', '', '', 'Accessories > Socks', '', 'Cotton', 'pack', 'Garments', '6115', '160', '349', '299', '', '10', '40', '', ''], escape: '');
            fclose($out);
        }, 'products-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array{created: int, updated: int, errors: list<array{row: int, message: string}>}
     */
    public function import(string $path, int $branchId): array
    {
        $handle = fopen($path, 'r');
        $rawHeader = fgetcsv($handle, escape: '') ?: [];
        $header = array_map(fn ($h) => mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $rawHeader);

        if (! in_array('name', $header, true) || ! in_array('selling_price', $header, true)) {
            fclose($handle);

            return ['created' => 0, 'updated' => 0, 'errors' => [['row' => 1, 'message' => 'Header row must include at least "name" and "selling_price". Download the template.']]];
        }

        // Attribute columns: any header matching an attribute name ("colour", "size", "waist"…)
        $attributeColumns = Attribute::all()->filter(fn ($a) => in_array(mb_strtolower($a->name), $header, true))
            ->mapWithKeys(fn ($a) => [mb_strtolower($a->name) => $a->id]);

        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            $line++;
            if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $row = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), null));
            $row = array_map(fn ($v) => is_string($v) ? trim($v) : $v, [...array_fill_keys(self::COLUMNS, null), ...$row]);
            $row['_line'] = $line;
            $row['_options'] = $attributeColumns->map(fn ($id, $col) => $row[$col] ?? null)->filter(fn ($v) => $v !== null && $v !== '')
                ->mapWithKeys(fn ($v, $col) => [$attributeColumns[$col] => $v])->all();
            $rows[] = $row;
        }
        fclose($handle);

        $result = ['created' => 0, 'updated' => 0, 'errors' => []];
        $ctx = [
            'units' => Unit::pluck('id', 'short_name')->mapWithKeys(fn ($id, $k) => [mb_strtolower($k) => $id]),
            'rates' => TaxRate::active()->get(),
        ];

        // 1. Rows with a known SKU → price update only.
        $new = [];
        foreach ($rows as $row) {
            if (! empty($row['sku']) && ($variant = ProductVariant::where('sku', $row['sku'])->first())) {
                $this->attempt($result, [$row['_line']], function () use ($row, $variant, &$result) {
                    $this->validateRow($row);
                    $variant->update($this->prices($row));
                    $result['updated']++;
                });
            } else {
                $new[] = $row;
            }
        }

        // 2. Remaining rows grouped into products by article no (or name).
        $groups = collect($new)->groupBy(fn ($r) => mb_strtolower($r['article_no'] ?: 'name:'.$r['name']));
        foreach ($groups as $group) {
            $this->attempt($result, $group->pluck('_line')->all(), function () use ($group, $ctx, $branchId, &$result) {
                $this->createProduct($group, $ctx, $branchId);
                $result['created']++;
            });
        }

        usort($result['errors'], fn ($a, $b) => $a['row'] <=> $b['row']);

        return $result;
    }

    private function attempt(array &$result, array $lines, callable $fn): void
    {
        try {
            DB::transaction($fn);
        } catch (\Throwable $e) {
            $label = count($lines) > 1 ? min($lines).'–'.max($lines) : $lines[0];
            $result['errors'][] = ['row' => min($lines), 'message' => (count($lines) > 1 ? "Rows {$label}: " : '').$e->getMessage()];
        }
    }

    private function createProduct(Collection $group, array $ctx, int $branchId): void
    {
        $first = $group->first();
        $group->each(fn ($r) => $this->validateRow($r));

        if ($first['article_no'] && Product::where('article_no', $first['article_no'])->exists()) {
            throw new \InvalidArgumentException("Article {$first['article_no']} already exists — add new sizes/colours from its product page.");
        }

        $unitKey = mb_strtolower($first['unit'] ?? '') ?: 'pcs';
        $unitId = $ctx['units'][$unitKey] ?? throw new \InvalidArgumentException("Unknown unit \"{$first['unit']}\". Create it first.");
        $taxRate = $this->taxRate($first['gst'], $ctx['rates']);

        $variable = $group->contains(fn ($r) => ! empty($r['_options']));
        if ($variable) {
            $keys = $group->map(fn ($r) => implode(',', array_keys($r['_options'])))->unique();
            if ($keys->count() > 1 || $keys->first() === '') {
                throw new \InvalidArgumentException('Every row of a product must fill the same size/colour columns.');
            }
        } elseif ($group->count() > 1) {
            throw new \InvalidArgumentException('Several rows share this name/article but have no size or colour — add those columns or use different names.');
        }

        foreach ($group as $r) {
            foreach (array_filter(explode('|', (string) $r['barcode'])) as $code) {
                if (ProductBarcode::where('barcode', $code)->exists()) {
                    throw new \InvalidArgumentException("Barcode {$code} already belongs to another item.");
                }
            }
        }

        $this->products->create([
            'name' => $first['name'],
            'article_no' => $first['article_no'] ?: null,
            'material' => $first['material'] ?: null,
            'type' => $variable ? Product::TYPE_VARIABLE : Product::TYPE_SINGLE,
            'category_id' => $this->categoryId($first['category'] ?? null),
            'brand_id' => ! empty($first['brand']) ? Brand::firstOrCreate(['name' => $first['brand']])->id : null,
            'unit_id' => $unitId,
            'tax_rate_id' => $taxRate->id,
            'hsn_code' => $first['hsn_code'] ?: null,
            'price_includes_tax' => (bool) settings('tax.prices_include_tax'),
            'track_stock' => true,
            'is_active' => true,
            'variants' => $group->map(fn ($r) => [
                ...$this->prices($r),
                'options' => $r['_options'],
                'sku' => $r['sku'] ?: null,
                'barcodes' => array_values(array_filter(explode('|', (string) $r['barcode']))),
                'opening_stock' => $r['opening_stock'] ?: 0,
            ])->values()->all(),
        ], $branchId);
    }

    private function validateRow(array $row): void
    {
        $validator = Validator::make($row, [
            'name' => ['required', 'string', 'max:255'],
            'article_no' => ['nullable', 'string', 'max:50'],
            'material' => ['nullable', 'string', 'max:100'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'mrp' => ['nullable', 'numeric', 'min:0', 'gte:selling_price'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'opening_stock' => ['nullable', 'numeric', 'min:0'],
            'hsn_code' => ['nullable', 'regex:/^\d{4,8}$/'],
        ], ['mrp.gte' => 'MRP cannot be lower than the selling price.', 'hsn_code.regex' => 'HSN code must be 4 to 8 digits.']);

        if ($validator->fails()) {
            throw new \InvalidArgumentException(implode(' ', $validator->errors()->all()));
        }
    }

    private function prices(array $row): array
    {
        return [
            'purchase_price' => $row['purchase_price'] ?: 0,
            'mrp' => $row['mrp'] ?: null,
            'selling_price' => $row['selling_price'],
            'wholesale_price' => $row['wholesale_price'] ?: null,
            'reorder_level' => $row['reorder_level'] ?: 0,
        ];
    }

    /** "Garments" (rate name) or "5" (a flat rate of 5%). Empty = the price-based garments rate. */
    private function taxRate(?string $value, Collection $rates): TaxRate
    {
        $value = trim((string) $value);

        if ($value === '') {
            return $rates->first(fn ($r) => $r->isPriceBased()) ?? throw new \InvalidArgumentException('Fill the "gst" column.');
        }
        if (is_numeric(rtrim($value, '%'))) {
            $pct = (float) rtrim($value, '%');

            return $rates->first(fn ($r) => ! $r->isPriceBased() && (float) $r->rate === $pct)
                ?? throw new \InvalidArgumentException("No active flat GST rate of {$pct}%.");
        }

        return $rates->first(fn ($r) => mb_strtolower($r->name) === mb_strtolower($value))
            ?? throw new \InvalidArgumentException("Unknown GST rate \"{$value}\".");
    }

    /** "Men > Shirts" or "Men › Shirts" creates the path if needed. */
    private function categoryId(?string $path): ?int
    {
        if (! $path) {
            return null;
        }

        $parentId = null;
        foreach (preg_split('/\s*(›|>)\s*/u', $path) as $name) {
            $parentId = Category::firstOrCreate(['name' => $name, 'parent_id' => $parentId])->id;
        }

        return $parentId;
    }
}
