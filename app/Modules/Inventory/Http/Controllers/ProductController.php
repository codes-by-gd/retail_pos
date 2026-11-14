<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\ProductRequest;
use App\Modules\Inventory\Models\Attribute;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductVariant;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\TaxRate;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Inventory\Services\ProductService;
use App\Modules\Settings\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function __construct(private ProductService $products) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'category_id', 'brand_id', 'status']);
        $branchId = current_branch()->id();

        $products = Product::query()
            ->with(['category:id,name', 'brand:id,name', 'unit:id,short_name', 'taxRate:id,rate,threshold_amount,rate_above',
                'variants' => fn ($q) => $q->select('id', 'product_id', 'name', 'sku', 'selling_price', 'mrp')->with('barcodes:id,product_variant_id,barcode'),
            ])
            ->withSum(['variants as stock' => fn ($q) => $q->join('stock_levels', 'stock_levels.product_variant_id', '=', 'product_variants.id')
                ->where('stock_levels.branch_id', $branchId)], 'stock_levels.quantity')
            ->when($filters['search'] ?? null, function ($q, $s) {
                $q->where(fn ($q) => $q->where('name', 'like', "%{$s}%")
                    ->orWhere('article_no', 'like', "%{$s}%")
                    ->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', "%{$s}%")
                        ->orWhereHas('barcodes', fn ($b) => $b->where('barcode', $s))));
            })
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when($filters['brand_id'] ?? null, fn ($q, $id) => $q->where('brand_id', $id))
            ->when(($filters['status'] ?? null) === 'active', fn ($q) => $q->where('is_active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'article_no' => $p->article_no,
                'type' => $p->type,
                'image_url' => $p->imageUrl(),
                'category' => $p->category?->name,
                'brand' => $p->brand?->name,
                'unit' => $p->unit->short_name,
                'gst' => $p->taxRate->isPriceBased()
                    ? (float) $p->taxRate->rate.'/'.(float) $p->taxRate->rate_above.'%'
                    : (float) $p->taxRate->rate.'%',
                'is_active' => $p->is_active,
                'track_stock' => $p->track_stock,
                'stock' => (float) ($p->stock ?? 0),
                'variant_count' => $p->variants->count(),
                'variant_ids' => $p->variants->pluck('id'),
                'sku' => $p->variants->first()?->sku,
                'barcode' => $p->variants->first()?->barcodes->first()?->barcode,
                'price_min' => (float) $p->variants->min('selling_price'),
                'price_max' => (float) $p->variants->max('selling_price'),
            ]);

        return Inertia::render('Inventory/Products/Index', [
            'products' => $products,
            'filters' => $filters,
            'categories' => Category::options(),
            'brands' => Brand::active()->orderBy('name')->get(['id as value', 'name as label']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/Products/Form', $this->formOptions());
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $product = $this->products->create($request->validated(), current_branch()->id());

        return to_route('products.show', $product)->with('success', 'Product created.');
    }

    public function show(Product $product): Response
    {
        $product->load(['category:id,name', 'brand:id,name', 'unit', 'taxRate', 'variants.barcodes', 'variants.attributeValues.attribute']);
        $variantIds = $product->variants->pluck('id');

        $levels = StockLevel::whereIn('product_variant_id', $variantIds)->get()->groupBy('product_variant_id');
        $branches = Branch::active()->orderBy('name')->get(['id', 'name', 'code']);

        return Inertia::render('Inventory/Products/Show', [
            'product' => [
                ...$product->only(['id', 'name', 'article_no', 'material', 'type', 'hsn_code', 'price_includes_tax', 'track_stock', 'description', 'is_active']),
                'image_url' => $product->imageUrl(),
                'category' => $product->category?->name,
                'brand' => $product->brand?->name,
                'unit' => $product->unit->only(['name', 'short_name', 'allow_decimal']),
                'tax_rate' => [...$product->taxRate->only(['name', 'rate', 'cess_rate']), 'label' => $product->taxRate->label()],
            ],
            'attributes' => $this->attributesUsed($product),
            'variants' => $product->variants->map(fn (ProductVariant $v) => [
                ...$v->only(['id', 'name', 'sku', 'purchase_price', 'mrp', 'selling_price', 'wholesale_price', 'reorder_level', 'is_active']),
                'barcodes' => $v->barcodes->pluck('barcode'),
                'options' => $v->attributeValues->mapWithKeys(fn ($av) => [$av->attribute_id => $av->value]),
                'stock' => $branches->mapWithKeys(fn ($b) => [$b->id => (float) ($levels->get($v->id)?->firstWhere('branch_id', $b->id)?->quantity ?? 0)]),
                'avg_cost' => (float) ($levels->get($v->id)?->firstWhere('branch_id', current_branch()->id())?->avg_cost ?? 0),
            ]),
            'branches' => $branches,
        ]);
    }

    public function edit(Product $product): Response
    {
        $product->load(['variants.barcodes', 'variants.attributeValues']);

        return Inertia::render('Inventory/Products/Form', [
            ...$this->formOptions(),
            'product' => [
                ...$product->only(['id', 'name', 'article_no', 'material', 'type', 'category_id', 'brand_id', 'unit_id', 'tax_rate_id', 'hsn_code',
                    'price_includes_tax', 'track_stock', 'description', 'is_active']),
                'image_url' => $product->imageUrl(),
                'variants' => $product->variants->map(fn (ProductVariant $v) => [
                    ...$v->only(['id', 'name', 'sku', 'purchase_price', 'mrp', 'selling_price', 'wholesale_price', 'reorder_level', 'is_active']),
                    'barcodes' => $v->barcodes->pluck('barcode')->implode(', '),
                    'options' => $v->attributeValues->mapWithKeys(fn ($av) => [$av->attribute_id => $av->value]),
                ]),
            ],
        ]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $this->products->update($product, $request->validated());

        return to_route('products.show', $product)->with('success', 'Product updated.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->products->delete($product);

        return to_route('products.index')->with('success', "“{$product->name}” deleted.");
    }

    /** Attributes used by the product's variants, in display order: [{id, name, values: [..]}] */
    private function attributesUsed(Product $product): array
    {
        return $product->variants
            ->flatMap->attributeValues
            ->groupBy('attribute_id')
            ->map(fn ($values) => [
                'id' => $values->first()->attribute->id,
                'name' => $values->first()->attribute->name,
                'sort' => $values->first()->attribute->sort_order,
                'values' => $values->unique('id')->sortBy([['sort_order', 'asc'], ['id', 'asc']])->pluck('value')->values(),
            ])
            ->sortBy('sort')
            ->values()
            ->all();
    }

    private function formOptions(): array
    {
        return [
            'categories' => Category::options(),
            'brands' => Brand::active()->orderBy('name')->get(['id as value', 'name as label']),
            'units' => Unit::orderBy('name')->get()->map(fn ($u) => ['value' => $u->id, 'label' => "{$u->name} ({$u->short_name})", 'allow_decimal' => $u->allow_decimal]),
            'taxRates' => TaxRate::active()->orderByRaw('threshold_amount IS NULL')->orderBy('rate')->get()
                ->map(fn ($t) => ['value' => $t->id, 'label' => $t->label()]),
            'attributes' => Attribute::options(),
            'materials' => Product::query()->whereNotNull('material')->distinct()->orderBy('material')->pluck('material'),
            'defaults' => [
                'price_includes_tax' => (bool) settings('tax.prices_include_tax'),
                'unit_id' => Unit::where('short_name', 'pcs')->value('id'),
                // Clothing default: the price-based garments rate if present.
                'tax_rate_id' => TaxRate::active()->whereNotNull('threshold_amount')->value('id')
                    ?? TaxRate::active()->where('rate', 5)->value('id'),
            ],
            'currentBranch' => current_branch()->get()?->name,
        ];
    }
}
