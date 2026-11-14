<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Contacts\Enums\LedgerEntryType;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Services\ContactLedgerService;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\ProductVariant;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Pos\Models\HeldCart;
use App\Modules\Pos\Models\RegisterSession;
use App\Modules\Pos\Services\RegisterService;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SalePayment;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Services\DocumentNumberService;
use App\Support\Gst\GstCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a POS cart into a sale. The server recalculates the whole bill with GstCalculator;
 * the client's `expected_total` must match, so a cashier never charges a different amount than shown.
 */
class CheckoutService
{
    public function __construct(
        private StockService $stock,
        private ContactLedgerService $ledger,
        private DocumentNumberService $numbers,
        private RegisterService $registers,
    ) {}

    /**
     * @param  array{customer_id?: ?int, items: array, bill_discount_type?: ?string, bill_discount_value?: mixed,
     *               payments: array, expected_total: mixed, note?: ?string, held_cart_id?: ?int}  $data
     *
     * @throws ValidationException|\DomainException
     */
    public function checkout(RegisterSession $session, User $user, array $data): Sale
    {
        return DB::transaction(function () use ($session, $user, $data) {
            $session = RegisterSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $this->registers->ensureOpen($session);
            $branch = Branch::findOrFail($session->branch_id);

            $customer = $this->customer($data['customer_id'] ?? null);
            $wholesale = $customer->group?->price_type === 'wholesale';
            $groupDiscount = (float) ($customer->group?->discount_percent ?? 0);

            // ---------- lines ----------
            $variants = ProductVariant::query()
                ->with(['product.taxRate', 'product.unit'])
                ->whereIn('id', collect($data['items'])->pluck('variant_id'))
                ->get()->keyBy('id');

            $calcLines = [];
            $meta = [];
            foreach (array_values($data['items']) as $i => $item) {
                $variant = $variants->get($item['variant_id']);
                if (! $variant || ! $variant->is_active || ! $variant->product || ! $variant->product->is_active) {
                    $this->fail("items.$i.variant_id", 'This item is no longer available.');
                }
                $product = $variant->product;
                $qty = BigDecimal::of((string) $item['quantity']);
                if (! $product->unit->allow_decimal && ! $qty->isEqualTo($qty->toScale(0, RoundingMode::DOWN))) {
                    $this->fail("items.$i.quantity", "{$variant->fullName()} is sold in whole {$product->unit->short_name}.");
                }

                $list = ($wholesale && $variant->wholesale_price !== null) ? $variant->wholesale_price : $variant->selling_price;
                $price = $list;
                if (isset($item['unit_price']) && $item['unit_price'] !== null && ! BigDecimal::of((string) $item['unit_price'])->isEqualTo($list)) {
                    if (! $user->can('pos.price_override')) {
                        $this->fail("items.$i.unit_price", 'You are not allowed to change prices.');
                    }
                    if ($variant->mrp !== null && BigDecimal::of((string) $item['unit_price'])->isGreaterThan($variant->mrp)) {
                        $this->fail("items.$i.unit_price", "Price can't be above MRP (₹{$variant->mrp}).");
                    }
                    $price = (string) BigDecimal::of((string) $item['unit_price'])->toScale(2);
                }

                $discountType = ! empty($item['discount_value']) ? ($item['discount_type'] ?? null) : null;
                if ($discountType && ! $user->can('pos.discount')) {
                    $this->fail("items.$i.discount_value", 'You are not allowed to give discounts.');
                }

                $tax = $product->taxRate;
                $calcLines[] = [
                    'key' => $i,
                    'unit_price' => (string) $price,
                    'quantity' => (string) $qty,
                    'price_includes_tax' => $product->price_includes_tax,
                    'rate' => (string) $tax->rate,
                    'cess_rate' => (string) $tax->cess_rate,
                    'threshold' => $tax->threshold_amount,
                    'rate_above' => $tax->rate_above,
                    'discount_type' => $discountType,
                    'discount_value' => $item['discount_value'] ?? 0,
                ];
                $meta[] = ['variant' => $variant, 'list' => $list, 'price' => $price, 'qty' => $qty, 'discount_type' => $discountType, 'discount_value' => $item['discount_value'] ?? 0];
            }

            // ---------- bill discount (group discount is automatic; anything else needs permission) ----------
            $billType = ! empty($data['bill_discount_value']) ? ($data['bill_discount_type'] ?? null) : null;
            $billValue = $billType ? $data['bill_discount_value'] : 0;
            $isGroupDiscount = $billType === 'percent' && abs((float) $billValue - $groupDiscount) < 0.001;
            if ($billType && ! $isGroupDiscount && ! $user->can('pos.discount')) {
                $this->fail('bill_discount_value', 'You are not allowed to give discounts.');
            }

            $placeOfSupply = $customer->state_code ?: $branch->state_code;
            $interState = $placeOfSupply !== $branch->state_code;
            $calc = GstCalculator::calculate($calcLines, $billType ? ['type' => $billType, 'value' => $billValue] : null, $interState, (bool) settings('tax.round_off'));
            $totals = $calc['totals'];
            $grand = BigDecimal::of($totals['grand_total']);

            if (! $grand->isEqualTo(BigDecimal::of((string) $data['expected_total']))) {
                $this->fail('expected_total', "The bill total changed to ₹{$totals['grand_total']} (prices or stock were updated). Please review the bill.");
            }

            // ---------- payments ----------
            $payments = $this->validatePayments($data['payments'] ?? [], $grand, $customer);

            // ---------- save ----------
            $sale = Sale::create([
                'invoice_no' => $this->numbers->gstNumber('INV', (string) settings('invoice.prefix', ''), $branch),
                'branch_id' => $branch->id,
                'register_session_id' => $session->id,
                'customer_id' => $customer->id,
                'user_id' => $user->id,
                'sold_at' => now(),
                'customer_name' => $customer->company_name ?: $customer->name,
                'customer_phone' => $customer->phone,
                'customer_gstin' => $customer->gstin,
                'place_of_supply' => $placeOfSupply,
                'is_inter_state' => $interState,
                'price_type' => $wholesale ? 'wholesale' : 'retail',
                'subtotal' => $totals['subtotal'],
                'line_discount_total' => $totals['line_discount_total'],
                'bill_discount_type' => $billType,
                'bill_discount_value' => $billValue ?: 0,
                'bill_discount_total' => $totals['bill_discount_total'],
                'taxable_total' => $totals['taxable_total'],
                'cgst_total' => $totals['cgst_total'],
                'sgst_total' => $totals['sgst_total'],
                'igst_total' => $totals['igst_total'],
                'cess_total' => $totals['cess_total'],
                'round_off' => $totals['round_off'],
                'grand_total' => $totals['grand_total'],
                'paid_total' => (string) $payments['total'],
                'change_given' => (string) $payments['change'],
                'account_amount' => (string) $payments['account'],
                'status' => Sale::COMPLETED,
                'payment_status' => $payments['account']->isZero() ? 'paid' : ($payments['account']->isEqualTo($grand) ? 'due' : 'partial'),
                'note' => $data['note'] ?? null,
            ]);

            foreach ($calc['lines'] as $i => $line) {
                $m = $meta[$i];
                $variant = $m['variant'];
                $product = $variant->product;

                $movement = $this->stock->move($variant, $branch->id, (string) $m['qty']->negated(), StockMovementType::Sale, reference: $sale, note: $sale->invoice_no);

                $sale->items()->create([
                    'product_variant_id' => $variant->id,
                    'product_name' => $product->name,
                    'variant_name' => $variant->name,
                    'sku' => $variant->sku,
                    'hsn_code' => $product->hsn_code,
                    'unit' => $product->unit->short_name,
                    'price_includes_tax' => $product->price_includes_tax,
                    'mrp' => $variant->mrp,
                    'list_price' => $m['list'],
                    'unit_price' => $m['price'],
                    'quantity' => (string) $m['qty'],
                    'discount_type' => $m['discount_type'],
                    'discount_value' => $m['discount_type'] ? $m['discount_value'] : 0,
                    'line_discount' => $line['line_discount'],
                    'bill_discount_share' => $line['bill_discount_share'],
                    'taxable_value' => $line['taxable_value'],
                    'gst_rate' => $line['gst_rate'],
                    'cgst' => $line['cgst'],
                    'sgst' => $line['sgst'],
                    'igst' => $line['igst'],
                    'cess_rate' => $line['cess_rate'],
                    'cess' => $line['cess'],
                    'line_total' => $line['line_total'],
                    'unit_cost' => $movement?->unit_cost ?? $variant->purchase_price ?? 0,
                ]);
            }

            foreach ($payments['rows'] as $p) {
                $sale->payments()->create($p);
                if ($p['method'] === SalePayment::EXCHANGE) {
                    SaleReturn::whereKey($p['sale_return_id'])->update(['used_in_sale_id' => $sale->id]);
                }
            }

            $this->postToLedger($sale, $customer, $payments);

            if (! empty($data['held_cart_id'])) {
                HeldCart::where('id', $data['held_cart_id'])->where('branch_id', $branch->id)->delete();
            }

            return $sale;
        });
    }

