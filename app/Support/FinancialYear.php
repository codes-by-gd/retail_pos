<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** Indian financial year helpers, using settings('business.financial_year_start') (MM-DD, default 04-01). */
final class FinancialYear
{
    /** Start date of the financial year that contains $date. */
    public static function start(?CarbonInterface $date = null): Carbon
    {
        $date = Carbon::instance($date ?? now());
        [$m, $d] = array_map('intval', explode('-', settings('business.financial_year_start', '04-01')));
        $start = Carbon::create($date->year, $m, $d)->startOfDay();

        return $date->lt($start) ? $start->subYear() : $start;
    }

    /** "25-26" for FY 2025-26 (or "25" when the FY is the calendar year). */
    public static function label(?CarbonInterface $date = null): string
    {
        $start = self::start($date);
        $end = $start->copy()->addYear()->subDay();

        return $start->year === $end->year
            ? $start->format('y')
            : $start->format('y').'-'.$end->format('y');
    }
}
