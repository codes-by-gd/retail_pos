<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Services\DocumentNumberService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class StockAdjustmentService
{
    public function __construct(private StockService $stock, private DocumentNumberService $numbers) {}

    /**
     * $data: date, reason, note?, mode ('change' | 'count'),
     *        items: [{ product_variant_id, quantity (signed, mode=change) | counted (mode=count), unit_cost? }]
     *
     * In "count" mode the difference between the counted and current quantity is posted,
     * read under a row lock so concurrent sales are accounted for.
     *
     * @throws InsufficientStockException
     */
    public function create(Branch $branch, array $data, User $user): StockAdjustment
    {
        return DB::transaction(function () use ($branch, $data, $user) {
            $adjustment = StockAdjustment::create([
                'reference_no' => $this->numbers->next('ADJ', $branch),
                'branch_id' => $branch->id,
                'date' => $data['date'],
                'reason' => $data['reason'],
                'note' => $data['note'] ?? null,
                'user_id' => $user->id,
            ]);

            foreach ($data['items'] as $item) {
                $variantId = (int) $item['product_variant_id'];

                $delta = ($data['mode'] ?? 'change') === 'count'
                    ? BigDecimal::of((string) $item['counted'])->minus($this->stock->lockedQuantity($variantId, $branch->id))
                    : BigDecimal::of((string) $item['quantity']);

                if ($delta->isZero()) {
                    continue;
                }

                $movement = $this->stock->move(
                    $variantId,
                    $branch->id,
                    (string) $delta,
                    $delta->isPositive() ? StockMovementType::AdjustmentIn : StockMovementType::AdjustmentOut,
                    $delta->isPositive() ? ($item['unit_cost'] ?? null) : null,
                    $adjustment,
                    StockAdjustment::REASONS[$data['reason']] ?? $data['reason'],
                    allowNegative: false,
                );

                $adjustment->items()->create([
                    'product_variant_id' => $variantId,
                    'quantity' => (string) $delta,
                    'unit_cost' => $movement?->unit_cost ?? 0,
                ]);
            }

            if ($adjustment->items()->doesntExist()) {
                throw new \DomainException('Nothing to adjust — all quantities already match.');
            }

            return $adjustment;
        });
    }
}
