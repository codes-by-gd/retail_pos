<?php

namespace App\Modules\Reports\Services;

use App\Models\User;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Pos\Models\RegisterSession;
use App\Modules\Pos\Services\RegisterService;
use App\Modules\Reports\Reports\LowStockReport;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\Sql;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SalePayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Figures for the dashboard. Sales numbers are bill totals (incl. GST & round-off) minus credit notes — the same as the Sales summary report. */
class DashboardService
{
    public const PERIODS = ['today' => 'Today', 'yesterday' => 'Yesterday', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'month' => 'This month'];

    public function __construct(private SalesFacts $facts, private RegisterService $registers) {}

    /** @return array{0: Carbon, 1: Carbon} */
    public function range(string $period): array
    {
        return match ($period) {
            'yesterday' => [today()->subDay(), today()->subDay()],
            '7d' => [today()->subDays(6), today()],
            '30d' => [today()->subDays(29), today()],
            'month' => [today()->startOfMonth(), today()],
            default => [today(), today()],
        };
    }

    public function build(User $user, string $period, array $branchIds, string $branchLabel): array
    {
        [$from, $to] = $this->range($period);
        $days = (int) round($from->diffInDays($to)) + 1;
        $f = new ReportFilters($from, $to, $branchIds, $branchLabel);
        $prev = new ReportFilters($from->copy()->subDays($days), $from->copy()->subDay(), $branchIds, $branchLabel);

        $now = $this->headline($f);
        $before = $this->headline($prev);
        $lines = $this->facts->totals($f);
        $prevLines = $this->facts->totals($prev);
        $financial = $user->can('reports.financial');

        $change = fn ($a, $b) => $b != 0 ? round(($a - $b) / abs($b) * 100, 1) : null;

        $data = [
            'kpis' => [
                'net_sales' => ['value' => $now['net'], 'change' => $change($now['net'], $before['net'])],
                'bills' => ['value' => $now['bills'], 'change' => $change($now['bills'], $before['bills'])],
                'average' => ['value' => $now['average'], 'change' => $change($now['average'], $before['average'])],
                'pieces' => ['value' => $lines['net_qty'], 'change' => $change($lines['net_qty'], $prevLines['net_qty'])],
                'returns' => ['value' => $now['returns'], 'count' => $now['return_count']],
                'profit' => $financial ? ['value' => $lines['profit'], 'margin' => $lines['margin'], 'change' => $change($lines['profit'], $prevLines['profit']),
                    'expenses' => round((float) DB::table('expenses')->whereIn('branch_id', $branchIds)->whereBetween('date', $f->dateRange())->sum(DB::raw('amount - gst_amount')), 2)] : null,
            ],
            'previousLabel' => $days === 1 ? ($period === 'today' ? 'yesterday' : 'the day before') : "previous {$days} days",
            'trend' => $this->trend($f, $days === 1),
            'paymentModes' => $this->paymentModes($f),
        ];

        return $data;
    }

    /** Secondary panels — loaded right after the page (deferred) so the KPIs show instantly. */
    public function details(User $user, string $period, array $branchIds, string $branchLabel): array
    {
        [$from, $to] = $this->range($period);
        $f = new ReportFilters($from, $to, $branchIds, $branchLabel);
        $data = [
            'topProducts' => $this->facts->byDimension($f, 'product')->take(5)->map(fn ($r) => ['label' => $r['label'], 'sub' => $r['sub'], 'qty' => $r['net_qty'], 'value' => $r['net_total']])->values(),
            'topCategories' => $this->facts->byDimension($f, 'category')->take(5)->map(fn ($r) => ['label' => $r['label'], 'qty' => $r['net_qty'], 'value' => $r['net_total']])->values(),
        ];

        if ($user->can('sales.view')) {
            $data['recentSales'] = Sale::query()->whereIn('branch_id', $branchIds)->latest('sold_at')->limit(6)
                ->get(['id', 'invoice_no', 'customer_name', 'grand_total', 'sold_at', 'status', 'payment_status'])
                ->map(fn ($s) => $s->only(['id', 'invoice_no', 'customer_name', 'grand_total', 'sold_at', 'status', 'payment_status']));
        }
        if ($user->can('products.view')) {
            $low = (new LowStockReport)->build($f)[0]->rows;
            $data['lowStock'] = ['count' => count($low), 'items' => array_slice($low, 0, 6)];
        }
        if ($user->can('customers.view')) {
            $owing = Contact::query()->whereIn('type', [Contact::CUSTOMER, Contact::BOTH])->select('contacts.id', 'contacts.name', 'contacts.company_name', 'contacts.type')
                ->withBalance()->get()->filter(fn ($c) => (float) $c->balance > 0.004)->sortByDesc(fn ($c) => (float) $c->balance);
            $data['receivables'] = ['total' => round($owing->sum(fn ($c) => (float) $c->balance), 2), 'count' => $owing->count(),
                'top' => $owing->take(5)->map(fn ($c) => ['id' => $c->id, 'name' => $c->company_name ?: $c->name, 'type' => $c->type, 'balance' => round((float) $c->balance, 2)])->values()];
        }
        if ($user->can('suppliers.view')) {
            $owed = Contact::query()->whereIn('type', [Contact::SUPPLIER, Contact::BOTH])->select('contacts.id')->withBalance()->get()
                ->filter(fn ($c) => (float) $c->balance < -0.004);
            $data['payables'] = ['total' => round(-$owed->sum(fn ($c) => (float) $c->balance), 2), 'count' => $owed->count()];
        }
        if ($user->can('pos.register.manage')) {
            $data['drawers'] = RegisterSession::with('register:id,name', 'opener:id,name', 'branch:id,name')->whereIn('branch_id', $branchIds)->where('status', RegisterSession::OPEN)->get()
                ->map(fn ($s) => ['id' => $s->id, 'branch' => $s->branch->name, 'register' => $s->register->name, 'opened_by' => $s->opener->name, 'opened_at' => $s->opened_at,
                    'expected' => (float) $this->registers->summary($s)['expected_cash']])->values();
        }

        return $data;
    }

