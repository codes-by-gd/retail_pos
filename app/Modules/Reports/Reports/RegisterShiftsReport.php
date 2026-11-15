<?php

namespace App\Modules\Reports\Reports;

use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\Section;
use Illuminate\Support\Facades\DB;

/** Every register shift in the period with the cash count result — spots repeated shortages. */
class RegisterShiftsReport extends Report
{
    public function key(): string
    {
        return 'register-shifts';
    }

    public function title(): string
    {
        return 'Register shifts & cash differences';
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
        return 'Opening float, expected and counted cash and the difference for every shift.';
    }

    public function build(ReportFilters $f): array
    {
        $rows = DB::table('register_sessions as rs')
            ->join('registers as rg', 'rg.id', '=', 'rs.register_id')
            ->join('branches as b', 'b.id', '=', 'rs.branch_id')
            ->join('users as o', 'o.id', '=', 'rs.opened_by')
            ->leftJoin('users as c', 'c.id', '=', 'rs.closed_by')
            ->whereIn('rs.branch_id', $f->branchIds)
            ->whereBetween('rs.opened_at', [$f->start(), $f->end()])
            ->orderBy('rs.opened_at')
            ->get(['rs.*', 'rg.name as register', 'b.name as branch', 'o.name as opener', 'c.name as closer'])
            ->map(fn ($s) => [
                'register' => count($f->branchIds) > 1 ? "{$s->branch} · {$s->register}" : $s->register,
                'opened' => $s->opened_at,
                'opener' => $s->opener,
                'closed' => $s->closed_at,
                'closer' => $s->closer ?? ($s->status === 'open' ? 'still open' : null),
                'opening' => round((float) $s->opening_cash, 2),
                'expected' => $s->expected_cash !== null ? round((float) $s->expected_cash, 2) : null,
                'counted' => $s->counted_cash !== null ? round((float) $s->counted_cash, 2) : null,
                'difference' => $s->difference !== null ? round((float) $s->difference, 2) : null,
                'note' => $s->closing_note,
            ]);

        $c = fn (...$a) => Section::col(...$a);

        return [Section::make('Shifts', [
            $c('register', 'Register'),
            $c('opened', 'Opened', 'datetime'),
            $c('opener', 'Opened by'),
            $c('closed', 'Closed', 'datetime'),
            $c('closer', 'Closed by'),
            $c('opening', 'Opening float', 'money'),
            $c('expected', 'Expected cash', 'money', true),
            $c('counted', 'Counted', 'money', true),
            $c('difference', 'Short (−) / excess', 'money', true),
            $c('note', 'Closing note'),
        ], $rows)->note('Difference = counted − expected. Open shifts have no count yet.')];
    }
}
