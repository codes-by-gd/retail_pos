<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Services\DocumentNumberService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Two-step transfer: stock leaves the source branch when sent (in transit),
 * and arrives at the destination when received there. Cancel returns it to the source.
 */
class StockTransferService
{
    public function __construct(private StockService $stock, private DocumentNumberService $numbers) {}

    /** @throws InsufficientStockException */
    public function send(Branch $from, Branch $to, array $items, User $user, ?string $note = null): StockTransfer
    {
        if ($from->is($to)) {
            throw new \DomainException('Choose a different destination branch.');
        }

        return DB::transaction(function () use ($from, $to, $items, $user, $note) {
            $transfer = StockTransfer::create([
                'reference_no' => $this->numbers->next('TRF', $from),
                'from_branch_id' => $from->id,
                'to_branch_id' => $to->id,
                'status' => StockTransfer::IN_TRANSIT,
                'note' => $note,
                'user_id' => $user->id,
            ]);

            foreach ($items as $item) {
                $qty = BigDecimal::of((string) $item['quantity']);
                $movement = $this->stock->move(
                    (int) $item['product_variant_id'], $from->id, (string) $qty->negated(),
                    StockMovementType::TransferOut, reference: $transfer, note: "To {$to->name}", allowNegative: false,
                );

                $transfer->items()->create([
                    'product_variant_id' => $item['product_variant_id'],
                    'quantity' => (string) $qty,
                    'unit_cost' => $movement?->unit_cost ?? 0,
                ]);
            }

            return $transfer;
        });
    }

    public function receive(StockTransfer $transfer, User $user): void
    {
        DB::transaction(function () use ($transfer, $user) {
            $transfer = StockTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            $this->ensureInTransit($transfer);

            foreach ($transfer->items()->with('variant.product')->get() as $item) {
                $this->stock->move(
                    $item->variant, $transfer->to_branch_id, $item->quantity, StockMovementType::TransferIn,
                    $item->unit_cost, $transfer, "From {$transfer->fromBranch->name}",
                );
            }

            $transfer->update(['status' => StockTransfer::RECEIVED, 'received_by' => $user->id, 'received_at' => now()]);
        });
    }

    public function cancel(StockTransfer $transfer): void
    {
        DB::transaction(function () use ($transfer) {
            $transfer = StockTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            $this->ensureInTransit($transfer);

            foreach ($transfer->items()->with('variant.product')->get() as $item) {
                $this->stock->move(
                    $item->variant, $transfer->from_branch_id, $item->quantity, StockMovementType::TransferCancel,
                    $item->unit_cost, $transfer, 'Transfer cancelled',
                );
            }

            $transfer->update(['status' => StockTransfer::CANCELLED]);
        });
    }

    private function ensureInTransit(StockTransfer $transfer): void
    {
        if ($transfer->status !== StockTransfer::IN_TRANSIT) {
            throw new \DomainException("Transfer {$transfer->reference_no} is already {$transfer->status}.");
        }
    }
}
