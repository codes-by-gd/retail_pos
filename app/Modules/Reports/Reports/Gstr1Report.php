<?php

namespace App\Modules\Reports\Reports;

use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\Section;
use App\Modules\Sales\Models\Sale;
use App\Support\Gst\IndianStates;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * GSTR-1 data for one GST registration (GSTIN) and month: B2B, B2C large, B2C small, credit notes,
 * HSN summary (split B2B / B2C) and documents issued. Figures come only from the saved bill/credit-note lines.
 */
class Gstr1Report extends Report
{
    /** B2C (large): inter-state invoice to an unregistered buyer above this value. */
    public const B2CL_LIMIT = 100000;

    /** Unit short name → GST Unit Quantity Code. */
    public const UQC = ['pcs' => 'PCS', 'nos' => 'NOS', 'set' => 'SET', 'pair' => 'PRS', 'prs' => 'PRS', 'mtr' => 'MTR', 'm' => 'MTR',
        'dzn' => 'DOZ', 'doz' => 'DOZ', 'pack' => 'PAC', 'pac' => 'PAC', 'box' => 'BOX', 'kg' => 'KGS', 'g' => 'GMS', 'gm' => 'GMS', 'ltr' => 'LTR', 'roll' => 'ROL'];

    public function key(): string
    {
        return 'gstr-1';
    }

    public function title(): string
    {
        return 'GSTR-1 (outward supplies)';
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
        return 'B2B, B2C, credit notes, HSN summary and documents issued — ready to enter in the GST portal.';
    }

    public function scope(): ?string
    {
        return 'gstin';
    }

    public function defaultRange(): string
    {
        return 'last_month';
    }

    /** Applies the GSTR-1 table a bill belongs to (alias s = sales). */
    public static function whereKind(Builder $q, string $kind): Builder
    {
        $registered = fn ($w) => $w->whereNotNull('s.customer_gstin')->where('s.customer_gstin', '!=', '');
        $unregistered = fn ($w) => $w->where(fn ($x) => $x->whereNull('s.customer_gstin')->orWhere('s.customer_gstin', ''));
        $large = fn ($w) => $w->where('s.is_inter_state', true)->where('s.grand_total', '>', self::B2CL_LIMIT);

        return match ($kind) {
            'b2b' => $q->where($registered),
            'b2cl' => $q->where($unregistered)->where($large),
            'b2cs' => $q->where($unregistered)->whereNot($large),
        };
    }

