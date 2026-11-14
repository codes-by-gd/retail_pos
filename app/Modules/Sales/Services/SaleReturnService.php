<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Contacts\Enums\LedgerEntryType;
use App\Modules\Contacts\Services\ContactLedgerService;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Pos\Models\RegisterSession;
use App\Modules\Pos\Services\RegisterService;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Services\DocumentNumberService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Returns / exchanges against an invoice → credit note.
 *
 * Refund methods:
 *   cash | card | upi – money paid back (cash leaves the register drawer)
 *   account           – credited to the customer's account (store credit), named customers only
 *   exchange          – credit used as payment in the next bill (works for walk-in customers too)
 */
class SaleReturnService
{
    public const REFUND_METHODS = ['cash', 'card', 'upi', 'account', 'exchange'];

    public function __construct(
        private StockService $stock,
        private ContactLedgerService $ledger,
        private DocumentNumberService $numbers,
        private RegisterService $registers,
    ) {}

    /**
     * @param  list<array{sale_item_id: int, quantity: mixed, restock?: bool}>  $items
     *
     * @throws ValidationException|\DomainException
     */
    public function create(Sale $sale, array $items, string $refundMethod, ?string $reason, User $user, ?RegisterSession $session, Branch $branch): SaleReturn
    {
        return DB::transaction(function () use ($sale, $items, $refundMethod, $reason, $user, $session, $branch) {
            $sale = Sale::with('customer')->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($sale->status !== Sale::COMPLETED) {
                $this->fail('sale_id', 'Only completed sales can be returned.');
            }
            if (! in_array($refundMethod, self::REFUND_METHODS, true)) {
                $this->fail('refund_method', 'Choose how to refund.');
            }
            if ($refundMethod === 'account' && $sale->customer->is_walk_in) {
                $this->fail('refund_method', 'Store credit needs a named customer. Use exchange or a refund instead.');
            }
            if ($refundMethod === 'cash') {
                if (! $session) {
                    $this->fail('refund_method', 'Open the register to give a cash refund.');
                }
                $this->registers->ensureOpen($session);
            }

            $saleItems = SaleItem::where('sale_id', $sale->id)->with('variant.product.unit')->lockForUpdate()->get()->keyBy('id');
            $lines = [];
            foreach (array_values($items) as $i => $item) {
                $qty = BigDecimal::of((string) ($item['quantity'] ?? 0));
                if (! $qty->isPositive()) {
                    continue;
                }
                $saleItem = $saleItems->get($item['sale_item_id']) ?? $this->fail("items.$i.sale_item_id", 'Item is not on this invoice.');
                $remaining = BigDecimal::of($saleItem->quantity)->minus($saleItem->returned_quantity);
                if ($qty->isGreaterThan($remaining)) {
                    $this->fail("items.$i.quantity", "Only {$remaining->stripTrailingZeros()} of {$saleItem->displayName()} can still be returned.");
                }
                if (! $saleItem->variant->product->unit->allow_decimal && ! $qty->isEqualTo($qty->toScale(0, RoundingMode::DOWN))) {
                    $this->fail("items.$i.quantity", 'Whole pieces only.');
                }
                $lines[] = ['item' => $saleItem, 'qty' => $qty, 'all' => $qty->isEqualTo($remaining), 'restock' => (bool) ($item['restock'] ?? true)];
            }
            if (! $lines) {
                $this->fail('items', 'Choose at least one item to return.');
            }

            $return = SaleReturn::create([
                'credit_note_no' => $this->numbers->gstNumber('CN', 'CN', $branch),
                'sale_id' => $sale->id,
                'branch_id' => $branch->id,
                'register_session_id' => $session?->id,
                'customer_id' => $sale->customer_id,
                'user_id' => $user->id,
                'returned_at' => now(),
                'taxable_total' => 0, 'total' => 0,
                'refund_method' => $refundMethod,
                'reason' => $reason,
            ]);

            $zero = BigDecimal::zero()->toScale(2);
            $sum = array_fill_keys(['taxable_value', 'cgst', 'sgst', 'igst', 'cess', 'line_total'], $zero);
            foreach ($lines as $l) {
                $amounts = $this->amountsFor($l['item'], $l['qty'], $l['all']);
                $return->items()->create([
                    'sale_item_id' => $l['item']->id,
                    'product_variant_id' => $l['item']->product_variant_id,
                    'quantity' => (string) $l['qty'],
                    'restock' => $l['restock'],
                    ...array_map('strval', $amounts),
                ]);
                foreach ($sum as $k => $v) {
                    $sum[$k] = $v->plus($amounts[$k]);
                }

                $l['item']->update(['returned_quantity' => (string) BigDecimal::of($l['item']->returned_quantity)->plus($l['qty'])]);

                if ($l['restock']) {
                    $this->stock->move($l['item']->variant, $branch->id, (string) $l['qty'], StockMovementType::SaleReturn,
                        $l['item']->unit_cost, $return, "Return {$return->credit_note_no}");
                }
            }

            // Round cumulatively per invoice: returning pieces one by one must never refund more than was paid.
            $total = $sum['line_total'];
            if (settings('tax.round_off')) {
                $previous = SaleReturn::where('sale_id', $sale->id)->whereKeyNot($return->id)
                    ->selectRaw('COALESCE(SUM(total), 0) as totals, COALESCE(SUM(total - round_off), 0) as line_sum')->first();
                $cumulative = BigDecimal::of((string) $previous->line_sum)->plus($sum['line_total']);
                $total = $cumulative->toScale(0, RoundingMode::HALF_UP)->toScale(2)->minus(BigDecimal::of((string) $previous->totals))->toScale(2);
            }
            $return->update([
                'taxable_total' => (string) $sum['taxable_value'],
                'cgst_total' => (string) $sum['cgst'],
                'sgst_total' => (string) $sum['sgst'],
                'igst_total' => (string) $sum['igst'],
                'cess_total' => (string) $sum['cess'],
                'round_off' => (string) $total->minus($sum['line_total']),
                'total' => (string) $total,
            ]);

            if (! $sale->customer->is_walk_in) {
                $this->ledger->record($sale->customer, LedgerEntryType::SaleReturn, credit: (string) $total, reference: $return,
                    note: "Credit note {$return->credit_note_no} for {$sale->invoice_no}", branchId: $branch->id);
                if (in_array($refundMethod, ['cash', 'card', 'upi'], true)) {
                    $this->ledger->record($sale->customer, LedgerEntryType::Refund, debit: (string) $total, reference: $return,
                        note: 'Refund by '.strtoupper($refundMethod), branchId: $branch->id);
                }
            }

            activity()->causedBy($user)->performedOn($return)->withProperties(['sale' => $sale->invoice_no, 'total' => (string) $total, 'refund' => $refundMethod])->log('Sale return');

            return $return->fresh('items');
        });
    }

