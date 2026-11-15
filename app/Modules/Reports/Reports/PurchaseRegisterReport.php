<?php

namespace App\Modules\Reports\Reports;

use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\Section;
use Illuminate\Support\Facades\DB;

/** Every supplier bill and debit note in the period, or totals per supplier. */
class PurchaseRegisterReport extends Report
{
    public function __construct(private readonly bool $bySupplier = false) {}

    public function key(): string
    {
        return $this->bySupplier ? 'purchases-by-supplier' : 'purchase-register';
    }

    public function title(): string
    {
        return $this->bySupplier ? 'Purchases by supplier' : 'Purchase register';
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
        return $this->bySupplier
            ? 'Bills, purchase value, returns and amount still due per supplier.'
            : 'Supplier bills with GST split, and debit notes, for the period.';
    }

    public function build(ReportFilters $f): array
    {
        $purchases = DB::table('purchases as pu')->join('contacts as c', 'c.id', '=', 'pu.supplier_id')
            ->whereIn('pu.branch_id', $f->branchIds)->whereBetween('pu.received_date', $f->dateRange());
        $returns = DB::table('purchase_returns as pr')->join('contacts as c', 'c.id', '=', 'pr.supplier_id')
            ->join('purchases as pu', 'pu.id', '=', 'pr.purchase_id')
            ->whereIn('pr.branch_id', $f->branchIds)->whereBetween('pr.date', $f->dateRange());
        $c = fn (...$a) => Section::col(...$a);
        $money = fn ($v) => round((float) $v, 2);

        if ($this->bySupplier) {
            $bills = $purchases->groupBy('pu.supplier_id')
                ->selectRaw('pu.supplier_id as id, MAX(c.name) as name, MAX(c.company_name) as company, MAX(c.gstin) as gstin, COUNT(*) as bills,
                    SUM(pu.taxable_total) as taxable, SUM(pu.cgst_total + pu.sgst_total + pu.igst_total + pu.cess_total) as tax, SUM(pu.grand_total) as total, SUM(pu.due_amount) as due')
                ->get()->keyBy('id');
            $ret = $returns->groupBy('pr.supplier_id')->selectRaw('pr.supplier_id as id, MAX(c.name) as name, MAX(c.company_name) as company, MAX(c.gstin) as gstin, SUM(pr.total) as total')
                ->get()->keyBy('id');

            $rows = $bills->keys()->merge($ret->keys())->unique()->map(function ($id) use ($bills, $ret, $money) {
                $b = $bills[$id] ?? null;
                $r = $ret[$id] ?? null;
                $any = $b ?? $r;

                return [
                    'supplier' => $any->company ?: $any->name, 'gstin' => $any->gstin, 'bills' => (int) ($b->bills ?? 0),
                    'taxable' => $money($b->taxable ?? 0), 'tax' => $money($b->tax ?? 0), 'total' => $money($b->total ?? 0),
                    'returns' => $money($r->total ?? 0), 'net' => $money(($b->total ?? 0) - ($r->total ?? 0)), 'due' => $money($b->due ?? 0),
                ];
            })->sortByDesc('net')->values();

            return [Section::make('By supplier', [
                $c('supplier', 'Supplier'), $c('gstin', 'GSTIN'), $c('bills', 'Bills', 'int', true),
                $c('taxable', 'Taxable', 'money', true), $c('tax', 'GST', 'money', true), $c('total', 'Bill total', 'money', true),
                $c('returns', 'Debit notes', 'money', true), $c('net', 'Net purchases', 'money', true), $c('due', 'Still due (these bills)', 'money', true),
            ], $rows)];
        }

        $billRows = $purchases->orderBy('pu.received_date')->orderBy('pu.id')
            ->get(['pu.*', 'c.name as supplier_name', 'c.company_name', 'c.gstin as supplier_gstin'])
            ->map(fn ($p) => [
                'date' => $p->received_date, 'reference_no' => $p->reference_no, 'supplier' => $p->company_name ?: $p->supplier_name,
                'gstin' => $p->supplier_gstin, 'bill_no' => $p->supplier_invoice_no, 'bill_date' => $p->supplier_invoice_date,
                'taxable' => $money($p->taxable_total), 'cgst' => $money($p->cgst_total), 'sgst' => $money($p->sgst_total), 'igst' => $money($p->igst_total),
                'cess' => $money($p->cess_total), 'other' => $money($p->other_charges), 'total' => $money($p->grand_total),
                'paid' => $money($p->paid_amount), 'due' => $money($p->due_amount),
            ]);

        $noteRows = $returns->orderBy('pr.date')->orderBy('pr.id')
            ->get(['pr.*', 'c.name as supplier_name', 'c.company_name', 'c.gstin as supplier_gstin', 'pu.reference_no as purchase_ref', 'pu.supplier_invoice_no'])
            ->map(fn ($r) => [
                'date' => $r->date, 'reference_no' => $r->reference_no, 'supplier' => $r->company_name ?: $r->supplier_name, 'gstin' => $r->supplier_gstin,
                'against' => trim($r->purchase_ref.($r->supplier_invoice_no ? " (bill {$r->supplier_invoice_no})" : '')),
                'taxable' => $money($r->taxable_total), 'cgst' => $money($r->cgst_total), 'sgst' => $money($r->sgst_total), 'igst' => $money($r->igst_total),
                'cess' => $money($r->cess_total), 'total' => $money($r->total),
            ]);

        $tax = [$c('taxable', 'Taxable', 'money', true), $c('cgst', 'CGST', 'money', true), $c('sgst', 'SGST', 'money', true), $c('igst', 'IGST', 'money', true), $c('cess', 'Cess', 'money', true)];

        return [
            Section::make('Supplier bills', [
                $c('date', 'Received', 'date'), $c('reference_no', 'Our ref.'), $c('supplier', 'Supplier'), $c('gstin', 'GSTIN'),
                $c('bill_no', 'Bill no.'), $c('bill_date', 'Bill date', 'date'), ...$tax,
                $c('other', 'Freight / other', 'money', true), $c('total', 'Total', 'money', true), $c('paid', 'Paid', 'money', true), $c('due', 'Due', 'money', true),
            ], $billRows),
            Section::make('Debit notes (returns to suppliers)', [
                $c('date', 'Date', 'date'), $c('reference_no', 'Debit note'), $c('supplier', 'Supplier'), $c('gstin', 'GSTIN'), $c('against', 'Against'),
                ...$tax, $c('total', 'Total', 'money', true),
            ], $noteRows),
        ];
    }
}
