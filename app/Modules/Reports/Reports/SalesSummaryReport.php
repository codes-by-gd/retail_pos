<?php

namespace App\Modules\Reports\Reports;

use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\Section;
use App\Modules\Reports\Support\Sql;
use App\Modules\Sales\Models\Sale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Bills, discounts, GST and returns per day or month — the "how did we do" report. */
class SalesSummaryReport extends Report
{
    public function key(): string
    {
        return 'sales-summary';
    }

    public function title(): string
    {
        return 'Sales summary';
    }

    public function group(): string
    {
        return self::SALES;
    }

    public function permission(): string
    {
        return 'reports.sales';
    }

    public function description(): string
    {
        return 'Bills, discounts, GST, returns and net sales per day or month.';
    }

    public function options(): array
    {
        return ['by' => ['label' => 'Group by', 'options' => ['day' => 'Day', 'month' => 'Month'], 'default' => 'day']];
    }

    public function chart(ReportFilters $f): ?array
    {
        return ['x' => 'label', 'y' => 'net_sales', 'label' => 'Net sales'];
    }

    public function build(ReportFilters $f): array
    {
        $month = $f->option('by') === 'month';
        $period = fn (string $col) => $month ? Sql::month($col) : Sql::date($col);

        $sales = DB::table('sales')->where('status', Sale::COMPLETED)->whereIn('branch_id', $f->branchIds)
            ->whereBetween('sold_at', [$f->start(), $f->end()])
            ->selectRaw($period('sold_at').' as p, COUNT(*) as bills, SUM(subtotal) as gross, SUM(line_discount_total + bill_discount_total) as discount,
                SUM(taxable_total) as taxable, SUM(cgst_total + sgst_total + igst_total + cess_total) as tax, SUM(round_off) as round_off, SUM(grand_total) as total')
            ->groupByRaw($period('sold_at'))->get()->keyBy('p');

        $qty = DB::table('sale_items as si')->join('sales as s', 's.id', '=', 'si.sale_id')->where('s.status', Sale::COMPLETED)
            ->whereIn('s.branch_id', $f->branchIds)->whereBetween('s.sold_at', [$f->start(), $f->end()])
            ->selectRaw($period('s.sold_at').' as p, SUM(si.quantity) as qty')->groupByRaw($period('s.sold_at'))->pluck('qty', 'p');

        $returns = DB::table('sale_returns')->whereIn('branch_id', $f->branchIds)->whereBetween('returned_at', [$f->start(), $f->end()])
            ->selectRaw($period('returned_at').' as p, COUNT(*) as notes, SUM(total) as total')
            ->groupByRaw($period('returned_at'))->get()->keyBy('p');

        $keys = collect($sales->keys())->merge($returns->keys())->unique()->sort()->values();

        $rows = $keys->map(function ($p) use ($sales, $returns, $qty, $month) {
            $s = $sales[$p] ?? null;
            $r = $returns[$p] ?? null;
            $total = (float) ($s->total ?? 0);
            $ret = (float) ($r->total ?? 0);

            return [
                'label' => $month ? Carbon::createFromFormat('Y-m-d', $p.'-01')->format('M Y') : Carbon::parse($p)->format('d M Y'),
                'weekday' => $month ? null : Carbon::parse($p)->format('l'),
                'bills' => (int) ($s->bills ?? 0),
                'qty' => round((float) ($qty[$p] ?? 0), 3),
                'gross' => round((float) ($s->gross ?? 0), 2),
                'discount' => round((float) ($s->discount ?? 0), 2),
                'taxable' => round((float) ($s->taxable ?? 0), 2),
                'tax' => round((float) ($s->tax ?? 0), 2),
                'total' => round($total, 2),
                'returns' => round($ret, 2),
                'net_sales' => round($total - $ret, 2),
                'average' => ($s->bills ?? 0) ? round($total / $s->bills, 2) : 0,
            ];
        });

        $c = fn (...$a) => Section::col(...$a);

        return [
            Section::make($month ? 'By month' : 'By day', [
                $c('label', $month ? 'Month' : 'Date'),
                ...($month ? [] : [$c('weekday', 'Day')]),
                $c('bills', 'Bills', 'int', true),
                $c('qty', 'Pieces', 'qty', true),
                $c('gross', 'Gross', 'money', true),
                $c('discount', 'Discounts', 'money', true),
                $c('taxable', 'Taxable', 'money', true),
                $c('tax', 'GST', 'money', true),
                $c('total', 'Bill total', 'money', true),
                $c('returns', 'Returns', 'money', true),
                $c('net_sales', 'Net sales', 'money', true),
                $c('average', 'Avg. bill', 'money', true),
            ], $rows)->totalsUsing(fn ($t) => [...$t, 'average' => $t['bills'] ? round($t['total'] / $t['bills'], 2) : 0])
                ->note('Gross = list price × quantity (GST-inclusive items include GST). Returns are counted on the credit-note date. Cancelled bills are excluded.'),
        ];
    }
}
