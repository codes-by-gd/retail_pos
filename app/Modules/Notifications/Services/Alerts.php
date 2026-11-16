<?php

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Inventory\Models\ProductVariant;
use App\Modules\Notifications\Notifications\AlertNotification;
use App\Modules\Notifications\NotificationTypes as T;
use App\Modules\Pos\Models\RegisterSession;
use App\Modules\Reports\Services\ReportExporter;
use App\Modules\Sales\Models\Sale;
use App\Modules\Settings\Models\Branch;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** The business events that raise alerts. Thresholds come from settings('alerts.*'). */
class Alerts
{
    public function __construct(private Notifier $notifier) {}

    private static function rs(float|string $v): string
    {
        return '₹'.ReportExporter::inr((float) $v);
    }

    /** Called by StockService when stock falls to/below the reorder level. */
    public function lowStock(ProductVariant $variant, int $branchId, BigDecimal $qty): void
    {
        $variant->loadMissing('product');
        $branch = Branch::find($branchId);
        $name = $variant->fullName();
        $out = ! $qty->isPositive();
        $left = (string) $qty->strippedOfTrailingZeros();

        $this->notifier->send(new AlertNotification(
            T::LOW_STOCK,
            $out ? "Out of stock: {$name}" : "Low stock: {$name}",
            $out ? "{$name} ({$variant->sku}) is out of stock at {$branch?->name}." : "Only {$left} left of {$name} ({$variant->sku}) at {$branch?->name} — reorder level ".BigDecimal::of($variant->reorder_level)->strippedOfTrailingZeros().'.',
            route('stock.index', ['search' => $variant->sku]),
            $out ? 'danger' : 'warning',
            "low_stock:{$variant->id}:{$branchId}",
            $branch?->name,
        ), $branchId);
    }

    public function saleCancelled(Sale $sale, User $by): void
    {
        $this->notifier->send(new AlertNotification(
            T::SALE_CANCELLED,
            "Bill {$sale->invoice_no} cancelled",
            "{$by->name} cancelled {$sale->invoice_no} (".self::rs($sale->grand_total).", {$sale->customer_name}). Reason: {$sale->cancel_reason}",
            route('sales.show', $sale), 'warning', null, $sale->branch?->name,
        ), $sale->branch_id, $by);
    }

    /** After checkout: total discount above the limit (% of the bill before discounts). */
    public function bigDiscount(Sale $sale, User $by): void
    {
        $limit = (float) settings('alerts.discount_percent', 20);
        $discount = (float) $sale->line_discount_total + (float) $sale->bill_discount_total;
        if ($limit <= 0 || (float) $sale->subtotal <= 0 || $discount / (float) $sale->subtotal * 100 < $limit) {
            return;
        }
        $pct = round($discount / (float) $sale->subtotal * 100, 1);

        $this->notifier->send(new AlertNotification(
            T::BIG_DISCOUNT,
            "{$pct}% discount on {$sale->invoice_no}",
            "{$by->name} gave ".self::rs($discount).' off ('.$pct.'%) on a bill of '.self::rs($sale->subtotal)." for {$sale->customer_name}.",
            route('sales.show', $sale), 'warning', null, $sale->branch?->name,
        ), $sale->branch_id, $by);
    }

    public function cashDifference(RegisterSession $session, User $by): void
    {
        $limit = (float) settings('alerts.cash_difference', 100);
        $diff = (float) $session->difference;
        if (abs($diff) < max(0.01, $limit)) {
            return;
        }
        $session->loadMissing('register', 'branch');

        $this->notifier->send(new AlertNotification(
            T::CASH_DIFFERENCE,
            ($diff < 0 ? 'Cash short ' : 'Cash excess ').self::rs(abs($diff))." at {$session->register->name}",
            "{$by->name} closed {$session->register->name} ({$session->branch->name}): expected ".self::rs($session->expected_cash).', counted '.self::rs($session->counted_cash).'.'
                .($session->closing_note ? " Note: {$session->closing_note}" : ''),
            route('register-sessions.show', $session), $diff < 0 ? 'danger' : 'warning', null, $session->branch->name,
        ), $session->branch_id, $by);
    }

