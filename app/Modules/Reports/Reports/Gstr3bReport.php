<?php

namespace App\Modules\Reports\Reports;

use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\Section;
use App\Modules\Sales\Models\Sale;
use App\Support\Gst\IndianStates;
use Illuminate\Support\Facades\DB;

/** GSTR-3B summary for one GSTIN and month: outward tax (net of credit notes), inter-state B2C, input tax credit, indicative net tax. */
class Gstr3bReport extends Report
{
    private const HEADS = ['taxable', 'igst', 'cgst', 'sgst', 'cess'];

    public function key(): string
    {
        return 'gstr-3b';
    }

    public function title(): string
    {
        return 'GSTR-3B summary';
    }

    public function group(): string
    {
        return self::GST;
    }

    public function permission(): string
    {
        return 'reports.financial';
    }

    public function description(): string
    {
        return 'Output tax, input tax credit from purchases and the indicative tax payable.';
    }

    public function scope(): ?string
    {
        return 'gstin';
    }

    public function defaultRange(): string
    {
        return 'last_month';
    }

    public function build(ReportFilters $f): array
    {
        $sum = 'SUM(%1$s.taxable_value) as taxable, SUM(%1$s.igst) as igst, SUM(%1$s.cgst) as cgst, SUM(%1$s.sgst) as sgst, SUM(%1$s.cess) as cess';
        $lines = fn () => DB::table('sale_items as si')->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.status', Sale::COMPLETED)->whereIn('s.branch_id', $f->branchIds)->whereBetween('s.sold_at', [$f->start(), $f->end()]);
        $returnLines = fn () => DB::table('sale_return_items as ri')->join('sale_returns as r', 'r.id', '=', 'ri.sale_return_id')
            ->join('sale_items as si', 'si.id', '=', 'ri.sale_item_id')->join('sales as s', 's.id', '=', 'r.sale_id')
            ->whereIn('r.branch_id', $f->branchIds)->whereBetween('r.returned_at', [$f->start(), $f->end()]);
        $net = function (callable $scope) use ($lines, $returnLines, $sum) {
            $a = $scope($lines())->selectRaw(sprintf($sum, 'si'))->first();
            $b = $scope($returnLines())->selectRaw(sprintf($sum, 'ri'))->first();

            return collect(self::HEADS)->mapWithKeys(fn ($h) => [$h => round((float) $a->{$h} - (float) $b->{$h}, 2)])->all();
        };

        $taxed = $net(fn ($q) => $q->where('si.gst_rate', '>', 0));
        $nil = $net(fn ($q) => $q->where('si.gst_rate', 0));

        // 3.2 — inter-state supplies to unregistered persons, by place of supply
        $unreg = fn ($q) => $q->where('s.is_inter_state', true)->where(fn ($x) => $x->whereNull('s.customer_gstin')->orWhere('s.customer_gstin', ''));
        $byPos = [];
        foreach ([[1, $unreg($lines())->groupBy('s.place_of_supply')->selectRaw('s.place_of_supply as pos, SUM(si.taxable_value) as taxable, SUM(si.igst) as igst')->get()],
            [-1, $unreg($returnLines())->groupBy('s.place_of_supply')->selectRaw('s.place_of_supply as pos, SUM(ri.taxable_value) as taxable, SUM(ri.igst) as igst')->get()]] as [$sign, $list]) {
            foreach ($list as $r) {
                $byPos[$r->pos] ??= ['pos' => $r->pos.'-'.IndianStates::name($r->pos), 'taxable' => 0.0, 'igst' => 0.0];
                $byPos[$r->pos]['taxable'] = round($byPos[$r->pos]['taxable'] + $sign * (float) $r->taxable, 2);
                $byPos[$r->pos]['igst'] = round($byPos[$r->pos]['igst'] + $sign * (float) $r->igst, 2);
            }
        }
        ksort($byPos);

        // 4 — ITC: bills from GST-registered suppliers received in the period, less debit notes
        $registered = fn ($q) => $q->join('contacts as c', 'c.id', '=', 'x.supplier_id')->whereNotNull('c.gstin')->where('c.gstin', '!=', '');
        $itc = $registered(DB::table('purchases as x'))->whereIn('x.branch_id', $f->branchIds)->whereBetween('x.received_date', $f->dateRange())
            ->selectRaw('SUM(x.taxable_total) as taxable, SUM(x.igst_total) as igst, SUM(x.cgst_total) as cgst, SUM(x.sgst_total) as sgst, SUM(x.cess_total) as cess')->first();
        $reversed = $registered(DB::table('purchase_returns as x'))->whereIn('x.branch_id', $f->branchIds)->whereBetween('x.date', $f->dateRange())
            ->selectRaw('SUM(x.taxable_total) as taxable, SUM(x.igst_total) as igst, SUM(x.cgst_total) as cgst, SUM(x.sgst_total) as sgst, SUM(x.cess_total) as cess')->first();
        $row = fn (string $label, $src, int $sign = 1) => ['label' => $label, ...collect(self::HEADS)->mapWithKeys(fn ($h) => [$h => round($sign * (float) (is_array($src) ? $src[$h] : $src->{$h}), 2)])->all()];
        $itcRow = $row('(A)(5) All other ITC — purchases from registered suppliers', $itc);
        $revRow = $row('(B)(2) Less: debit notes (purchase returns)', $reversed, -1);
        $netItc = ['label' => '(C) Net ITC available', ...collect(self::HEADS)->mapWithKeys(fn ($h) => [$h => round($itcRow[$h] + $revRow[$h], 2)])->all()];

        $heads = fn (bool $taxable = true) => array_values(array_filter([
            $taxable ? Section::col('taxable', 'Taxable value', 'money') : null,
            Section::col('igst', 'IGST', 'money'), Section::col('cgst', 'CGST', 'money'), Section::col('sgst', 'SGST', 'money'), Section::col('cess', 'Cess', 'money'),
        ]));
        $c = fn (...$a) => Section::col(...$a);

        $payable = ['label' => 'Output tax − net ITC'];
        foreach (['igst', 'cgst', 'sgst', 'cess'] as $h) {
            $payable[$h] = round($taxed[$h] - $netItc[$h], 2);
        }

        return [
            Section::make('3.1 · Outward supplies (net of credit notes)', [$c('label', 'Nature of supplies'), ...$heads()], [
                ['label' => '(a) Outward taxable supplies (other than zero rated, nil rated and exempted)', ...$taxed],
                ['label' => '(c) Other outward supplies (nil rated, exempted)', ...$nil],
            ])->withoutTotals(),
            Section::make('3.2 · Inter-state supplies to unregistered persons', [$c('pos', 'Place of supply'), $c('taxable', 'Taxable value', 'money', true), $c('igst', 'IGST', 'money', true)], array_values($byPos)),
            Section::make('4 · Eligible ITC', [$c('label', 'Details'), ...$heads()], [$itcRow, $revRow, $netItc])->withoutTotals()
                ->note('ITC only from bills of suppliers with a GSTIN, taken in the month goods were received. Check against GSTR-2B before claiming.'),
            Section::make('Indicative tax payable', [$c('label', ''), ...$heads(false)], [$payable])->withoutTotals()
                ->note('Negative = credit carried forward. Indicative only — when filing, ITC is set off in the order the law prescribes (IGST first).'),
        ];
    }
}
