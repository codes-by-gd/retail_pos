<?php

namespace App\Modules\Reports\Reports;

use App\Modules\Inventory\Enums\StockMovementType as T;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\Section;
use Illuminate\Support\Facades\DB;

/** Opening → purchases, sales, returns, transfers, adjustments → closing, per item, from the stock ledger. */
class StockMovementReport extends Report
{
    public function key(): string
    {
        return 'stock-movement';
    }

    public function title(): string
    {
        return 'Stock movement summary';
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
        return 'Opening stock, everything in and out during the period, and closing stock per item.';
    }

    public function build(ReportFilters $f): array
    {
        $groups = [
            'purchased' => [T::Purchase],
            'purchase_returns' => [T::PurchaseReturn],
            'sold' => [T::Sale, T::SaleCancel],
            'sale_returns' => [T::SaleReturn],
            'transfers' => [T::TransferIn, T::TransferOut, T::TransferCancel],
            'adjustments' => [T::AdjustmentIn, T::AdjustmentOut, T::Opening, T::Import],
        ];
        $sums = collect($groups)->map(function ($types, $k) {
            $list = "'".implode("','", array_map(fn ($t) => $t->value, $types))."'";

            return "SUM(CASE WHEN sm.created_at >= ? AND sm.type IN ({$list}) THEN sm.quantity ELSE 0 END) as {$k}";
        })->implode(', ');

        $data = DB::table('stock_movements as sm')
            ->join('product_variants as pv', 'pv.id', '=', 'sm.product_variant_id')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->whereIn('sm.branch_id', $f->branchIds)
            ->where('sm.created_at', '<=', $f->end())
            ->groupBy('pv.id')
            ->selectRaw("MAX(p.name) as product, MAX(pv.name) as variant, MAX(pv.sku) as sku,
                SUM(CASE WHEN sm.created_at < ? THEN sm.quantity ELSE 0 END) as opening, {$sums}, SUM(sm.quantity) as closing",
                array_fill(0, count($groups) + 1, $f->start()))
            ->orderByRaw('MAX(p.name)')->orderBy('pv.id')
            ->get();

        $rows = $data->map(function ($r) {
            $row = ['name' => trim($r->product.($r->variant ? ' — '.$r->variant : '')), 'sku' => $r->sku];
            foreach (['opening', 'purchased', 'purchase_returns', 'sold', 'sale_returns', 'transfers', 'adjustments', 'closing'] as $k) {
                $v = (float) $r->{$k};
                // show outward quantities as positive numbers
                $row[$k] = round(in_array($k, ['purchase_returns', 'sold'], true) ? -$v : $v, 3);
            }

            return $row;
        })->filter(fn ($r) => $r['opening'] || $r['closing'] || $r['purchased'] || $r['sold'] || $r['sale_returns'] || $r['purchase_returns'] || $r['transfers'] || $r['adjustments'])->values();

        $c = fn (...$a) => Section::col(...$a);

        return [Section::make('Movement per item', [
            $c('name', 'Item'), $c('sku', 'SKU'),
            $c('opening', 'Opening', 'qty', true),
            $c('purchased', 'Purchased', 'qty', true),
            $c('purchase_returns', 'Returned to supplier', 'qty', true),
            $c('sold', 'Sold', 'qty', true),
            $c('sale_returns', 'Customer returns', 'qty', true),
            $c('transfers', 'Transfers (net)', 'qty', true),
            $c('adjustments', 'Adjustments (net)', 'qty', true),
            $c('closing', 'Closing', 'qty', true),
        ], $rows)->note('From the stock ledger. Opening + purchased − returned to supplier − sold + customer returns ± transfers ± adjustments = closing. Cancelled bills reduce "Sold".')];
    }
}
