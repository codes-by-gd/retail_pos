<?php

namespace App\Modules\Reports\Reports;

use App\Modules\Reports\Services\SalesFacts;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\Section;
use Illuminate\Support\Facades\DB;

/** Stock on hand right now, valued at weighted-average cost and at selling price. */
class StockValuationReport extends Report
{
    private const BY = ['variant' => 'Item (size / colour)', 'product' => 'Article', 'category' => 'Category', 'brand' => 'Brand'];

    public function key(): string
    {
        return 'stock-valuation';
    }

    public function title(): string
    {
        return 'Stock valuation';
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
        return 'Pieces in stock with their value at cost and at selling price.';
    }

    public function usesDates(): bool
    {
        return false;
    }

    public function options(): array
    {
        return ['by' => ['label' => 'Group by', 'options' => self::BY, 'default' => 'category']];
    }

    public function build(ReportFilters $f): array
    {
        $by = array_key_exists($f->option('by'), self::BY) ? $f->option('by') : 'category';

        [$key, $label, $sub] = match ($by) {
            'variant' => ['pv.id', 'MAX(p.name)', 'MAX(pv.sku)'],
            'product' => ['p.id', 'MAX(p.name)', 'MAX(p.article_no)'],
            'category' => ['COALESCE(p.category_id, 0)', 'NULL', 'NULL'],
            'brand' => ['COALESCE(p.brand_id, 0)', 'MAX(b.name)', 'NULL'],
        };

        $data = DB::table('stock_levels as sl')
            ->join('product_variants as pv', 'pv.id', '=', 'sl.product_variant_id')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->whereIn('sl.branch_id', $f->branchIds)
            ->where('sl.quantity', '!=', 0)
            ->whereNull('p.deleted_at')
            ->groupByRaw($key)
            ->selectRaw("{$key} as k, {$label} as label, {$sub} as sub, MAX(pv.name) as variant, COUNT(DISTINCT pv.id) as items,
                SUM(sl.quantity) as qty, SUM(sl.quantity * sl.avg_cost) as cost, SUM(sl.quantity * pv.selling_price) as retail,
                SUM(sl.quantity * COALESCE(pv.mrp, pv.selling_price)) as mrp")
            ->get();

        $paths = $by === 'category' ? app(SalesFacts::class)->categoryPaths() : [];
        $rows = $data->map(fn ($r) => [
            'name' => match ($by) {
                'category' => $paths[$r->k] ?? 'Uncategorised',
                'brand' => $r->label ?? 'No brand',
                'variant' => trim($r->label.($r->variant ? ' — '.$r->variant : '')),
                default => $r->label,
            },
            'sub' => $r->sub,
            'items' => (int) $r->items,
            'qty' => round((float) $r->qty, 3),
            'avg_cost' => (float) $r->qty ? round((float) $r->cost / (float) $r->qty, 2) : 0,
            'cost' => round((float) $r->cost, 2),
            'retail' => round((float) $r->retail, 2),
            'mrp' => round((float) $r->mrp, 2),
        ])->sortByDesc('cost')->values();

        $c = fn (...$a) => Section::col(...$a);
        $columns = [$c('name', self::BY[$by])];
        if (in_array($by, ['variant', 'product'], true)) {
            $columns[] = $c('sub', $by === 'variant' ? 'SKU' : 'Article no.');
        }
        if ($by !== 'variant') {
            $columns[] = $c('items', 'Items', 'int', true);
        }
        array_push($columns,
            $c('qty', 'In stock', 'qty', true),
            $c('avg_cost', 'Avg. cost', 'money'),
            $c('cost', 'Value at cost', 'money', true),
            $c('retail', 'Value at selling price', 'money', true),
            $c('mrp', 'Value at MRP', 'money', true),
        );

        return [Section::make('Stock on hand', $columns, $rows)
            ->note('As of now. Cost = weighted-average purchase (landed) cost, excluding GST. Negative stock (sold before it was received) reduces the value.')];
    }
}
