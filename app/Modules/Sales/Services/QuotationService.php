<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Inventory\Http\Controllers\VariantSearchController;
use App\Modules\Inventory\Models\ProductVariant;
use App\Modules\Pos\Models\HeldCart;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Services\DocumentNumberService;
use App\Support\Gst\GstCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Quotations use the customer's list price (retail or wholesale) plus % / ₹ discounts — never a price
 * override — so a converted quotation fits the POS discount rules without manager approval.
 */
class QuotationService
{
    public function __construct(private DocumentNumberService $numbers) {}

    /**
     * @param  array{customer_id?: ?int, customer_name?: ?string, customer_phone?: ?string, date: string, valid_until?: ?string,
     *               items: array, bill_discount_type?: ?string, bill_discount_value?: mixed, notes?: ?string}  $data
     */
    public function save(array $data, Branch $branch, User $user, ?Quotation $quotation = null): Quotation
    {
        if ($quotation && ! $quotation->isEditable()) {
            throw new \DomainException("Quotation {$quotation->reference_no} is {$quotation->status} and can't be changed.");
        }

        return DB::transaction(function () use ($data, $branch, $user, $quotation) {
            $customer = ! empty($data['customer_id'])
                ? Contact::with('group')->whereKey($data['customer_id'])->whereIn('type', [Contact::CUSTOMER, Contact::BOTH])->first()
                : null;
            if (! empty($data['customer_id']) && ! $customer) {
                throw ValidationException::withMessages(['customer_id' => 'Choose a customer.']);
            }
            if ($customer?->is_walk_in) {
                $customer = null;
            }

            $wholesale = $customer?->group?->price_type === 'wholesale';
            $placeOfSupply = $customer?->state_code ?: $branch->state_code;
            $interState = $placeOfSupply !== $branch->state_code;

            $variants = ProductVariant::with(['product.taxRate', 'product.unit'])->whereIn('id', collect($data['items'])->pluck('variant_id'))->get()->keyBy('id');
            $lines = [];
            $meta = [];
            foreach (array_values($data['items']) as $i => $item) {
                $v = $variants->get($item['variant_id']) ?? throw ValidationException::withMessages(["items.$i.variant_id" => 'Item not found.']);
                $price = ($wholesale && $v->wholesale_price !== null) ? $v->wholesale_price : $v->selling_price;
                $tax = $v->product->taxRate;
                $discountType = ! empty($item['discount_value']) ? ($item['discount_type'] ?? 'percent') : null;
                $lines[] = [
                    'unit_price' => (string) $price, 'quantity' => (string) $item['quantity'], 'price_includes_tax' => $v->product->price_includes_tax,
                    'rate' => (string) $tax->rate, 'cess_rate' => (string) $tax->cess_rate, 'threshold' => $tax->threshold_amount, 'rate_above' => $tax->rate_above,
                    'discount_type' => $discountType, 'discount_value' => $item['discount_value'] ?? 0,
                ];
                $meta[] = [$v, $price, $discountType, $item];
            }

            $billType = ! empty($data['bill_discount_value']) ? ($data['bill_discount_type'] ?? 'percent') : null;
            $calc = GstCalculator::calculate($lines, $billType ? ['type' => $billType, 'value' => $data['bill_discount_value']] : null, $interState, (bool) settings('tax.round_off'));
            $t = $calc['totals'];

            $attributes = [
                'branch_id' => $branch->id,
                'customer_id' => $customer?->id,
                'customer_name' => $customer ? ($customer->company_name ?: $customer->name) : ($data['customer_name'] ?: 'Walk-in Customer'),
                'customer_phone' => $customer?->phone ?? ($data['customer_phone'] ?? null),
                'place_of_supply' => $placeOfSupply,
                'is_inter_state' => $interState,
                'date' => $data['date'],
                'valid_until' => $data['valid_until'] ?? null,
                'bill_discount_type' => $billType,
                'bill_discount_value' => $billType ? $data['bill_discount_value'] : 0,
                'subtotal' => $t['subtotal'], 'line_discount_total' => $t['line_discount_total'], 'bill_discount_total' => $t['bill_discount_total'],
                'taxable_total' => $t['taxable_total'], 'cgst_total' => $t['cgst_total'], 'sgst_total' => $t['sgst_total'], 'igst_total' => $t['igst_total'],
                'cess_total' => $t['cess_total'], 'round_off' => $t['round_off'], 'grand_total' => $t['grand_total'],
                'notes' => $data['notes'] ?? null,
            ];

            if ($quotation) {
                $quotation->update($attributes);
                $quotation->items()->delete();
            } else {
                $quotation = Quotation::create([...$attributes, 'reference_no' => $this->numbers->next('QTN', $branch), 'user_id' => $user->id, 'status' => 'draft']);
            }

            foreach ($calc['lines'] as $i => $line) {
                [$v, $price, $discountType, $item] = $meta[$i];
                $quotation->items()->create([
                    'product_variant_id' => $v->id, 'product_name' => $v->product->name, 'variant_name' => $v->name, 'sku' => $v->sku,
                    'hsn_code' => $v->product->hsn_code, 'unit' => $v->product->unit->short_name, 'quantity' => $item['quantity'], 'unit_price' => $price,
                    'discount_type' => $discountType, 'discount_value' => $discountType ? $item['discount_value'] : 0,
                    'line_discount' => $line['line_discount'], 'bill_discount_share' => $line['bill_discount_share'], 'taxable_value' => $line['taxable_value'],
                    'gst_rate' => $line['gst_rate'], 'cgst' => $line['cgst'], 'sgst' => $line['sgst'], 'igst' => $line['igst'], 'cess' => $line['cess'],
                    'line_total' => $line['line_total'],
                ]);
            }

            return $quotation->fresh('items');
        });
    }

