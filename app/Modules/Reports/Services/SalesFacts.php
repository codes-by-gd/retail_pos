<?php

namespace App\Modules\Reports\Services;

use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\Sql;
use App\Modules\Sales\Models\Sale;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sales facts shared by the reports and the dashboard.
 *
 * Period rule (same as GST): a bill counts on its bill date, a return on its credit-note date.
 * "Net" = sold − returned in the period. Cost of a sold line = quantity × average cost saved on the line;
 * a restocked return gives that cost back, a damaged return (not restocked) does not.
 */
class SalesFacts
{
    public const DIMENSIONS = ['all', 'variant', 'product', 'category', 'brand', 'attribute', 'cashier', 'branch', 'customer', 'day', 'month', 'hour'];

    /** Completed bill lines in the period. Aliases: si (sale_items), s (sales). */
    public function saleLines(ReportFilters $f): Builder
    {
        return DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.status', Sale::COMPLETED)
            ->whereIn('s.branch_id', $f->branchIds)
            ->whereBetween('s.sold_at', [$f->start(), $f->end()]);
    }

    /** Returned lines whose credit note falls in the period. Aliases: ri, r (sale_returns), si, s. */
    public function returnLines(ReportFilters $f): Builder
    {
        return DB::table('sale_return_items as ri')
            ->join('sale_returns as r', 'r.id', '=', 'ri.sale_return_id')
            ->join('sale_items as si', 'si.id', '=', 'ri.sale_item_id')
            ->join('sales as s', 's.id', '=', 'r.sale_id')
            ->whereIn('r.branch_id', $f->branchIds)
            ->whereBetween('r.returned_at', [$f->start(), $f->end()]);
    }

