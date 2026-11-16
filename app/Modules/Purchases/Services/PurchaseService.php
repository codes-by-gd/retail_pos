<?php

namespace App\Modules\Purchases\Services;

use App\Models\User;
use App\Modules\Contacts\Enums\LedgerEntryType;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\ContactPayment;
use App\Modules\Contacts\Services\ContactLedgerService;
use App\Modules\Contacts\Services\PaymentService;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\ProductVariant;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Pos\Models\RegisterSession;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Models\PurchaseOrderItem;
use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Services\DocumentNumberService;
use App\Support\Gst\GstCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Goods received with the supplier's bill.
 *  - Supplier rates are entered excluding GST (input tax is claimed back, so it is not part of stock cost).
 *  - GST per line with the same calculator as sales (price-based garment rate per piece, IGST for other states).
 *  - Other charges (freight, packing) are shared across lines by taxable value into the landed cost.
 *  - Stock comes in at landed cost (weighted average); the supplier is credited; optional payment now.
 */
class PurchaseService
{
    public function __construct(
        private StockService $stock,
        private ContactLedgerService $ledger,
        private DocumentNumberService $numbers,
        private PaymentService $payments,
    ) {}

    /**
     * @param  array{supplier_id: int, purchase_order_id?: ?int, supplier_invoice_no?: ?string, supplier_invoice_date?: ?string, received_date: string,
     *               items: array, other_charges?: mixed, notes?: ?string, update_cost_price?: bool, payment?: ?array}  $data
     */
    public function create(array $data, Branch $branch, User $user, ?RegisterSession $session = null): Purchase
    {
        return DB::transaction(function () use ($data, $branch, $user, $session) {
            $supplier = Contact::whereKey($data['supplier_id'])->whereIn('type', [Contact::SUPPLIER, Contact::BOTH])->first()
                ?? $this->fail('supplier_id', 'Choose a supplier.');

            if (! empty($data['supplier_invoice_no'])
                && Purchase::where('supplier_id', $supplier->id)->where('supplier_invoice_no', $data['supplier_invoice_no'])->exists()) {
                $this->fail('supplier_invoice_no', "Bill {$data['supplier_invoice_no']} from this supplier is already entered.");
            }

            $order = null;
            if (! empty($data['purchase_order_id'])) {
                $order = PurchaseOrder::whereKey($data['purchase_order_id'])->lockForUpdate()->first();
                if (! $order || $order->supplier_id !== $supplier->id || $order->branch_id !== $branch->id || ! $order->isOpen()) {
                    $this->fail('purchase_order_id', 'This purchase order is not open for this supplier and branch.');
                }
            }

            $placeOfSupply = $supplier->state_code ?: $branch->state_code;
            $interState = $placeOfSupply !== $branch->state_code;

            $variants = ProductVariant::with(['product.taxRate', 'product.unit'])->whereIn('id', collect($data['items'])->pluck('variant_id'))->get()->keyBy('id');
            $lines = [];
            foreach (array_values($data['items']) as $i => $item) {
                $v = $variants->get($item['variant_id']) ?? $this->fail("items.$i.variant_id", 'Item not found.');
                $qty = BigDecimal::of((string) $item['quantity']);
                if (! $v->product->unit->allow_decimal && ! $qty->isEqualTo($qty->toScale(0, RoundingMode::Down))) {
                    $this->fail("items.$i.quantity", "{$v->fullName()} is counted in whole {$v->product->unit->short_name}.");
                }
                if (! empty($item['purchase_order_item_id'])) {
                    $poItem = PurchaseOrderItem::whereKey($item['purchase_order_item_id'])->where('purchase_order_id', $order?->id)->first()
                        ?? $this->fail("items.$i.purchase_order_item_id", 'Not on this purchase order.');
                    $left = BigDecimal::of($poItem->quantity)->minus($poItem->received_quantity);
                    if ($qty->isGreaterThan($left)) {
                        $this->fail("items.$i.quantity", "Only {$left->strippedOfTrailingZeros()} still to receive for {$v->fullName()} on this order.");
                    }
                }
                $tax = $v->product->taxRate;
                $lines[] = [
                    'unit_price' => (string) $item['unit_cost'], 'quantity' => (string) $qty, 'price_includes_tax' => false,
                    'rate' => (string) $tax->rate, 'cess_rate' => (string) $tax->cess_rate, 'threshold' => $tax->threshold_amount, 'rate_above' => $tax->rate_above,
                    'discount_type' => ! empty($item['discount_value']) ? ($item['discount_type'] ?? 'percent') : null, 'discount_value' => $item['discount_value'] ?? 0,
                ];
            }

            $calc = GstCalculator::calculate($lines, null, $interState, false);
            $t = $calc['totals'];
            $other = BigDecimal::of((string) ($data['other_charges'] ?? 0))->toScale(2);
            $beforeRound = BigDecimal::of($t['grand_total'])->plus($other);
            $grand = settings('tax.round_off') ? $beforeRound->toScale(0, RoundingMode::HalfUp)->toScale(2) : $beforeRound;

            $purchase = Purchase::create([
                'reference_no' => $this->numbers->next('PUR', $branch),
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'purchase_order_id' => $order?->id,
                'user_id' => $user->id,
                'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
                'supplier_invoice_date' => $data['supplier_invoice_date'] ?? null,
                'received_date' => $data['received_date'],
                'place_of_supply' => $placeOfSupply,
                'is_inter_state' => $interState,
                'subtotal' => $t['subtotal'],
                'discount_total' => $t['line_discount_total'],
                'taxable_total' => $t['taxable_total'],
                'cgst_total' => $t['cgst_total'], 'sgst_total' => $t['sgst_total'], 'igst_total' => $t['igst_total'], 'cess_total' => $t['cess_total'],
                'other_charges' => (string) $other,
                'round_off' => (string) $grand->minus($beforeRound),
                'grand_total' => (string) $grand,
                'paid_amount' => 0,
                'due_amount' => (string) $grand,
                'payment_status' => 'due',
                'notes' => $data['notes'] ?? null,
            ]);

            // Freight etc. shared by taxable value (remainder to the last line) → landed cost.
            $taxableTotal = BigDecimal::of($t['taxable_total']);
            $shared = BigDecimal::zero()->toScale(2);
            $last = count($calc['lines']) - 1;
            foreach (array_values($data['items']) as $i => $item) {
                $line = $calc['lines'][$i];
                $v = $variants[$item['variant_id']];
                $qty = BigDecimal::of((string) $item['quantity']);
                $share = $other->isZero() || $taxableTotal->isZero() ? BigDecimal::zero()->toScale(2)
                    : ($i === $last ? $other->minus($shared) : $other->multipliedBy($line['taxable_value'])->dividedBy($taxableTotal, 2, RoundingMode::HalfUp));
                $shared = $shared->plus($share);
                $landed = BigDecimal::of($line['taxable_value'])->plus($share)->dividedBy($qty, 4, RoundingMode::HalfUp);

                $purchase->items()->create([
                    'product_variant_id' => $v->id,
                    'purchase_order_item_id' => $item['purchase_order_item_id'] ?? null,
                    'product_name' => $v->product->name, 'variant_name' => $v->name, 'sku' => $v->sku, 'hsn_code' => $v->product->hsn_code,
                    'unit' => $v->product->unit->short_name, 'quantity' => (string) $qty, 'unit_cost' => $item['unit_cost'],
                    'discount_type' => $lines[$i]['discount_type'], 'discount_value' => $lines[$i]['discount_type'] ? $item['discount_value'] : 0,
                    'line_discount' => $line['line_discount'], 'taxable_value' => $line['taxable_value'], 'gst_rate' => $line['gst_rate'],
                    'cgst' => $line['cgst'], 'sgst' => $line['sgst'], 'igst' => $line['igst'], 'cess' => $line['cess'], 'line_total' => $line['line_total'],
                    'landed_unit_cost' => (string) $landed,
                ]);

                $this->stock->move($v, $branch->id, (string) $qty, StockMovementType::Purchase, (string) $landed, $purchase, "{$purchase->reference_no} · {$supplier->name}");

                if (! empty($data['update_cost_price'])) {
                    $v->update(['purchase_price' => (string) BigDecimal::of($line['taxable_value'])->dividedBy($qty, 2, RoundingMode::HalfUp)]);
                }
                if (! empty($item['purchase_order_item_id'])) {
                    PurchaseOrderItem::whereKey($item['purchase_order_item_id'])->increment('received_quantity', (float) (string) $qty);
                }
            }

            if ($order) {
                $order->load('items');
                $complete = $order->items->every(fn ($i) => BigDecimal::of($i->received_quantity)->isGreaterThanOrEqualTo($i->quantity));
                $order->update(['status' => $complete ? 'received' : 'partially_received']);
            }

            // Supplier is owed the bill. An existing advance (supplier owes us) settles it first.
            $advance = BigDecimal::max(BigDecimal::zero(), BigDecimal::of($supplier->balance()));
            $this->ledger->record($supplier, LedgerEntryType::Purchase, credit: (string) $grand, date: $purchase->received_date, reference: $purchase,
                note: 'Bill '.($purchase->supplier_invoice_no ?: $purchase->reference_no), branchId: $branch->id);
            if ($advance->isPositive()) {
                PaymentService::reduceDue($purchase, BigDecimal::min($advance, $grand));
            }

            if (! empty($data['payment']['amount']) && (float) $data['payment']['amount'] > 0) {
                $this->payments->record($supplier, ContactPayment::OUT, [...$data['payment'], 'date' => $data['received_date']], $user, $branch,
                    ($data['payment']['method'] ?? null) === 'cash' ? $session : null, $purchase->fresh());
            }

            activity()->causedBy($user)->performedOn($purchase)->withProperties(['supplier' => $supplier->name, 'total' => (string) $grand])->log('Purchase received');

            return $purchase->fresh(['items']);
        });
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
