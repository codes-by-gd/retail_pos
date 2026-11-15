<?php

namespace App\Modules\Reports\Reports;

use App\Modules\Expenses\Models\Expense;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\Section;
use App\Modules\Reports\Support\Sql;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Shop running costs per category, day, month or as a list. */
class ExpensesReport extends Report
{
    private const BY = ['category' => 'Category', 'month' => 'Month', 'day' => 'Day', 'list' => 'Each expense'];

    public function key(): string
    {
        return 'expenses';
    }

    public function title(): string
    {
        return 'Expenses';
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
        return 'Rent, salaries, electricity… per category, day or month, with GST paid.';
    }

    public function options(): array
    {
        return ['by' => ['label' => 'Show', 'options' => self::BY, 'default' => 'category']];
    }

    public function chart(ReportFilters $f): ?array
    {
        return in_array($f->option('by'), ['day', 'month'], true) ? ['x' => 'name', 'y' => 'amount', 'label' => 'Expenses'] : null;
    }

    public function build(ReportFilters $f): array
    {
        $by = array_key_exists($f->option('by'), self::BY) ? $f->option('by') : 'category';
        $q = DB::table('expenses as e')->join('expense_categories as ec', 'ec.id', '=', 'e.expense_category_id')
            ->join('branches as b', 'b.id', '=', 'e.branch_id')
            ->whereIn('e.branch_id', $f->branchIds)->whereBetween('e.date', $f->dateRange());
        $c = fn (...$a) => Section::col(...$a);
        $m = fn ($v) => round((float) $v, 2);

        if ($by === 'list') {
            $rows = $q->leftJoin('users as u', 'u.id', '=', 'e.user_id')->orderBy('e.date')->orderBy('e.id')
                ->get(['e.*', 'ec.name as category', 'b.name as branch', 'u.name as user'])
                ->map(fn ($e) => ['date' => $e->date, 'reference_no' => $e->reference_no, 'category' => $e->category, 'paid_to' => $e->paid_to,
                    'method' => Expense::METHODS[$e->method] ?? $e->method, 'reference' => $e->reference, 'branch' => $e->branch,
                    'amount' => $m($e->amount), 'gst' => $m($e->gst_amount), 'note' => $e->note, 'user' => $e->user]);

            return [Section::make('Expenses', array_values(array_filter([
                $c('date', 'Date', 'date'), $c('reference_no', 'Ref.'), $c('category', 'Category'), $c('paid_to', 'Paid to'), $c('method', 'Mode'),
                $c('reference', 'Bill / UTR'), count($f->branchIds) > 1 ? $c('branch', 'Branch') : null,
                $c('amount', 'Amount', 'money', true), $c('gst', 'GST incl.', 'money', true), $c('note', 'Note'), $c('user', 'Entered by'),
            ])), $rows)];
        }

        $key = match ($by) {
            'day' => Sql::date('e.date'), 'month' => Sql::month('e.date'), default => 'ec.name'
        };
        $rows = $q->groupByRaw($key)->selectRaw("{$key} as k, COUNT(*) as n, SUM(e.amount) as amount, SUM(e.gst_amount) as gst")
            ->get()->map(fn ($r) => [
                'name' => match ($by) {
                    'day' => Carbon::parse($r->k)->format('d M Y'),
                    'month' => Carbon::createFromFormat('Y-m-d', $r->k.'-01')->format('M Y'),
                    default => $r->k,
                },
                'sort' => $r->k, 'count' => (int) $r->n, 'amount' => $m($r->amount), 'gst' => $m($r->gst), 'net' => $m($r->amount - $r->gst),
            ]);
        $rows = $by === 'category' ? $rows->sortByDesc('amount')->values() : $rows->sortBy('sort')->values();
        $total = $rows->sum('amount');
        $rows = $rows->map(fn ($r) => [...$r, 'share' => $total ? round($r['amount'] / $total * 100, 1) : null]);

        return [Section::make('Expenses by '.strtolower(self::BY[$by]), [
            $c('name', self::BY[$by]), $c('count', 'Entries', 'int', true), $c('amount', 'Amount', 'money', true),
            $c('gst', 'GST incl.', 'money', true), $c('net', 'Excl. GST', 'money', true), $c('share', 'Share %', 'percent', true),
        ], $rows)->totalsUsing(fn ($t) => [...$t, 'share' => $t['amount'] ? 100.0 : null])];
    }
}
