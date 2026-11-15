<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON item lookup used by stock forms, label printing and (later) the POS.
 * Exact barcode/SKU matches come first, then name/SKU "contains" matches.
 */
class VariantSearchController extends Controller
{
    public const ABILITIES = ['products.view', 'stock.adjust', 'stock.transfer', 'labels.print', 'pos.access', 'purchases.manage', 'quotations.manage'];

    /** Relations present() needs — eager-load these. */
    public const WITH = ['product:id,name,article_no,brand_id,unit_id,tax_rate_id,track_stock,hsn_code,price_includes_tax', 'product.unit:id,short_name,allow_decimal',
        'product.brand:id,name', 'product.taxRate:id,rate,cess_rate,threshold_amount,rate_above', 'barcodes:id,product_variant_id,barcode', 'stockHere'];

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()->canAny(self::ABILITIES), 403);

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'ids' => ['nullable', 'array', 'max:500'],
            'ids.*' => ['integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $q = trim($data['q'] ?? '');
        $branchId = current_branch()->id();

        $query = ProductVariant::query()
            ->active()
            ->whereHas('product', fn ($p) => $p->where('is_active', true))
            ->with(self::WITH);

        if (! empty($data['ids'])) {
            $variants = $query->whereIn('id', $data['ids'])->get();
        } elseif ($q === '') {
            return response()->json(['data' => []]);
        } else {
            $exact = (clone $query)
                ->where(fn ($w) => $w->where('sku', $q)->orWhereHas('barcodes', fn ($b) => $b->where('barcode', $q)))
                ->limit(5)->get();

            $like = (clone $query)
                ->whereNotIn('id', $exact->pluck('id'))
                ->where(function ($w) use ($q) {
                    $w->where('sku', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%")
                        ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$q}%")->orWhere('article_no', $q));
                })
                ->orderBy('product_id')
                ->limit(($data['limit'] ?? 15))
                ->get();

            $variants = $exact->concat($like);
        }

        return response()->json([
            'data' => $variants->map(fn (ProductVariant $v) => self::present($v))->values(),
        ]);
    }

    /** Item as the POS cart, label printing and purchase forms expect it. */
    public static function present(ProductVariant $v): array
    {
        return [
            'id' => $v->id,
            'product_id' => $v->product_id,
            'name' => $v->fullName(),
            'product_name' => $v->product->name,
            'variant_name' => $v->name,
            'article_no' => $v->product->article_no,
            'brand' => $v->product->brand?->name,
            'sku' => $v->sku,
            'barcode' => $v->barcodes->first()?->barcode,
            'barcodes' => $v->barcodes->pluck('barcode'),
            'mrp' => $v->mrp !== null ? (float) $v->mrp : null,
            'selling_price' => (float) $v->selling_price,
            'purchase_price' => (float) $v->purchase_price,
            'wholesale_price' => $v->wholesale_price !== null ? (float) $v->wholesale_price : null,
            'price_includes_tax' => $v->product->price_includes_tax,
            'gst_cess' => (float) $v->product->taxRate->cess_rate,
            'unit' => $v->product->unit->short_name,
            'allow_decimal' => $v->product->unit->allow_decimal,
            'gst_rate' => (float) $v->product->taxRate->rate,
            'gst_threshold' => $v->product->taxRate->threshold_amount !== null ? (float) $v->product->taxRate->threshold_amount : null,
            'gst_rate_above' => $v->product->taxRate->rate_above !== null ? (float) $v->product->taxRate->rate_above : null,
            'track_stock' => $v->product->track_stock,
            'stock' => (float) ($v->stockHere?->quantity ?? 0),
            'avg_cost' => (float) ($v->stockHere?->avg_cost ?? 0),
        ];
    }
}