    /**
     * Net sales grouped by a dimension.
     *
     * @return Collection<int, array> rows with key, label, sub, sort, bills, sold_*, ret_*, net_* (qty, taxable, tax, total, cost), profit
     */
    public function byDimension(ReportFilters $f, string $dimension, array $options = []): Collection
    {
        $sold = $this->sideQuery($this->saleLines($f), $dimension, false, $options)
            ->selectRaw('COUNT(DISTINCT s.id) as bills, SUM(si.quantity) as qty, SUM(si.taxable_value) as taxable,
                SUM(si.cgst + si.sgst + si.igst + si.cess) as tax, SUM(si.line_total) as total, SUM(si.quantity * si.unit_cost) as cost')
            ->get();

        $returned = $this->sideQuery($this->returnLines($f), $dimension, true, $options)
            ->selectRaw('0 as bills, SUM(ri.quantity) as qty, SUM(ri.taxable_value) as taxable,
                SUM(ri.cgst + ri.sgst + ri.igst + ri.cess) as tax, SUM(ri.line_total) as total,
                SUM(CASE WHEN ri.restock = 1 THEN ri.quantity * si.unit_cost ELSE 0 END) as cost')
            ->get();

        $rows = [];
        foreach ([['sold', $sold], ['ret', $returned]] as [$side, $list]) {
            foreach ($list as $r) {
                if ($r->qty === null) {
                    continue;   // aggregate over no rows
                }
                $k = (string) $r->k;
                $rows[$k] ??= ['key' => $r->k, 'label' => $r->label, 'sub' => $r->sub, 'sort' => $r->sort, 'bills' => 0,
                    'sold_qty' => 0.0, 'sold_taxable' => 0.0, 'sold_tax' => 0.0, 'sold_total' => 0.0, 'sold_cost' => 0.0,
                    'ret_qty' => 0.0, 'ret_taxable' => 0.0, 'ret_tax' => 0.0, 'ret_total' => 0.0, 'ret_cost' => 0.0];
                $rows[$k]['label'] ??= $r->label;
                $rows[$k]['sub'] ??= $r->sub;
                $rows[$k]['bills'] += (int) $r->bills;
                foreach (['qty', 'taxable', 'tax', 'total', 'cost'] as $m) {
                    $rows[$k]["{$side}_{$m}"] += (float) $r->{$m};
                }
            }
        }

        $paths = $dimension === 'category' ? $this->categoryPaths() : [];

        return collect($rows)->map(function (array $r) use ($dimension, $paths) {
            foreach (['qty' => 3, 'taxable' => 2, 'tax' => 2, 'total' => 2, 'cost' => 2] as $m => $scale) {
                $r["sold_{$m}"] = round($r["sold_{$m}"], $scale);
                $r["ret_{$m}"] = round($r["ret_{$m}"], $scale);
                $r["net_{$m}"] = round($r["sold_{$m}"] - $r["ret_{$m}"], $scale);
            }
            $r['profit'] = round($r['net_taxable'] - $r['net_cost'], 2);
            $r['margin'] = $r['net_taxable'] != 0.0 ? round($r['profit'] / $r['net_taxable'] * 100, 1) : null;
            $r['label'] = match ($dimension) {
                'category' => $paths[$r['key']] ?? 'Uncategorised',
                'brand' => $r['label'] ?? 'No brand',
                'attribute' => $r['label'] ?? 'Not set',
                'hour' => sprintf('%02d:00 – %02d:00', $r['key'], ((int) $r['key'] + 1) % 24),
                'month' => Carbon::createFromFormat('Y-m-d', $r['key'].'-01')->format('M Y'),
                default => $r['label'],
            };

            return $r;
        })->values()->pipe(fn (Collection $c) => match ($dimension) {
            'day', 'month', 'hour' => $c->sortBy('key')->values(),
            'attribute' => $c->sortBy([['sort', 'asc'], ['label', 'asc']])->values(),
            default => $c->sortByDesc('net_total')->values(),
        });
    }

    /** One-row totals for the period (net of returns). */
    public function totals(ReportFilters $f): array
    {
        return $this->byDimension($f, 'all')->first() ?? [
            'bills' => 0, 'sold_qty' => 0, 'ret_qty' => 0, 'net_qty' => 0, 'sold_total' => 0, 'ret_total' => 0, 'net_total' => 0,
            'net_taxable' => 0, 'net_tax' => 0, 'net_cost' => 0, 'profit' => 0, 'margin' => null,
        ];
    }

    /** "Men › Shirts" for every category id. */
    public function categoryPaths(): array
    {
        $all = DB::table('categories')->get(['id', 'parent_id', 'name'])->keyBy('id');
        $path = function ($id, $depth = 0) use (&$path, $all) {
            $c = $all[$id] ?? null;
            if (! $c) {
                return null;
            }

            return $c->parent_id && $depth < 5 && isset($all[$c->parent_id]) ? $path($c->parent_id, $depth + 1).' › '.$c->name : $c->name;
        };

        return $all->keys()->mapWithKeys(fn ($id) => [$id => $path($id)])->all();
    }

    private function sideQuery(Builder $q, string $dimension, bool $isReturn, array $options): Builder
    {
        $at = $isReturn ? 'r.returned_at' : 's.sold_at';
        $userCol = $isReturn ? 'r.user_id' : 's.user_id';
        $branchCol = $isReturn ? 'r.branch_id' : 's.branch_id';
        $joinProduct = fn () => $q->join('product_variants as pv', 'pv.id', '=', 'si.product_variant_id')->join('products as p', 'p.id', '=', 'pv.product_id');

        [$key, $label, $sub, $sort] = match ($dimension) {
            'all' => ["'all'", 'NULL', 'NULL', 'NULL'],
            'variant' => ['si.product_variant_id', 'MAX(si.product_name)', "MAX(COALESCE(si.variant_name, '') || '|' || si.sku)", 'NULL'],
            'product' => [$joinProduct() ? 'pv.product_id' : '', 'MAX(si.product_name)', 'MAX(p.article_no)', 'NULL'],
            'category' => [$joinProduct() ? 'COALESCE(p.category_id, 0)' : '', 'NULL', 'NULL', 'NULL'],
            'brand' => [$joinProduct()->leftJoin('brands as b', 'b.id', '=', 'p.brand_id') ? 'COALESCE(p.brand_id, 0)' : '', 'MAX(b.name)', 'NULL', 'NULL'],
            'attribute' => [$this->joinAttribute($q, (int) ($options['attribute_id'] ?? 0)), 'MAX(av.value)', 'NULL', 'MAX(av.sort_order)'],
            'cashier' => [$q->join('users as u', 'u.id', '=', $userCol) ? $userCol : '', 'MAX(u.name)', 'NULL', 'NULL'],
            'branch' => [$q->join('branches as br', 'br.id', '=', $branchCol) ? $branchCol : '', 'MAX(br.name)', 'NULL', 'NULL'],
            'customer' => ['s.customer_id', 'MAX(s.customer_name)', 'MAX(s.customer_phone)', 'NULL'],
            'day' => [Sql::date($at), 'NULL', 'NULL', 'NULL'],
            'month' => [Sql::month($at), 'NULL', 'NULL', 'NULL'],
            'hour' => [Sql::hour($at), 'NULL', 'NULL', 'NULL'],
        };

        // MySQL has no `||` string concatenation by default.
        if ($dimension === 'variant' && DB::connection()->getDriverName() !== 'sqlite') {
            $sub = "MAX(CONCAT(COALESCE(si.variant_name, ''), '|', si.sku))";
        }

        $q->selectRaw("{$key} as k, {$label} as label, {$sub} as sub, {$sort} as sort");

        return $dimension === 'all' ? $q : $q->groupByRaw($key);
    }

    private function joinAttribute(Builder $q, int $attributeId): string
    {
        $values = DB::table('attribute_value_product_variant as avpv')
            ->join('attribute_values as av0', 'av0.id', '=', 'avpv.attribute_value_id')
            ->where('av0.attribute_id', $attributeId)
            ->select('avpv.product_variant_id', 'av0.id', 'av0.value', 'av0.sort_order');
        $q->leftJoinSub($values, 'av', 'av.product_variant_id', '=', 'si.product_variant_id');

        return 'COALESCE(av.id, 0)';
    }
}