    public function build(ReportFilters $f): array
    {
        $lines = fn () => DB::table('sale_items as si')->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.status', Sale::COMPLETED)->whereIn('s.branch_id', $f->branchIds)->whereBetween('s.sold_at', [$f->start(), $f->end()]);
        $returnLines = fn () => DB::table('sale_return_items as ri')->join('sale_returns as r', 'r.id', '=', 'ri.sale_return_id')
            ->join('sale_items as si', 'si.id', '=', 'ri.sale_item_id')->join('sales as s', 's.id', '=', 'r.sale_id')
            ->whereIn('r.branch_id', $f->branchIds)->whereBetween('r.returned_at', [$f->start(), $f->end()]);
        $tax = 'SUM(%1$s.taxable_value) as taxable, SUM(%1$s.igst) as igst, SUM(%1$s.cgst) as cgst, SUM(%1$s.sgst) as sgst, SUM(%1$s.cess) as cess';
        $m = fn ($v) => round((float) $v, 2);
        $pos = fn ($code) => $code.'-'.IndianStates::name($code);
        $taxCols = fn (bool $intra = true) => array_values(array_filter([
            Section::col('rate', 'Rate %', 'percent'),
            Section::col('taxable', 'Taxable value', 'money', true),
            Section::col('igst', 'IGST', 'money', true),
            $intra ? Section::col('cgst', 'CGST', 'money', true) : null,
            $intra ? Section::col('sgst', 'SGST', 'money', true) : null,
            Section::col('cess', 'Cess', 'money', true),
        ]));
        $taxVals = fn ($r) => ['rate' => (float) $r->rate, 'taxable' => $m($r->taxable), 'igst' => $m($r->igst), 'cgst' => $m($r->cgst), 'sgst' => $m($r->sgst), 'cess' => $m($r->cess)];
        $c = fn (...$a) => Section::col(...$a);

        // 4A — B2B invoices, one row per invoice and rate
        $b2b = self::whereKind($lines(), 'b2b')->groupBy('s.id', 'si.gst_rate')
            ->selectRaw('s.id, MAX(s.customer_gstin) as gstin, MAX(s.customer_name) as name, MAX(s.invoice_no) as no, MAX(s.sold_at) as at,
                MAX(s.grand_total) as value, MAX(s.place_of_supply) as pos, si.gst_rate as rate, '.sprintf($tax, 'si'))
            ->orderByRaw('MAX(s.sold_at)')->get()
            ->map(fn ($r) => ['gstin' => $r->gstin, 'name' => $r->name, 'invoice_no' => $r->no, 'date' => substr($r->at, 0, 10), 'value' => $m($r->value),
                'pos' => $pos($r->pos), 'reverse_charge' => 'N', ...$taxVals($r)]);

        // 5 — B2C (large)
        $b2cl = self::whereKind($lines(), 'b2cl')->groupBy('s.id', 'si.gst_rate')
            ->selectRaw('s.id, MAX(s.invoice_no) as no, MAX(s.sold_at) as at, MAX(s.grand_total) as value, MAX(s.place_of_supply) as pos,
                si.gst_rate as rate, '.sprintf($tax, 'si'))
            ->orderByRaw('MAX(s.sold_at)')->get()
            ->map(fn ($r) => ['invoice_no' => $r->no, 'date' => substr($r->at, 0, 10), 'value' => $m($r->value), 'pos' => $pos($r->pos), ...$taxVals($r)]);

        // 7 — B2C (small): net of credit notes against those bills, per place of supply and rate
        $b2csGroup = fn (Builder $q, string $alias) => $q->groupBy('s.place_of_supply', 's.is_inter_state', 'si.gst_rate')
            ->selectRaw('s.place_of_supply as pos, s.is_inter_state as inter, si.gst_rate as rate, '.sprintf($tax, $alias))->get();
        $b2cs = [];
        foreach ([[1, $b2csGroup(self::whereKind($lines(), 'b2cs'), 'si')], [-1, $b2csGroup(self::whereKind($returnLines(), 'b2cs'), 'ri')]] as [$sign, $list]) {
            foreach ($list as $r) {
                $k = "{$r->pos}|{$r->inter}|{$r->rate}";
                $b2cs[$k] ??= ['pos' => $pos($r->pos), 'supply' => $r->inter ? 'Inter-state' : 'Intra-state', 'rate' => (float) $r->rate,
                    'taxable' => 0.0, 'igst' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'cess' => 0.0];
                foreach (['taxable', 'igst', 'cgst', 'sgst', 'cess'] as $h) {
                    $b2cs[$k][$h] = round($b2cs[$k][$h] + $sign * (float) $r->{$h}, 2);
                }
            }
        }
        ksort($b2cs);

        // 9B — credit notes: registered (CDNR) and unregistered B2C large (CDNUR)
        $notes = fn (string $kind) => self::whereKind($returnLines(), $kind)->groupBy('r.id', 'si.gst_rate')
            ->selectRaw('r.id, MAX(r.credit_note_no) as no, MAX(r.returned_at) as at, MAX(r.total) as value, MAX(s.invoice_no) as invoice,
                MAX(s.customer_gstin) as gstin, MAX(s.customer_name) as name, MAX(s.place_of_supply) as pos, si.gst_rate as rate, '.sprintf($tax, 'ri'))
            ->orderByRaw('MAX(r.returned_at)')->get();
        $cdnr = $notes('b2b')->map(fn ($r) => ['gstin' => $r->gstin, 'name' => $r->name, 'note_no' => $r->no, 'date' => substr($r->at, 0, 10),
            'type' => 'C', 'invoice_no' => $r->invoice, 'pos' => $pos($r->pos), 'value' => $m($r->value), ...$taxVals($r)]);
        $cdnur = $notes('b2cl')->map(fn ($r) => ['note_no' => $r->no, 'date' => substr($r->at, 0, 10), 'type' => 'C', 'ur_type' => 'B2CL',
            'invoice_no' => $r->invoice, 'pos' => $pos($r->pos), 'value' => $m($r->value), ...$taxVals($r)]);

        // 12 — HSN summary, B2B and B2C tabs, net of credit notes
        $hsnGroup = fn (Builder $q, string $alias, string $qtyCol) => $q->groupByRaw("CASE WHEN s.customer_gstin IS NOT NULL AND s.customer_gstin != '' THEN 'B2B' ELSE 'B2C' END, si.hsn_code, si.unit, si.gst_rate")
            ->selectRaw("CASE WHEN s.customer_gstin IS NOT NULL AND s.customer_gstin != '' THEN 'B2B' ELSE 'B2C' END as kind, si.hsn_code as hsn, si.unit, si.gst_rate as rate,
                SUM({$qtyCol}) as qty, SUM({$alias}.line_total) as value, ".sprintf($tax, $alias))->get();
        $hsn = [];
        foreach ([[1, $hsnGroup($lines(), 'si', 'si.quantity')], [-1, $hsnGroup($returnLines(), 'ri', 'ri.quantity')]] as [$sign, $list]) {
            foreach ($list as $r) {
                $k = "{$r->kind}|{$r->hsn}|{$r->unit}|{$r->rate}";
                $hsn[$k] ??= ['kind' => $r->kind, 'hsn' => $r->hsn ?: '—', 'uqc' => self::UQC[strtolower((string) $r->unit)] ?? 'OTH', 'rate' => (float) $r->rate,
                    'qty' => 0.0, 'value' => 0.0, 'taxable' => 0.0, 'igst' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'cess' => 0.0];
                $hsn[$k]['qty'] = round($hsn[$k]['qty'] + $sign * (float) $r->qty, 3);
                foreach (['value', 'taxable', 'igst', 'cgst', 'sgst', 'cess'] as $h) {
                    $hsn[$k][$h] = round($hsn[$k][$h] + $sign * (float) $r->{$h}, 2);
                }
            }
        }
        ksort($hsn);
        $missingHsn = collect($hsn)->contains(fn ($r) => $r['hsn'] === '—');

        // 13 — documents issued, per branch series
        $docs = collect();
        foreach (DB::table('branches')->whereIn('id', $f->branchIds)->orderBy('name')->get(['id', 'name']) as $b) {
            $inv = DB::table('sales')->where('branch_id', $b->id)->whereBetween('sold_at', [$f->start(), $f->end()])
                ->selectRaw("MIN(invoice_no) as first, MAX(invoice_no) as last, COUNT(*) as total, SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled")->first();
            $cn = DB::table('sale_returns')->where('branch_id', $b->id)->whereBetween('returned_at', [$f->start(), $f->end()])
                ->selectRaw('MIN(credit_note_no) as first, MAX(credit_note_no) as last, COUNT(*) as total, 0 as cancelled')->first();
            foreach ([['Invoices for outward supply', $inv], ['Credit notes', $cn]] as [$nature, $d]) {
                if ($d->total) {
                    $docs->push(['nature' => $nature, 'branch' => $b->name, 'from' => $d->first, 'to' => $d->last,
                        'total' => (int) $d->total, 'cancelled' => (int) $d->cancelled, 'net' => (int) $d->total - (int) $d->cancelled]);
                }
            }
        }

        return [
            Section::make('4A · B2B invoices', [$c('gstin', 'GSTIN of buyer'), $c('name', 'Buyer'), $c('invoice_no', 'Invoice no.'), $c('date', 'Date', 'date'),
                $c('value', 'Invoice value', 'money'), $c('pos', 'Place of supply'), $c('reverse_charge', 'Reverse charge'), ...$taxCols()], $b2b)
                ->note('One row per invoice and GST rate. Invoice value is repeated on each rate row, as the portal expects.'),
            Section::make('5 · B2C (large) invoices', [$c('invoice_no', 'Invoice no.'), $c('date', 'Date', 'date'), $c('value', 'Invoice value', 'money'),
                $c('pos', 'Place of supply'), ...$taxCols(false)], $b2cl)
                ->note('Inter-state bills above ₹1,00,000 to buyers without a GSTIN.'),
            Section::make('7 · B2C (small) — net of credit notes', [$c('pos', 'Place of supply'), $c('supply', 'Supply'), ...$taxCols()], array_values($b2cs)),
            Section::make('9B · Credit notes — registered (CDNR)', [$c('gstin', 'GSTIN of buyer'), $c('name', 'Buyer'), $c('note_no', 'Note no.'), $c('date', 'Date', 'date'),
                $c('type', 'Type'), $c('invoice_no', 'Against invoice'), $c('pos', 'Place of supply'), $c('value', 'Note value', 'money'), ...$taxCols()], $cdnr),
            Section::make('9B · Credit notes — unregistered (CDNUR)', [$c('note_no', 'Note no.'), $c('date', 'Date', 'date'), $c('type', 'Type'), $c('ur_type', 'UR type'),
                $c('invoice_no', 'Against invoice'), $c('pos', 'Place of supply'), $c('value', 'Note value', 'money'), ...$taxCols(false)], $cdnur)
                ->note('Only credit notes against B2C (large) invoices. Other B2C returns are netted in table 7.'),
            Section::make('12 · HSN summary', [$c('kind', 'Tab'), $c('hsn', 'HSN'), $c('uqc', 'UQC'), $c('rate', 'Rate %', 'percent'), $c('qty', 'Total qty', 'qty', true),
                $c('value', 'Total value', 'money', true), $c('taxable', 'Taxable value', 'money', true), $c('igst', 'IGST', 'money', true),
                $c('cgst', 'CGST', 'money', true), $c('sgst', 'SGST', 'money', true), $c('cess', 'Cess', 'money', true)], array_values($hsn))
                ->note('Net of credit notes. B2B and B2C are reported in separate tabs.'.($missingHsn ? ' Some items have no HSN code — add it on the product.' : '')),
            Section::make('13 · Documents issued', [$c('nature', 'Nature of document'), $c('branch', 'Series (branch)'), $c('from', 'Sr. no. from'), $c('to', 'Sr. no. to'),
                $c('total', 'Total', 'int', true), $c('cancelled', 'Cancelled', 'int', true), $c('net', 'Net issued', 'int', true)], $docs),
        ];
    }
}