    /** Put the quotation into the POS as a held bill (prices and stock are refreshed when it is recalled). */
    public function convert(Quotation $quotation, User $user, Branch $branch): HeldCart
    {
        if (! in_array($quotation->status, ['draft', 'sent', 'accepted'], true)) {
            throw new \DomainException("Quotation {$quotation->reference_no} is {$quotation->status}.");
        }

        return DB::transaction(function () use ($quotation, $user, $branch) {
            $quotation->load('items', 'customer.group');
            $variants = ProductVariant::active()->with(VariantSearchController::WITH)
                ->whereIn('id', $quotation->items->pluck('product_variant_id'))->get()->keyBy('id');

            $items = $quotation->items->filter(fn ($i) => $variants->has($i->product_variant_id))->values()
                ->map(fn ($i, $n) => [
                    ...VariantSearchController::present($variants[$i->product_variant_id]),
                    'variant_id' => $i->product_variant_id,
                    'key' => $n + 1,
                    'quantity' => (float) $i->quantity,
                    'unit_price' => null,
                    'discount_type' => $i->discount_type ?? 'percent',
                    'discount_value' => (float) $i->discount_value,
                ])->all();
            if (! $items) {
                throw new \DomainException('None of the quoted items are available any more.');
            }

            $customer = $quotation->customer && ! $quotation->customer->trashed()
                ? Contact::forPos($quotation->customer)
                : Contact::forPos(Contact::with('group')->where('is_walk_in', true)->firstOrFail());

            $cart = HeldCart::create([
                'branch_id' => $branch->id,
                'user_id' => $user->id,
                'label' => "Quotation {$quotation->reference_no} · {$quotation->customer_name}",
                'payload' => [
                    'items' => $items,
                    'customer' => $customer,
                    'billDiscount' => ['type' => $quotation->bill_discount_type, 'value' => (float) $quotation->bill_discount_value],
                    'note' => "From quotation {$quotation->reference_no}",
                    'exchangeCredits' => [],
                    'total' => (float) $quotation->grand_total,
                ],
            ]);

            $quotation->update(['status' => 'converted', 'converted_at' => now()]);

            return $cart;
        });
    }
}
