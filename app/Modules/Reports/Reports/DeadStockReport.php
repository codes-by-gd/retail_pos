<?php

namespace App\Modules\Reports\Reports;

use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\Section;
use App\Modules\Sales\Models\Sale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** In-stock items that haven't sold for N days — candidates for a sale / end-of-season discount. */
class DeadStockReport extends Report
{
    public function key(): string
    {
        return 'slow-moving';
    }

    public function title(): string
    {
        return 'Slow-moving & dead stock';
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
        return 'Stock that has not sold in 30–365 days, with its value — plan the end-of-season sale.';
    }

    public function usesDates(): bool
    {
        return false;
    }

    public function options(): array
    {
        return ['days' => ['label' => 'Not sold for', 'options' => ['30' => '30 days', '60' => '60 days', '90' => '90 days', '180' => '180 days', '365' => '1 year'], 'default' => '90']];
    }

    public function build(ReportFilters $f): array
    {
        $days = in_array((string) $f->option('days'), ['30', '60', '90', '180', '365'], true) ? (int) $f->option('days') : 90;
        $cutoff = now()->subDays($days);

        $lastSold = DB::table('sale_items as si')->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.status', Sale::COMPLETED)->whereIn('s.branch_id', $f->branchIds)
            ->groupBy('si.product_variant_id')->selectRaw('si.product_variant_id as vid, MAX(s.sold_at) as last_sold');
        $firstIn = DB::table('stock_movements')->whereIn('branch_id', $f->branchIds)->where('quantity', '>', 0)
            ->groupBy('product_variant_id')->selectRaw('product_variant_id as vid, MIN(created_at) as first_in, MAX(created_at) as last_in');

        $rows = DB::table('stock_levels as sl')
            ->join('product_variants as pv', 'pv.id', '=', 'sl.product_variant_id')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoinSub($lastSold, 'ls', 'ls.vid', '=', 'pv.id')
            ->leftJoinSub($firstIn, 'fi', 'fi.vid', '=', 'pv.id')
            ->whereIn('sl.branch_id', $f->branchIds)->where('sl.quantity', '>', 0)->whereNull('p.deleted_at')
            ->where(fn ($q) => $q->whereNull('ls.last_sold')->orWhere('ls.last_sold', '<', $cutoff))
            ->where(fn ($q) => $q->whereNull('fi.first_in')->orWhere('fi.first_in', '<', $cutoff))   // in stock long enough to judge
            ->groupBy('pv.id')
            ->selectRaw('MAX(p.name) as product, MAX(pv.name) as variant, MAX(pv.sku) as sku, MAX(b.name) as brand, SUM(sl.quantity) as qty,
                SUM(sl.quantity * sl.avg_cost) as cost, SUM(sl.quantity * pv.selling_price) as retail, MAX(ls.last_sold) as last_sold, MAX(fi.last_in) as last_in')
            ->get()
            ->map(fn ($r) => [
                'name' => trim($r->product.($r->variant ? ' — '.$r->variant : '')),
                'sku' => $r->sku,
                'brand' => $r->brand,
                'qty' => round((float) $r->qty, 3),
                'cost' => round((float) $r->cost, 2),
                'retail' => round((float) $r->retail, 2),
                'last_sold' => $r->last_sold ? Carbon::parse($r->last_sold)->toDateString() : null,
                'days' => $r->last_sold ? (int) Carbon::parse($r->last_sold)->startOfDay()->diffInDays(today()) : null,
                'last_in' => $r->last_in ? Carbon::parse($r->last_in)->toDateString() : null,
            ])
            ->sortByDesc('cost')->values();

        $c = fn (...$a) => Section::col(...$a);

        return [Section::make("Not sold in {$days} days", [
            $c('name', 'Item'), $c('sku', 'SKU'), $c('brand', 'Brand'),
            $c('qty', 'In stock', 'qty', true), $c('cost', 'Value at cost', 'money', true), $c('retail', 'Value at selling price', 'money', true),
            $c('last_sold', 'Last sold', 'date'), $c('days', 'Days since sale', 'int'), $c('last_in', 'Last received', 'date'),
        ], $rows)->note('Only stock that has been on hand for at least the chosen period. "Last sold" empty = never sold here.')];
    }
}