    /**
     * Share of the original line for $qty pieces. When the last remaining pieces are returned,
     * take whatever is left so repeated partial returns never drift by a paisa.
     *
     * @return array<string, BigDecimal>
     */
    private function amountsFor(SaleItem $item, BigDecimal $qty, bool $all): array
    {
        $fields = ['taxable_value', 'cgst', 'sgst', 'igst', 'cess', 'line_total'];
        $out = [];

        if ($all) {
            $already = $item->sale->returns()->join('sale_return_items', 'sale_return_items.sale_return_id', '=', 'sale_returns.id')
                ->where('sale_return_items.sale_item_id', $item->id)
                ->selectRaw(implode(', ', array_map(fn ($f) => "COALESCE(SUM(sale_return_items.$f), 0) as $f", $fields)))
                ->first();
            foreach ($fields as $f) {
                $out[$f] = BigDecimal::of((string) $item->{$f})->minus(BigDecimal::of((string) ($already->{$f} ?? 0)))->toScale(2);
            }

            return $out;
        }

        foreach ($fields as $f) {
            $out[$f] = BigDecimal::of((string) $item->{$f})->multipliedBy($qty)->dividedBy($item->quantity, 2, RoundingMode::HALF_UP);
        }
        // keep the line consistent: total = taxable + taxes for exclusive, or total share for inclusive
        $out['line_total'] = $out['taxable_value']->plus($out['cgst'])->plus($out['sgst'])->plus($out['igst'])->plus($out['cess']);

        return $out;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
