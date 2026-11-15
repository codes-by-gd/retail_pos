<?php

namespace App\Modules\Pos\Services;

use App\Models\User;
use App\Modules\Contacts\Models\ContactPayment;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Notifications\Services\Alerts;
use App\Modules\Pos\Models\CashMovement;
use App\Modules\Pos\Models\Register;
use App\Modules\Pos\Models\RegisterSession;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SalePayment;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class RegisterService
{
    /** Open a shift, or return the shift that is already open on this register. */
    public function open(Register $register, User $user, string|float|int $openingCash): RegisterSession
    {
        return DB::transaction(function () use ($register, $user, $openingCash) {
            $register = Register::whereKey($register->id)->lockForUpdate()->firstOrFail();
            if (! $register->is_active) {
                throw new \DomainException("{$register->name} is inactive.");
            }

            if ($open = $register->openSession()->first()) {
                return $open;
            }

            return RegisterSession::create([
                'register_id' => $register->id,
                'branch_id' => $register->branch_id,
                'opened_by' => $user->id,
                'opened_at' => now(),
                'opening_cash' => $openingCash,
                'status' => RegisterSession::OPEN,
            ]);
        });
    }

    public function cashMovement(RegisterSession $session, string $type, string|float $amount, string $reason, User $user): CashMovement
    {
        $this->ensureOpen($session);

        if ($type === 'out' && BigDecimal::of((string) $amount)->isGreaterThan($this->summary($session)['expected_cash'])) {
            throw new \DomainException('Not enough cash in the drawer for this.');
        }

        $movement = $session->cashMovements()->create(['type' => $type, 'amount' => $amount, 'reason' => $reason, 'user_id' => $user->id]);
        activity()->causedBy($user)->performedOn($session)->withProperties(['type' => $type, 'amount' => (string) $amount, 'reason' => $reason])
            ->log($type === 'in' ? 'Cash put into drawer' : 'Cash taken from drawer');

        return $movement;
    }

    /**
     * Shift summary / Z-report figures.
     *
     * @return array<string, mixed> amounts as strings with 2 decimals
     */
    public function summary(RegisterSession $session): array
    {
        $sales = Sale::where('register_session_id', $session->id)->where('status', Sale::COMPLETED);

        $byMethod = SalePayment::query()
            ->whereIn('sale_id', (clone $sales)->select('id'))
            ->selectRaw('method, COALESCE(SUM(amount), 0) as total, COUNT(*) as count')
            ->groupBy('method')->get()->keyBy('method');

        $refunds = $session->returns()->selectRaw('refund_method, COALESCE(SUM(total), 0) as total, COUNT(*) as count')
            ->groupBy('refund_method')->get()->keyBy('refund_method');

        $d = fn ($v) => BigDecimal::of((string) ($v ?? 0))->toScale(2);
        $cashIn = $d($session->cashMovements()->where('type', 'in')->sum('amount'));
        $cashOut = $d($session->cashMovements()->where('type', 'out')->sum('amount'));
        $change = $d((clone $sales)->sum('change_given'));
        $cashSales = $d($byMethod->get(SalePayment::CASH)?->total);
        $cashRefunds = $d($refunds->get(SalePayment::CASH)?->total);

        // Cash received from customers / paid to suppliers through this drawer
        $payIn = $d(ContactPayment::where('register_session_id', $session->id)->where('direction', 'in')->sum('amount'));
        $payOut = $d(ContactPayment::where('register_session_id', $session->id)->where('direction', 'out')->sum('amount'));
        $expenses = $d(Expense::where('register_session_id', $session->id)->sum('amount'));

        $expected = $d($session->opening_cash)->plus($cashSales)->minus($change)->minus($cashRefunds)->plus($cashIn)->minus($cashOut)
            ->plus($payIn)->minus($payOut)->minus($expenses);

        return [
            'sales_count' => (clone $sales)->count(),
            'gross_sales' => (string) $d((clone $sales)->sum('grand_total')),
            'discounts' => (string) $d((clone $sales)->sum(DB::raw('line_discount_total + bill_discount_total'))),
            'tax' => (string) $d((clone $sales)->sum(DB::raw('cgst_total + sgst_total + igst_total + cess_total'))),
            'payments' => collect(SalePayment::LABELS)->map(fn ($label, $m) => [
                'method' => $m, 'label' => $label, 'count' => (int) ($byMethod->get($m)?->count ?? 0), 'total' => (string) $d($byMethod->get($m)?->total),
            ])->values()->all(),
            'change_given' => (string) $change,
            'returns_count' => (int) $refunds->sum('count'),
            'returns_total' => (string) $d($refunds->sum('total')),
            'refunds' => $refunds->map(fn ($r) => ['method' => $r->refund_method, 'count' => (int) $r->count, 'total' => (string) $d($r->total)])->values()->all(),
            'opening_cash' => (string) $d($session->opening_cash),
            'cash_sales' => (string) $cashSales,
            'cash_refunds' => (string) $cashRefunds,
            'cash_in' => (string) $cashIn,
            'cash_out' => (string) $cashOut,
            'payments_in' => (string) $payIn,
            'payments_out' => (string) $payOut,
            'expenses' => (string) $expenses,
            'expected_cash' => (string) $expected,
        ];
    }

    public function close(RegisterSession $session, string|float $countedCash, ?string $note, User $user): RegisterSession
    {
        return DB::transaction(function () use ($session, $countedCash, $note, $user) {
            $session = RegisterSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $this->ensureOpen($session);

            $expected = BigDecimal::of($this->summary($session)['expected_cash']);
            $counted = BigDecimal::of((string) $countedCash)->toScale(2);

            $session->update([
                'status' => RegisterSession::CLOSED,
                'closed_by' => $user->id,
                'closed_at' => now(),
                'expected_cash' => (string) $expected,
                'counted_cash' => (string) $counted,
                'difference' => (string) $counted->minus($expected),
                'closing_note' => $note,
            ]);

            activity()->causedBy($user)->performedOn($session)
                ->withProperties(['expected' => (string) $expected, 'counted' => (string) $counted, 'difference' => $session->difference])
                ->log('Closed register shift');
            app(Alerts::class)->cashDifference($session, $user);

            return $session;
        });
    }

    public function ensureOpen(RegisterSession $session): void
    {
        if (! $session->isOpen()) {
            throw new \DomainException('This register shift is already closed. Open the register again.');
        }
    }
}