    /** Bill totals and credit notes for a period. */
    private function headline(ReportFilters $f): array
    {
        $s = DB::table('sales')->where('status', Sale::COMPLETED)->whereIn('branch_id', $f->branchIds)->whereBetween('sold_at', [$f->start(), $f->end()])
            ->selectRaw('COUNT(*) as bills, COALESCE(SUM(grand_total), 0) as total')->first();
        $r = DB::table('sale_returns')->whereIn('branch_id', $f->branchIds)->whereBetween('returned_at', [$f->start(), $f->end()])
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(total), 0) as total')->first();
        $total = (float) $s->total;

        return ['bills' => (int) $s->bills, 'total' => round($total, 2), 'returns' => round((float) $r->total, 2), 'return_count' => (int) $r->n,
            'net' => round($total - (float) $r->total, 2), 'average' => $s->bills ? round($total / $s->bills, 2) : 0.0];
    }

    /** Net sales per hour (one day) or per day, with empty slots filled in. */
    private function trend(ReportFilters $f, bool $hourly): array
    {
        $expr = fn (string $col) => $hourly ? Sql::hour($col) : Sql::date($col);
        $sales = DB::table('sales')->where('status', Sale::COMPLETED)->whereIn('branch_id', $f->branchIds)->whereBetween('sold_at', [$f->start(), $f->end()])
            ->groupByRaw($expr('sold_at'))->selectRaw($expr('sold_at').' as k, SUM(grand_total) as total, COUNT(*) as bills')->get()->keyBy('k');
        $returns = DB::table('sale_returns')->whereIn('branch_id', $f->branchIds)->whereBetween('returned_at', [$f->start(), $f->end()])
            ->groupByRaw($expr('returned_at'))->selectRaw($expr('returned_at').' as k, SUM(total) as total')->pluck('total', 'k');

        if ($hourly) {
            $keys = $sales->keys()->merge($returns->keys())->map(fn ($k) => (int) $k);
            $first = min($keys->min() ?? 10, 10);
            $last = max($keys->max() ?? 20, 20);
            $slots = collect(range($first, $last))->map(fn ($h) => ['key' => $h, 'label' => sprintf('%02d:00', $h)]);
        } else {
            $slots = collect();
            for ($d = $f->from->copy(); $d->lte($f->to); $d->addDay()) {
                $slots->push(['key' => $d->toDateString(), 'label' => $d->format('d M'), 'title' => $d->format('D, d M Y')]);
            }
        }

        return $slots->map(fn ($s) => [...$s,
            'value' => round((float) ($sales[$s['key']]->total ?? 0) - (float) ($returns[$s['key']] ?? 0), 2),
            'bills' => (int) ($sales[$s['key']]->bills ?? 0),
        ])->values()->all();
    }

    private function paymentModes(ReportFilters $f): array
    {
        $rows = DB::table('sale_payments as sp')->join('sales as s', 's.id', '=', 'sp.sale_id')
            ->where('s.status', Sale::COMPLETED)->whereIn('s.branch_id', $f->branchIds)->whereBetween('s.sold_at', [$f->start(), $f->end()])
            ->groupBy('sp.method')->selectRaw('sp.method as m, SUM(sp.amount) as amount')->pluck('amount', 'm');
        $change = (float) DB::table('sales')->where('status', Sale::COMPLETED)->whereIn('branch_id', $f->branchIds)
            ->whereBetween('sold_at', [$f->start(), $f->end()])->sum('change_given');

        $list = collect(SalePayment::LABELS)->map(fn ($label, $m) => ['method' => $m, 'label' => $label,
            'value' => round((float) ($rows[$m] ?? 0) - ($m === SalePayment::CASH ? $change : 0), 2)])->filter(fn ($r) => $r['value'] > 0)->values();
        $total = $list->sum('value');

        return $list->map(fn ($r) => [...$r, 'share' => $total ? round($r['value'] / $total * 100, 1) : 0])->sortByDesc('value')->values()->all();
    }
}
