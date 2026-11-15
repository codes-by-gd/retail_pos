<?php

namespace App\Modules\Reports\Reports;

use App\Modules\Contacts\Models\ContactPayment;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\Section;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SalePayment;
use Illuminate\Support\Facades\DB;

/** Money in and out per payment mode: at the counter, received later, refunds, paid to suppliers. */
class PaymentModesReport extends Report
{
    public function key(): string
    {
        return 'payment-modes';
    }

    public function title(): string
    {
        return 'Collections by payment mode';
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
        return 'Cash, UPI, card… collected at billing and later, refunds and supplier payments.';
    }

    public function build(ReportFilters $f): array
    {
        $atSale = DB::table('sale_payments as sp')->join('sales as s', 's.id', '=', 'sp.sale_id')
            ->where('s.status', Sale::COMPLETED)->whereIn('s.branch_id', $f->branchIds)->whereBetween('s.sold_at', [$f->start(), $f->end()])
            ->selectRaw('sp.method as m, COUNT(*) as n, SUM(sp.amount) as amount')->groupBy('sp.method')->get()->keyBy('m');
        $change = (float) DB::table('sales')->where('status', Sale::COMPLETED)->whereIn('branch_id', $f->branchIds)
            ->whereBetween('sold_at', [$f->start(), $f->end()])->sum('change_given');

        $refunds = DB::table('sale_returns')->whereIn('branch_id', $f->branchIds)->whereBetween('returned_at', [$f->start(), $f->end()])
            ->selectRaw('refund_method as m, SUM(total) as amount')->groupBy('refund_method')->pluck('amount', 'm');

        $contact = DB::table('contact_payments')->whereIn('branch_id', $f->branchIds)->whereBetween('date', $f->dateRange())
            ->selectRaw('method as m, direction as d, SUM(amount) as amount')->groupBy('method', 'direction')->get();
        $in = $contact->where('d', ContactPayment::IN)->pluck('amount', 'm');
        $out = $contact->where('d', ContactPayment::OUT)->pluck('amount', 'm');

        $labels = SalePayment::LABELS + ContactPayment::METHODS;
        $rows = [];
        foreach ($labels as $m => $label) {
            $sale = (float) ($atSale[$m]->amount ?? 0) - ($m === SalePayment::CASH ? $change : 0);
            $row = [
                'mode' => $label,
                'bills' => (int) ($atSale[$m]->n ?? 0),
                'at_sale' => round($sale, 2),
                'received' => round((float) ($in[$m] ?? 0), 2),
                'refunds' => round((float) ($refunds[$m] ?? 0), 2),
                'paid_out' => round((float) ($out[$m] ?? 0), 2),
            ];
            $row['net'] = round($row['at_sale'] + $row['received'] - $row['refunds'] - $row['paid_out'], 2);
            if ($row['bills'] || $row['at_sale'] || $row['received'] || $row['refunds'] || $row['paid_out']) {
                $rows[] = $row;
            }
        }

        $c = fn (...$a) => Section::col(...$a);

        return [Section::make('By payment mode', [
            $c('mode', 'Mode'),
            $c('bills', 'Payments', 'int', true),
            $c('at_sale', 'At billing', 'money', true),
            $c('received', 'Received later', 'money', true),
            $c('refunds', 'Refunds', 'money', true),
            $c('paid_out', 'Paid to suppliers', 'money', true),
            $c('net', 'Net', 'money', true),
        ], $rows)->note('Cash at billing is net of change given. "On account" is credit given (collected later as a receipt); "Exchange credit" is a returned item used as payment — neither is money received.')];
    }
}
