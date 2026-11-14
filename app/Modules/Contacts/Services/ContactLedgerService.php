<?php

namespace App\Modules\Contacts\Services;

use App\Modules\Contacts\Enums\LedgerEntryType;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\ContactLedgerEntry;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Money owed between the business and a contact.
 * Positive balance = contact owes us (receivable); negative = we owe them (payable).
 * Sales/purchases/payments (Phases 3–4) post here via record().
 */
class ContactLedgerService
{
    public function record(
        Contact $contact,
        LedgerEntryType $type,
        string|int|float $debit = 0,
        string|int|float $credit = 0,
        ?CarbonInterface $date = null,
        ?Model $reference = null,
        ?string $note = null,
        ?int $branchId = null,
    ): ContactLedgerEntry {
        return ContactLedgerEntry::create([
            'contact_id' => $contact->id,
            'branch_id' => $branchId,
            'date' => ($date ?? now())->toDateString(),
            'type' => $type,
            'debit' => (string) BigDecimal::of((string) $debit)->toScale(2),
            'credit' => (string) BigDecimal::of((string) $credit)->toScale(2),
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'note' => $note,
            'user_id' => auth()->id(),
        ]);
    }

    /**
     * Set (or replace) the opening balance. $amount > 0: they owe us; < 0: we owe them; 0 removes it.
     * The opening entry is the only ledger row that may be changed — it is not tied to any document.
     */
    public function setOpeningBalance(Contact $contact, string|int|float $amount, ?CarbonInterface $date = null): void
    {
        DB::transaction(function () use ($contact, $amount, $date) {
            $amount = BigDecimal::of((string) $amount)->toScale(2);
            $existing = $contact->ledger()->where('type', LedgerEntryType::Opening)->first();

            if ($amount->isZero()) {
                $existing?->delete();

                return;
            }

            $values = [
                'debit' => (string) ($amount->isPositive() ? $amount : BigDecimal::zero()->toScale(2)),
                'credit' => (string) ($amount->isNegative() ? $amount->abs() : BigDecimal::zero()->toScale(2)),
            ];

            if ($existing) {
                $existing->update($values);
            } else {
                $this->record($contact, LedgerEntryType::Opening, $values['debit'], $values['credit'], $date ?? $contact->created_at ?? now(), note: 'Opening balance');
            }
        });
    }

    public function openingBalance(Contact $contact): string
    {
        $e = $contact->ledger()->where('type', LedgerEntryType::Opening)->first();

        return $e ? (string) BigDecimal::of($e->debit)->minus($e->credit) : '0.00';
    }

    /**
     * Statement for a period with a running balance.
     *
     * @return array{opening: string, closing: string, debit: string, credit: string, rows: list<array>}
     */
    public function statement(Contact $contact, ?string $from = null, ?string $to = null): array
    {
        $before = $from
            ? (string) ($contact->ledger()->where('date', '<', $from)->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as b')->value('b') ?? 0)
            : '0';

        $entries = $contact->ledger()
            ->with(['user:id,name', 'branch:id,code', 'reference'])
            ->when($from, fn ($q) => $q->where('date', '>=', $from))
            ->when($to, fn ($q) => $q->where('date', '<=', $to))
            ->orderBy('date')->orderBy('id')
            ->get();

        $running = BigDecimal::of($before);
        $debit = BigDecimal::zero();
        $credit = BigDecimal::zero();
        $rows = [];

        foreach ($entries as $e) {
            $running = $running->plus($e->debit)->minus($e->credit);
            $debit = $debit->plus($e->debit);
            $credit = $credit->plus($e->credit);
            $rows[] = [
                'id' => $e->id,
                'date' => $e->date->toDateString(),
                'type' => $e->type->value,
                'type_label' => $e->type->label(),
                'reference' => $e->reference?->reference_no ?? $e->reference?->invoice_no ?? null,
                'note' => $e->note,
                'branch' => $e->branch?->code,
                'debit' => (float) $e->debit,
                'credit' => (float) $e->credit,
                'balance' => (float) (string) $running,
            ];
        }

        return [
            'opening' => (string) BigDecimal::of($before)->toScale(2),
            'closing' => (string) $running->toScale(2),
            'debit' => (string) $debit->toScale(2),
            'credit' => (string) $credit->toScale(2),
            'rows' => $rows,
        ];
    }
}
