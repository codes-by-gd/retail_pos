<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\ProductVariant;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Notifications\Services\Alerts;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * THE only place where stock quantities change.
 *
 * Each call locks the stock_levels row, updates quantity and weighted-average cost,
 * and appends a stock_movements ledger row — all inside one DB transaction.
 */
class StockService
{
    /**
     * @param  string|int|float  $quantity  signed: positive adds stock, negative removes it
     * @param  string|int|float|null  $unitCost  cost per unit for incoming stock (null = keep current average)
     * @param  bool|null  $allowNegative  null = use the "pos.allow_negative_stock" setting
     * @return StockMovement|null null when the product does not track stock
     *
     * @throws InsufficientStockException
     */
    public function move(
        ProductVariant|int $variant,
        int $branchId,
        string|int|float $quantity,
        StockMovementType $type,
        string|int|float|null $unitCost = null,
        ?Model $reference = null,
        ?string $note = null,
        ?bool $allowNegative = null,
    ): ?StockMovement {
        $variant = $variant instanceof ProductVariant ? $variant : ProductVariant::withTrashed()->findOrFail($variant);
        $variant->loadMissing('product');

        if (! $variant->product->track_stock) {
            return null;
        }

        $delta = BigDecimal::of((string) $quantity)->toScale(3, RoundingMode::HalfUp);
        if ($delta->isZero()) {
            return null;
        }

        return DB::transaction(function () use ($variant, $branchId, $delta, $type, $unitCost, $reference, $note, $allowNegative) {
            $level = $this->lockLevel($variant->id, $branchId);

            $oldQty = BigDecimal::of($level->quantity);
            $oldAvg = BigDecimal::of($level->avg_cost);
            $newQty = $oldQty->plus($delta);

            $allowNegative ??= (bool) settings('pos.allow_negative_stock');
            if ($delta->isNegative() && $newQty->isNegative() && ! $allowNegative) {
                throw new InsufficientStockException($variant->fullName(), (string) $oldQty->strippedOfTrailingZeros(), (string) $delta->abs()->strippedOfTrailingZeros());
            }

            if ($delta->isPositive()) {
                $cost = $unitCost !== null
                    ? BigDecimal::of((string) $unitCost)
                    : ($oldAvg->isZero() ? BigDecimal::of($variant->purchase_price ?? 0) : $oldAvg);
                $newAvg = $this->weightedAverage($oldQty, $oldAvg, $delta, $cost);
            } else {
                $cost = $oldAvg;      // goods leave at current average cost
                $newAvg = $oldAvg;
            }

            $level->update(['quantity' => (string) $newQty, 'avg_cost' => (string) $newAvg]);

            // Alert once when stock falls to/below the reorder level (not again while it stays there)
            $reorder = BigDecimal::of($variant->reorder_level ?? 0);
            if ($delta->isNegative() && $oldQty->isGreaterThan($reorder) && ! $newQty->isGreaterThan($reorder)) {
                rescue(fn () => app(Alerts::class)->lowStock($variant, $branchId, $newQty));
            }

            return StockMovement::create([
                'product_variant_id' => $variant->id,
                'branch_id' => $branchId,
                'type' => $type,
                'quantity' => (string) $delta,
                'unit_cost' => (string) $cost->toScale(4, RoundingMode::HalfUp),
                'balance_after' => (string) $newQty,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'note' => $note,
                'user_id' => auth()->id(),
            ]);
        });
    }

    /** Current quantity (no lock). */
    public function quantity(int $variantId, int $branchId): string
    {
        return (string) (StockLevel::where(['product_variant_id' => $variantId, 'branch_id' => $branchId])->value('quantity') ?? '0');
    }

    /** Locked quantity — call inside a transaction when the value decides what to write. */
    public function lockedQuantity(int $variantId, int $branchId): string
    {
        return (string) $this->lockLevel($variantId, $branchId)->quantity;
    }

    public function averageCost(int $variantId, int $branchId): string
    {
        return (string) (StockLevel::where(['product_variant_id' => $variantId, 'branch_id' => $branchId])->value('avg_cost') ?? '0');
    }

    private function lockLevel(int $variantId, int $branchId): StockLevel
    {
        $key = ['product_variant_id' => $variantId, 'branch_id' => $branchId];

        $level = StockLevel::where($key)->lockForUpdate()->first();
        if ($level) {
            return $level;
        }

        try {
            StockLevel::create([...$key, 'quantity' => 0, 'avg_cost' => 0]);
        } catch (UniqueConstraintViolationException) {
            // created concurrently
        }

        return StockLevel::where($key)->lockForUpdate()->firstOrFail();
    }

    /**
     * Weighted average after receiving $inQty at $inCost.
     * If existing stock is zero or negative, the incoming cost becomes the new average.
     */
    private function weightedAverage(BigDecimal $oldQty, BigDecimal $oldAvg, BigDecimal $inQty, BigDecimal $inCost): BigDecimal
    {
        if (! $oldQty->isPositive()) {
            return $inCost->toScale(4, RoundingMode::HalfUp);
        }

        return $oldQty->multipliedBy($oldAvg)
            ->plus($inQty->multipliedBy($inCost))
            ->dividedBy($oldQty->plus($inQty), 4, RoundingMode::HalfUp);
    }
}
