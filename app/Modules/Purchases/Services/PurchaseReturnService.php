<?php

namespace App\Modules\Purchases\Services;

use App\Models\User;
use App\Modules\Contacts\Enums\LedgerEntryType;
use App\Modules\Contacts\Services\ContactLedgerService;
use App\Modules\Contacts\Services\PaymentService;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Purchases\Models\PurchaseItem;
use App\Modules\Purchases\Models\PurchaseReturn;
use App\Modules\Settings\Services\DocumentNumberService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Debit note: goods back to the supplier. Mirrors sale returns (proportional amounts, cumulative rounding). */
class PurchaseReturnService
{
    public function __construct(private StockService $stock, private ContactLedgerService $ledger, private DocumentNumberService $numbers) {}

    /** @param list<array{purchase_item_id: int, quantity: mixed}> $items */
    public function create(Purchase $purchase, array $items, ?string $reason, User $user): PurchaseReturn
    {
        return DB::transaction(function () use ($purchase, $items, $reason, $user) {
            $purchase = Purchase::with(['supplier', 'branch'])->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            $purchaseItems = PurchaseItem::where('purchase_id', $purchase->id)->with('variant.product.unit')->lockForUpdate()->get()->keyBy('id');

            $lines = [];
            foreach (array_values($items) as $i => $item) {
                $qty = BigDecimal::of((string) ($item['quantity'] ?? 0));
                if (! $qty->isPositive()) {
                    continue;
                }
                $pi = $purchaseItems->get($item['purchase_item_id']) ?? $this->fail("items.$i.purchase_item_id", 'Not on this purchase.');
                $left = BigDecimal::of($pi->quantity)->minus($pi->returned_quantity);
                if ($qty->isGreaterThan($left)) {
                    $this->fail("items.$i.quantity", "Only {$left->stripTrailingZeros()} of {$pi->displayName()} can still be returned.");
                }
                $lines[] = [$pi, $qty, $qty->isEqualTo($left)];
            }
            if (! $lines) {
                $this->fail('items', 'Choose at least one item to return.');
            }

            $return = PurchaseReturn::create([
                'reference_no' => $this->numbers->next('DN', $purchase->branch),
                'purchase_id' => $purchase->id, 'branch_id' => $purchase->branch_id, 'supplier_id' => $purchase->supplier_id,
                'user_id' => $user->id, 'date' => now()->toDateString(), 'reason' => $reason,
            ]);

            $fields = ['taxable_value', 'cgst', 'sgst', 'igst', 'cess', 'line_total'];
            $sum = array_fill_keys($fields, BigDecimal::zero()->toScale(2));
            foreach ($lines as [$pi, $qty, $all]) {
                $amounts = [];
                foreach ($fields as $f) {
                    if ($all) {
                        $already = BigDecimal::of((string) $return->newQuery()->join('purchase_return_items', 'purchase_return_items.purchase_return_id', '=', 'purchase_returns.id')
                            ->where('purchase_return_items.purchase_item_id', $pi->id)->sum("purchase_return_items.$f"));
                        $amounts[$f] = BigDecimal::of($pi->{$f})->minus($already)->toScale(2);
                    } else {
                        $amounts[$f] = BigDecimal::of($pi->{$f})->multipliedBy($qty)->dividedBy($pi->quantity, 2, RoundingMode::HALF_UP);
                    }
                }
                if (! $all) {
                    $amounts['line_total'] = $amounts['taxable_value']->plus($amounts['cgst'])->plus($amounts['sgst'])->plus($amounts['igst'])->plus($amounts['cess']);
                }

                $return->items()->create(['purchase_item_id' => $pi->id, 'product_variant_id' => $pi->product_variant_id, 'quantity' => (string) $qty, ...array_map('strval', $amounts)]);
                foreach ($fields as $f) {
                    $sum[$f] = $sum[$f]->plus($amounts[$f]);
                }
                $pi->update(['returned_quantity' => (string) BigDecimal::of($pi->returned_quantity)->plus($qty)]);

                $this->stock->move($pi->variant, $purchase->branch_id, (string) $qty->negated(), StockMovementType::PurchaseReturn,
                    reference: $return, note: "{$return->reference_no} to {$purchase->supplier->name}", allowNegative: false);
            }

            // Freight and round-off of the bill stay with the supplier unless everything is returned.
            $total = $sum['line_total'];
            $everything = PurchaseItem::where('purchase_id', $purchase->id)->get()->every(fn ($i) => BigDecimal::of($i->returned_quantity)->isGreaterThanOrEqualTo($i->quantity));
            if ($everything) {
                $total = BigDecimal::of($purchase->grand_total)->minus(BigDecimal::of((string) PurchaseReturn::where('purchase_id', $purchase->id)->whereKeyNot($return->id)->sum('total')))->toScale(2);
            }

            $return->update([
                'taxable_total' => (string) $sum['taxable_value'], 'cgst_total' => (string) $sum['cgst'], 'sgst_total' => (string) $sum['sgst'],
                'igst_total' => (string) $sum['igst'], 'cess_total' => (string) $sum['cess'], 'total' => (string) $total,
            ]);

            $this->ledger->record($purchase->supplier, LedgerEntryType::PurchaseReturn, debit: (string) $total, reference: $return,
                note: "Debit note {$return->reference_no} for ".($purchase->supplier_invoice_no ?: $purchase->reference_no), branchId: $purchase->branch_id);

            // The debit note settles what is still unpaid on this bill first.
            if (BigDecimal::of($purchase->due_amount)->isPositive()) {
                PaymentService::reduceDue($purchase, BigDecimal::min($total, BigDecimal::of($purchase->due_amount)), false);
            }

            return $return->fresh('items');
        });
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
