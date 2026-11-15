<?php

namespace App\Modules\Reports\Reports;

use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Reports\Services\SalesFacts;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\Section;
use Illuminate\Support\Facades\DB;

/**
 * Simple trading profit & loss for the shop: net sales − cost of goods sold = gross profit;
 * − expenses (excl. GST) − stock written off/found (adjustments at cost) = net profit.
 */
class ProfitLossReport extends Report
{
    public function key(): string
    {
        return 'profit-loss';
    }

    public function title(): string
    {
        return 'Profit & loss';
    }

    public function group(): string
    {
        return self::FINANCIAL;
    }

    public function permission(): string
    {
        return 'reports.financial';
    }

    public function description(): string
    {
        return 'Net sales, cost of goods, gross profit, expenses and net profit for the period.';
    }

    public function build(ReportFilters $f): array
    {
        $t = app(SalesFacts::class)->totals($f);
        $sales = (float) $t['net_taxable'];
        $cogs = (float) $t['net_cost'];
        $gross = round($sales - $cogs, 2);

        $expenses = DB::table('expenses as e')->join('expense_categories as ec', 'ec.id', '=', 'e.expense_category_id')
            ->whereIn('e.branch_id', $f->branchIds)->whereBetween('e.date', $f->dateRange())
            ->groupBy('ec.name')->selectRaw('ec.name as name, SUM(e.amount - e.gst_amount) as amount')->orderByDesc('amount')->get();
        $expenseTotal = round((float) $expenses->sum('amount'), 2);

        // stock adjustments at cost: negative = written off (damage, theft, count short), positive = found
        $adjust = round((float) DB::table('stock_movements')->whereIn('branch_id', $f->branchIds)
            ->whereBetween('created_at', [$f->start(), $f->end()])
            ->whereIn('type', [StockMovementType::AdjustmentIn->value, StockMovementType::AdjustmentOut->value])
            ->sum(DB::raw('quantity * unit_cost')), 2);

        $net = round($gross - $expenseTotal + $adjust, 2);
        $pct = fn ($v) => $sales ? round($v / $sales * 100, 1) : null;
        $row = fn (string $label, ?float $amount, string $kind = 'line') => ['label' => $label, 'amount' => $amount, 'share' => $amount === null ? null : $pct($amount), 'kind' => $kind];

        $rows = [
            $row('Net sales (excl. GST, after returns)', round($sales, 2), 'head'),
            $row('Less: cost of goods sold', -round($cogs, 2)),
            $row('Gross profit', $gross, 'total'),
            $row('Expenses (excl. GST)', null, 'head'),
            ...$expenses->map(fn ($e) => $row('   '.$e->name, -round((float) $e->amount, 2))),
            $row('Total expenses', -$expenseTotal, 'subtotal'),
            $row($adjust < 0 ? 'Stock written off (adjustments at cost)' : ($adjust > 0 ? 'Stock found (adjustments at cost)' : 'Stock adjustments (at cost)'), $adjust),
            $row($net < 0 ? 'Net loss' : 'Net profit', $net, 'total'),
        ];

        $c = fn (...$a) => Section::col(...$a);

        return [Section::make('Profit & loss', [$c('label', 'Particulars'), $c('amount', 'Amount', 'money'), $c('share', '% of sales', 'percent')], $rows)
            ->withoutTotals()
            ->note('Sales and cost from bills and credit notes (cost saved on each bill line). Expenses exclude the GST entered on them. Not a statutory P&L: depreciation, owner drawings and interest are not included.')];
    }
}