    private function customer(?int $id): Contact
    {
        $customer = $id
            ? Contact::with('group')->whereKey($id)->whereIn('type', [Contact::CUSTOMER, Contact::BOTH])->where('is_active', true)->first()
            : Contact::with('group')->where('is_walk_in', true)->first();

        return $customer ?? $this->fail('customer_id', 'Choose an active customer.');
    }

    /**
     * Rules: total tendered ≥ bill; only cash / exchange credit can create change;
     * "on account" needs a named customer within their credit limit; exchange credit is used once, in full.
     *
     * @return array{rows: list<array>, total: BigDecimal, change: BigDecimal, account: BigDecimal, settled: BigDecimal}
     */
    private function validatePayments(array $payments, BigDecimal $grand, Contact $customer): array
    {
        $zero = BigDecimal::zero()->toScale(2);
        $sum = fn (string ...$methods) => array_reduce(
            array_filter($payments, fn ($p) => in_array($p['method'], $methods, true)),
            fn ($s, $p) => $s->plus(BigDecimal::of((string) $p['amount'])->toScale(2)), $zero,
        );

        $enabled = [SalePayment::CASH, SalePayment::ACCOUNT, SalePayment::EXCHANGE];
        if (settings('pos.accept_card')) {
            $enabled[] = SalePayment::CARD;
        }
        if (settings('pos.accept_upi')) {
            $enabled[] = SalePayment::UPI;
        }

        $rows = [];
        $usedReturns = [];
        foreach (array_values($payments) as $i => $p) {
            if (! in_array($p['method'], $enabled, true)) {
                $this->fail("payments.$i.method", 'This payment method is turned off in settings.');
            }
            $row = ['method' => $p['method'], 'amount' => (string) BigDecimal::of((string) $p['amount'])->toScale(2), 'reference' => $p['reference'] ?? null, 'sale_return_id' => null];

            if ($p['method'] === SalePayment::EXCHANGE) {
                $return = SaleReturn::whereKey($p['sale_return_id'] ?? 0)->lockForUpdate()->first();
                if (! $return || $return->refund_method !== 'exchange' || $return->used_in_sale_id || in_array($return->id, $usedReturns, true)) {
                    $this->fail("payments.$i.sale_return_id", 'This exchange credit is not valid or was already used.');
                }
                if (! BigDecimal::of($row['amount'])->isEqualTo($return->total)) {
                    $this->fail("payments.$i.amount", "Exchange credit {$return->credit_note_no} is worth ₹{$return->total} and must be used in full.");
                }
                $usedReturns[] = $return->id;
                $row['sale_return_id'] = $return->id;
                $row['reference'] = $return->credit_note_no;
            }
            $rows[] = $row;
        }

        $total = $sum(...$enabled);
        if ($total->isLessThan($grand)) {
            $this->fail('payments', 'Payment is short by ₹'.$grand->minus($total).'.');
        }

        $change = $total->minus($grand);
        if ($change->isGreaterThan($sum(SalePayment::CASH, SalePayment::EXCHANGE))) {
            $this->fail('payments', 'Card, UPI and on-account amounts cannot be more than the bill.');
        }

        $account = $sum(SalePayment::ACCOUNT);
        if ($account->isPositive()) {
            if ($customer->is_walk_in) {
                $this->fail('payments', 'Choose a customer to put the amount on their account.');
            }
            $newBalance = BigDecimal::of($customer->balance())->plus($account);
            if ($newBalance->isGreaterThan($customer->credit_limit)) {
                $this->fail('payments', "Credit limit exceeded: balance would be ₹{$newBalance}, limit ₹{$customer->credit_limit}.");
            }
        }

        // Money actually settled now (cash/card/UPI minus change). Negative when exchange credit exceeds the bill.
        $settled = $sum(SalePayment::CASH, SalePayment::CARD, SalePayment::UPI)->minus($change);

        return compact('rows', 'total', 'change', 'account', 'settled');
    }

    /** Named customers only: sale debit, money settled now credit (or refund debit for exchange excess). */
    private function postToLedger(Sale $sale, Contact $customer, array $payments): void
    {
        if ($customer->is_walk_in) {
            return;
        }

        $this->ledger->record($customer, LedgerEntryType::Sale, debit: $sale->grand_total, reference: $sale, note: "Invoice {$sale->invoice_no}", branchId: $sale->branch_id);

        $settled = $payments['settled'];
        if ($settled->isPositive()) {
            $this->ledger->record($customer, LedgerEntryType::PaymentReceived, credit: (string) $settled, reference: $sale, note: "Paid at counter · {$sale->invoice_no}", branchId: $sale->branch_id);
        } elseif ($settled->isNegative()) {
            $this->ledger->record($customer, LedgerEntryType::Refund, debit: (string) $settled->abs(), reference: $sale, note: "Exchange balance paid back · {$sale->invoice_no}", branchId: $sale->branch_id);
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
