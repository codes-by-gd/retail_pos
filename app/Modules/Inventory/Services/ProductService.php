<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\AttributeValue;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductVariant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Creates/updates a product together with its variants, barcodes, image and opening stock.
 *
 * $data shape (validated by ProductRequest):
 *   product fields…, image?: UploadedFile, remove_image?: bool,
 *   variants: [{ id?, options?: {attribute_id: "value"}, sku?, barcodes: string[], purchase_price, mrp?,
 *                selling_price, wholesale_price?, reorder_level?, is_active?, opening_stock? }]
 *
 * Variable products describe each variant by attribute values (Colour = Navy, Size = 40).
 * Values that don't exist yet are created, so new sizes/colours can be typed in the form.
 */
class ProductService
{
    private const PRODUCT_FIELDS = [
        'name', 'article_no', 'type', 'category_id', 'brand_id', 'unit_id', 'tax_rate_id', 'hsn_code', 'material',
        'price_includes_tax', 'track_stock', 'description', 'is_active',
    ];

    public function __construct(
        private StockService $stock,
        private BarcodeService $barcodes,
    ) {}

    public function create(array $data, ?int $branchId = null): Product
    {
        return DB::transaction(function () use ($data, $branchId) {
            $product = Product::create(Arr::only($data, self::PRODUCT_FIELDS));
            $this->saveImage($product, $data);
            $this->syncVariants($product, $data['variants'], $branchId);

            return $product;
        });
    }

    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $product->update(Arr::only($data, self::PRODUCT_FIELDS));
            $this->saveImage($product, $data);
            $this->syncVariants($product, $data['variants'], null);

            return $product;
        });
    }

    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product) {
            // Soft delete keeps stock history and old documents intact.
            $product->variants()->each(fn (ProductVariant $v) => $v->delete());
            $product->delete();
        });
    }

    private function syncVariants(Product $product, array $rows, ?int $openingBranchId): void
    {
        $keepIds = [];

        foreach (array_values($rows) as $index => $row) {
            $valueIds = $product->type === Product::TYPE_VARIABLE ? $this->resolveValues($row['options'] ?? []) : [];

            $attributes = [
                'name' => $valueIds ? $this->nameFor($valueIds) : null,
                'purchase_price' => $row['purchase_price'] ?? 0,
                'mrp' => $row['mrp'] ?? null,
                'selling_price' => $row['selling_price'],
                'wholesale_price' => $row['wholesale_price'] ?? null,
                'reorder_level' => $row['reorder_level'] ?? 0,
                'is_active' => $row['is_active'] ?? true,
            ];

            $variant = ! empty($row['id'])
                ? $product->variants()->findOrFail($row['id'])
                : null;

            if ($variant) {
                $variant->update([...$attributes, 'sku' => ($row['sku'] ?? null) ?: $variant->sku]);
            } else {
                $variant = $product->variants()->create([
                    ...$attributes,
                    'sku' => ($row['sku'] ?? null) ?: $this->generateSku($product, $index),
                ]);
            }

            $variant->attributeValues()->sync($valueIds);
            $this->syncBarcodes($variant, $row['barcodes'] ?? []);
            $keepIds[] = $variant->id;

            if ($openingBranchId && ! empty($row['opening_stock']) && (float) $row['opening_stock'] > 0) {
                $this->stock->move(
                    $variant->setRelation('product', $product),
                    $openingBranchId,
                    $row['opening_stock'],
                    StockMovementType::Opening,
                    $row['purchase_price'] ?? 0,
                    note: 'Opening stock',
                );
            }
        }

        // Variants removed from the form are soft-deleted (history stays).
        $product->variants()->whereNotIn('id', $keepIds)->get()->each->delete();
    }

    /**
     * {attribute_id: "value"} → attribute_value ids, creating unknown values at the end of the list.
     *
     * @return list<int>
     */
    public function resolveValues(array $options): array
    {
        $ids = [];
        foreach ($options as $attributeId => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            $existing = AttributeValue::where('attribute_id', $attributeId)->where('value', $value)->first();
            $ids[] = $existing?->id ?? AttributeValue::create([
                'attribute_id' => $attributeId,
                'value' => $value,
                'sort_order' => (int) AttributeValue::where('attribute_id', $attributeId)->max('sort_order') + 1,
            ])->id;
        }

        return $ids;
    }

    /** "Navy / 40" — values ordered by their attribute's sort order. */
    public function nameFor(array $valueIds): string
    {
        return AttributeValue::query()
            ->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id')
            ->whereIn('attribute_values.id', $valueIds)
            ->orderBy('attributes.sort_order')->orderBy('attributes.name')
            ->pluck('attribute_values.value')
            ->implode(' / ');
    }

    /** Rebuild stored variant names after attribute values were renamed. */
    public function refreshVariantNames(array $valueIds): void
    {
        ProductVariant::withTrashed()
            ->whereHas('attributeValues', fn ($q) => $q->whereIn('attribute_values.id', $valueIds))
            ->with('attributeValues:id')
            ->each(fn (ProductVariant $v) => $v->updateQuietly(['name' => $this->nameFor($v->attributeValues->pluck('id')->all())]));
    }

    /** Replace the variant's barcodes; auto-generate an EAN-13 when none given. */
    private function syncBarcodes(ProductVariant $variant, array $codes): void
    {
        $codes = array_values(array_unique(array_filter(array_map('trim', $codes))));
        if (! $codes) {
            $existing = $variant->barcodes()->pluck('barcode')->all();
            $codes = $existing ?: [$this->barcodes->generateFor($variant->id)];
        }

        $variant->barcodes()->whereNotIn('barcode', $codes)->delete();
        foreach ($codes as $code) {
            $variant->barcodes()->firstOrCreate(['barcode' => $code]);
        }
    }

    private function generateSku(Product $product, int $index): string
    {
        $base = sprintf('P%05d', $product->id).($product->type === Product::TYPE_VARIABLE ? '-'.($index + 1) : '');
        $sku = $base;
        $n = 1;
        while (ProductVariant::withTrashed()->where('sku', $sku)->exists()) {
            $sku = $base.'-'.(++$n);
        }

        return $sku;
    }

    private function saveImage(Product $product, array $data): void
    {
        $image = $data['image'] ?? null;
        $remove = ! empty($data['remove_image']);

        if (($image instanceof UploadedFile || $remove) && $product->image_path) {
            Storage::disk('public')->delete($product->image_path);
            $product->update(['image_path' => null]);
        }
        if ($image instanceof UploadedFile) {
            $product->update(['image_path' => $image->store('products', 'public')]);
        }
    }
}
