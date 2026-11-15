<?php

namespace App\Modules\Contacts\Services;

use App\Models\User;
use App\Modules\Contacts\Enums\LedgerEntryType;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\ContactPayment;
use App\Modules\Pos\Models\RegisterSession;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Sales\Models\Sale;
use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Services\DocumentNumberService;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Payments received from customers (in) and paid to suppliers (out), outside a bill.
 *
 * Each payment posts to the contact ledger and is allocated to unpaid bills — a specific bill first
 * when given (e.g. "pay this purchase now"), then the oldest unpaid bills. What is left stays as
 * an advance / credit on the account (visible in the balance).
 */
class PaymentService
{
    public function __construct(private ContactLedgerService $ledger, private DocumentNumberService $numbers) {}

    /**
     * @param  array{amount: mixed, method: string, reference?: ?string, date?: ?string, note?: ?string}  $data
     *
     * @throws \DomainException
     */
    public function record(Contact $contact, string $direction, array $data, User $user, Branch $branch, ?RegisterSession $session = null, ?Model $first = null): ContactPayment
    {
        if (! array_key_exists($data['method'], ContactPayment::METHODS)) {
            throw new \DomainException('Choose a payment method.');
        }
        if ($direction === ContactPayment::IN && ! $contact->isCustomer()) {
            throw new \DomainException("{$contact->name} is not a customer.");
        }
        if ($direction === ContactPayment::OUT && ! $contact->isSupplier()) {
            throw new \DomainException("{$contact->name} is not a supplier.");
        }
        if ($contact->is_walk_in) {
            throw new \DomainException('Payments need a named customer.');
        }
        // Only cash passes through a register drawer.
        if ($data['method'] !== 'cash') {
            $session = null;
        }
        if ($session && (! $session->isOpen() || $session->branch_id !== $branch->id)) {
            throw new \DomainException('That register shift is closed. Choose an open register or record the cash outside the drawer.');
        }

        $amount = BigDecimal::of((string) $data['amount'])->toScale(2);
        if (! $amount->isPositive()) {
            throw new \DomainException('Enter an amount.');
        }

        return DB::transaction(function () use ($contact, $direction, $data, $user, $branch, $session, $first, $amount) {
            $payment = ContactPayment::create([
                'reference_no' => $this->numbers->next($direction === ContactPayment::IN ? 'RCPT' : 'PAY', $branch),
                'contact_id' => $contact->id,
                'branch_id' => $branch->id,
                'register_session_id' => $session?->id,
                'direction' => $direction,
                'method' => $data['method'],
                'amount' => (string) $amount,
                'unallocated' => (string) $amount,
                'reference' => $data['reference'] ?? null,
                'date' => $data['date'] ?? now()->toDateString(),
                'note' => $data['note'] ?? null,
                'user_id' => $user->id,
            ]);

            $label = ContactPayment::METHODS[$data['method']];
            $direction === ContactPayment::IN
                ? $this->ledger->record($contact, LedgerEntryType::PaymentReceived, credit: (string) $amount, date: $payment->date, reference: $payment,
                    note: "{$label} received · {$payment->reference_no}", branchId: $branch->id)
                : $this->ledger->record($contact, LedgerEntryType::PaymentMade, debit: (string) $amount, date: $payment->date, reference: $payment,
                    note: "{$label} paid · {$payment->reference_no}", branchId: $branch->id);

            $left = $this->allocate($payment, $amount, $first);
            $payment->update(['unallocated' => (string) $left]);

            return $payment->fresh('allocations');
        });
    }

    /** Settle unpaid bills: $first, then oldest first. Returns what could not be allocated. */
    private function allocate(ContactPayment $payment, BigDecimal $amount, ?Model $first): BigDecimal
    {
        $bills = $payment->direction === ContactPayment::IN
            ? Sale::where('customer_id', $payment->contact_id)->where('status', Sale::COMPLETED)->where('due_amount', '>', 0)->orderBy('sold_at')->orderBy('id')
            : Purchase::where('supplier_id', $payment->contact_id)->where('due_amount', '>', 0)->orderBy('received_date')->orderBy('id');

        $bills = $bills->lockForUpdate()->get();
        if ($first) {
            $bills = $bills->sortBy(fn ($b) => $b->is($first) ? 0 : 1)->values();
        }

        $left = $amount;
        foreach ($bills as $bill) {
            if (! $left->isPositive()) {
                break;
            }
            $take = BigDecimal::min($left, BigDecimal::of($bill->due_amount));
            $payment->allocations()->create(['allocatable_type' => $bill->getMorphClass(), 'allocatable_id' => $bill->id, 'amount' => (string) $take]);
            self::reduceDue($bill, $take);
            $left = $left->minus($take);
        }

        return $left;
    }

    /** Lower a sale's / purchase's due amount and refresh its payment status. */
    public static function reduceDue(Model $bill, BigDecimal $by, bool $isPayment = true): void
    {
        $due = BigDecimal::max(BigDecimal::zero(), BigDecimal::of($bill->due_amount)->minus($by))->toScale(2);
        $values = ['due_amount' => (string) $due, 'payment_status' => self::statusFor($due, BigDecimal::of($bill->grand_total))];
        if ($isPayment && $bill instanceof Purchase) {
            $values['paid_amount'] = (string) BigDecimal::of($bill->paid_amount)->plus($by)->toScale(2);
        }
        $bill->update($values);
    }

    public static function statusFor(BigDecimal $due, BigDecimal $total): string
    {
        return match (true) {
            $due->isZero() => 'paid',
            $due->isGreaterThanOrEqualTo($total) => 'due',
            default => 'partial',
        };
    }
}
