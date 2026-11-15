<?php

namespace App\Modules\Reports\Reports;

use App\Modules\Loyalty\Models\LoyaltyTransaction as T;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\Section;
use Illuminate\Support\Facades\DB;

/** Points per customer in the period and the outstanding balance (what the shop "owes" in points). */
class LoyaltyReport extends Report
{
    public function key(): string
    {
        return 'loyalty';
    }

    public function title(): string
    {
        return 'Loyalty points';
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
        return 'Points earned, used and expired per customer, and the value of points not yet used.';
    }

    public function scope(): ?string
    {
        return null;   // points belong to the customer, not a branch
    }

    public function build(ReportFilters $f): array
    {
        $sum = fn (array $types) => 'SUM(CASE WHEN t.created_at BETWEEN ? AND ? AND t.type IN (\''.implode("','", $types).'\') THEN t.points ELSE 0 END)';
        $b = [$f->start(), $f->end()];
        $value = (float) settings('loyalty.point_value', 1);

        $rows = DB::table('loyalty_transactions as t')->join('contacts as c', 'c.id', '=', 't.contact_id')
            ->groupBy('t.contact_id')
            ->selectRaw('MAX(c.code) as code, MAX(c.name) as name, MAX(c.phone) as phone, '
                .$sum([T::EARN, T::RESTORE]).' as earned, '.$sum([T::REDEEM]).' as redeemed, '.$sum([T::REVERSE]).' as reversed, '
                .$sum([T::EXPIRE]).' as expired, '.$sum([T::ADJUST]).' as adjusted, SUM(t.points) as balance, MAX(t.created_at) as last',
                [...$b, ...$b, ...$b, ...$b, ...$b])
            ->get()
            ->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'phone' => $r->phone,
                'earned' => (int) $r->earned, 'redeemed' => -(int) $r->redeemed, 'reversed' => -(int) $r->reversed, 'expired' => -(int) $r->expired,
                'adjusted' => (int) $r->adjusted, 'balance' => (int) $r->balance, 'value' => round((int) $r->balance * $value, 2), 'last' => $r->last])
            ->filter(fn ($r) => $r['balance'] || $r['earned'] || $r['redeemed'] || $r['expired'] || $r['adjusted'])
            ->sortByDesc('balance')->values();

        $c = fn (...$a) => Section::col(...$a);

        return [Section::make('Points by customer', [
            $c('code', 'Code'), $c('name', 'Customer'), $c('phone', 'Phone'),
            $c('earned', 'Earned', 'int', true), $c('redeemed', 'Used', 'int', true), $c('reversed', 'Taken back (returns)', 'int', true),
            $c('expired', 'Expired', 'int', true), $c('adjusted', 'Adjusted', 'int', true),
            $c('balance', 'Balance now', 'int', true), $c('value', 'Value now', 'money', true), $c('last', 'Last activity', 'date'),
        ], $rows)->note('Earned / used / expired are for the selected period; balance and value are as of now (outstanding points are a liability). Points used are a payment method on the bill.')];
    }
}