    public function backupFailed(string $message): void
    {
        $this->notifier->send(new AlertNotification(T::BACKUP, 'Database backup failed', $message, route('settings.backups.index'), 'danger'));
    }

    /**
     * End-of-day checks (scheduled): sales summary per branch, supplier bills due, old customer dues.
     *
     * @return array<string, int> notifications sent per type
     */
    public function daily(?Carbon $day = null): array
    {
        $day ??= today();
        $sent = [T::DAILY_SUMMARY => 0, T::SUPPLIER_DUES => 0, T::CUSTOMER_DUES => 0];

        foreach (Branch::active()->get() as $branch) {
            $s = DB::table('sales')->where('branch_id', $branch->id)->where('status', Sale::COMPLETED)->whereDate('sold_at', $day)
                ->selectRaw('COUNT(*) as bills, COALESCE(SUM(grand_total), 0) as total')->first();
            $returns = (float) DB::table('sale_returns')->where('branch_id', $branch->id)->whereDate('returned_at', $day)->sum('total');
            $diff = (float) DB::table('register_sessions')->where('branch_id', $branch->id)->whereDate('closed_at', $day)->sum('difference');
            $open = DB::table('register_sessions')->where('branch_id', $branch->id)->where('status', 'open')->count();
            if (! $s->bills && ! $returns) {
                continue;
            }
            $body = "{$s->bills} bills · net sales ".self::rs((float) $s->total - $returns).($returns ? ' (after '.self::rs($returns).' returns)' : '')
                .($diff ? ' · cash '.($diff < 0 ? 'short ' : 'excess ').self::rs(abs($diff)) : '').($open ? " · {$open} register(s) still open" : '');
            $sent[T::DAILY_SUMMARY] += $this->notifier->send(new AlertNotification(
                T::DAILY_SUMMARY, "{$branch->name}: ".$day->format('d M').' summary', $body,
                route('dashboard', ['period' => $day->isToday() ? 'today' : 'yesterday']), 'info', "daily:{$branch->id}:".$day->toDateString(), $branch->name,
            ), $branch->id);
        }

        // Supplier bills overdue or due within 3 days (payment terms)
        $due = DB::table('purchases as p')->join('contacts as c', 'c.id', '=', 'p.supplier_id')->where('p.due_amount', '>', 0)
            ->get(['p.due_amount', 'p.received_date', 'c.payment_terms_days'])
            ->map(fn ($p) => ['due' => (float) $p->due_amount, 'on' => Carbon::parse($p->received_date)->addDays((int) ($p->payment_terms_days ?? 0))])
            ->filter(fn ($p) => $p['on']->lte($day->copy()->addDays(3)));
        if ($due->isNotEmpty()) {
            $overdue = $due->filter(fn ($p) => $p['on']->lt($day));
            $sent[T::SUPPLIER_DUES] += $this->notifier->send(new AlertNotification(
                T::SUPPLIER_DUES, $due->count().' supplier bill(s) to pay',
                ($overdue->isNotEmpty() ? $overdue->count().' overdue ('.self::rs($overdue->sum('due')).'), ' : '').'total '.self::rs($due->sum('due')).' due by '.$day->copy()->addDays(3)->format('d M').'.',
                route('reports.show', 'payables'), $overdue->isNotEmpty() ? 'danger' : 'warning', 'supplier_dues:'.$day->toDateString(),
            ));
        }

        // Customer bills unpaid for more than 30 days
        $old = DB::table('sales')->where('status', Sale::COMPLETED)->where('due_amount', '>', 0)->where('sold_at', '<', $day->copy()->subDays(30))
            ->selectRaw('COUNT(DISTINCT customer_id) as customers, COALESCE(SUM(due_amount), 0) as total')->first();
        if ($old->customers) {
            $sent[T::CUSTOMER_DUES] += $this->notifier->send(new AlertNotification(
                T::CUSTOMER_DUES, "{$old->customers} customer(s) with dues over 30 days",
                self::rs($old->total).' unpaid for more than 30 days. Follow up or record payments.',
                route('reports.show', 'receivables'), 'warning', 'customer_dues:'.$day->toDateString(),
            ));
        }

        return $sent;
    }
}
