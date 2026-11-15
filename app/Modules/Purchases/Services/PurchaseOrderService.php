<?php

namespace App\Modules\Purchases\Services;

use App\Models\User;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Services\DocumentNumberService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

class PurchaseOrderService
{
    public function __construct(private DocumentNumberService $numbers) {}

    /** @param array{supplier_id: int, order_date: string, expected_date?: ?string, notes?: ?string, items: array, place?: bool} $data */
    public function save(array $data, Branch $branch, User $user, ?PurchaseOrder $order = null): PurchaseOrder
    {
        if ($order && $order->status !== 'draft') {
            throw new \DomainException('Only draft orders can be edited.');
        }
        $supplier = Contact::whereKey($data['supplier_id'])->whereIn('type', [Contact::SUPPLIER, Contact::BOTH])->first()
            ?? throw new \DomainException('Choose a supplier.');

        return DB::transaction(function () use ($data, $branch, $user, $order, $supplier) {
            $total = collect($data['items'])->reduce(fn ($s, $i) => $s->plus(BigDecimal::of((string) $i['quantity'])->multipliedBy((string) $i['unit_cost'])), BigDecimal::zero());
            $attributes = [
                'supplier_id' => $supplier->id,
                'order_date' => $data['order_date'],
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'estimated_total' => (string) $total->toScale(2, RoundingMode::HALF_UP),
                'status' => ! empty($data['place']) ? 'ordered' : 'draft',
            ];

            if ($order) {
                $order->update($attributes);
                $order->items()->delete();
            } else {
                $order = PurchaseOrder::create([...$attributes, 'reference_no' => $this->numbers->next('PO', $branch), 'branch_id' => $branch->id, 'user_id' => $user->id]);
            }

            foreach ($data['items'] as $item) {
                $order->items()->create(['product_variant_id' => $item['variant_id'], 'quantity' => $item['quantity'], 'unit_cost' => $item['unit_cost']]);
            }

            return $order->fresh('items');
        });
    }

    public function setStatus(PurchaseOrder $order, string $status): void
    {
        $allowed = ['ordered' => ['draft'], 'cancelled' => ['draft', 'ordered', 'partially_received']][$status] ?? [];
        if (! in_array($order->status, $allowed, true)) {
            throw new \DomainException("Order {$order->reference_no} is ".PurchaseOrder::STATUSES[$order->status].'.');
        }
        $order->update(['status' => $status]);
    }
}
