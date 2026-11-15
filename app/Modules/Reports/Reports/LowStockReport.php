<?php

namespace App\Modules\Reports\Reports;

use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\Section;
use Illuminate\Support\Facades\DB;

/** Items at or below their reorder level — the re-order list for the next purchase. */
class LowStockReport extends Report
{
    public function key(): string
    {
        return 'low-stock';
    }

    public function title(): string
    {
        return 'Low stock / reorder list';
    }

    public function group(): string
    {
        return self::INVENTORY;
    }

    public function permission(): string
    {
        return 'reports.inventory';
    }

    public function description(): string
    {
        return 'Sizes and colours at or below their reorder level, with how many to order.';
    }

    public function usesDates(): bool
    {
        return false;
    }

    public function build(ReportFilters $f): array
    {
        $rows = DB::table('product_variants as pv')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->crossJoin('branches as br')
            ->leftJoin('stock_levels as sl', fn ($j) => $j->on('sl.product_variant_id', '=', 'pv.id')->on('sl.branch_id', '=', 'br.id'))
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->whereIn('br.id', $f->branchIds)
            ->whereNull('p.deleted_at')->whereNull('pv.deleted_at')
            ->where('p.is_active', true)->where('pv.is_active', true)->where('p.track_stock', true)
            ->whereRaw('COALESCE(sl.quantity, 0) <= pv.reorder_level')
            ->orderBy('p.name')->orderBy('pv.id')
            ->get(['br.name as branch', 'p.name as product', 'p.article_no', 'pv.name as variant', 'pv.sku', 'b.name as brand', 'c.name as category',
                'pv.reorder_level', 'pv.purchase_price', DB::raw('COALESCE(sl.quantity, 0) as qty')])
            ->map(fn ($r) => [
                'branch' => $r->branch,
                'name' => trim($r->product.($r->variant ? ' — '.$r->variant : '')),
                'sku' => $r->sku,
                'article_no' => $r->article_no,
                'brand' => $r->brand,
                'category' => $r->category,
                'qty' => round((float) $r->qty, 3),
                'reorder' => round((float) $r->reorder_level, 3),
                // order back up to twice the reorder level
                'suggested' => round(max(0, 2 * (float) $r->reorder_level - (float) $r->qty), 3),
                'est_cost' => round(max(0, 2 * (float) $r->reorder_level - (float) $r->qty) * (float) $r->purchase_price, 2),
            ]);

        $c = fn (...$a) => Section::col(...$a);
        $columns = array_values(array_filter([
            count($f->branchIds) > 1 ? $c('branch', 'Branch') : null,
            $c('name', 'Item'), $c('sku', 'SKU'), $c('article_no', 'Article no.'), $c('brand', 'Brand'), $c('category', 'Category'),
            $c('qty', 'In stock', 'qty', true), $c('reorder', 'Reorder level', 'qty'),
            $c('suggested', 'Suggested order', 'qty', true), $c('est_cost', 'Est. cost', 'money', true),
        ]));

        return [Section::make('Reorder list', $columns, $rows)
            ->note('Same rule as the Stock page: stock at or below the reorder level (items without a reorder level appear once they run out). Suggested order brings stock back to twice the reorder level, at the current purchase price.')];
    }
}
