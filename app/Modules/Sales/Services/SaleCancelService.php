<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Contacts\Enums\LedgerEntryType;
use App\Modules\Contacts\Services\ContactLedgerService;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Loyalty\Services\LoyaltyService;
use App\Modules\Notifications\Services\Alerts;
use App\Modules\Pos\Models\RegisterSession;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SalePayment;
use App\Modules\Sales\Models\SaleReturn;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a bill made by mistake. Allowed only while its register shift is still open
 * (afterwards the Z-report is final — use a return instead), and only when nothing has
 * been returned or paid against it later.
 */
class SaleCancelService
{
    public function __construct(private StockService $stock, private ContactLedgerService $ledger) {}

    /** Why this sale can't be cancelled, or null when it can. */
    public function blocker(Sale $sale): ?string
    {
        return match (true) {
            $sale->status !== Sale::COMPLETED => 'This bill is already cancelled.',
            $sale->returns()->exists() => 'Items were returned against this bill. Use a return for the rest.',
            $sale->allocations()->exists() => 'A later payment was applied to this bill. Use a return instead.',
            ! $sale->register_session_id || ! RegisterSession::whereKey($sale->register_session_id)->where('status', RegisterSession::OPEN)->exists() => 'The register shift of this bill is closed. Use a return instead.',
            default => null,
        };
    }

    /** @throws \DomainException */
    public function cancel(Sale $sale, string $reason, User $user): Sale
    {
        return DB::transaction(function () use ($sale, $reason, $user) {
            $sale = Sale::with(['items.variant.product', 'payments', 'customer'])->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($why = $this->blocker($sale)) {
                throw new \DomainException($why);
            }

            foreach ($sale->items as $item) {
                $this->stock->move($item->variant, $sale->branch_id, $item->quantity, StockMovementType::SaleCancel,
                    $item->unit_cost, $sale, "Cancelled {$sale->invoice_no}", allowNegative: true);
            }

            // Exchange credit notes used on this bill become usable again.
            SaleReturn::whereIn('id', $sale->payments->where('method', SalePayment::EXCHANGE)->pluck('sale_return_id')->filter())
                ->update(['used_in_sale_id' => null]);

            if (! $sale->customer->is_walk_in) {
                $this->ledger->record($sale->customer, LedgerEntryType::SaleCancelled, credit: $sale->grand_total, reference: $sale,
                    note: "Cancelled {$sale->invoice_no}: {$reason}", branchId: $sale->branch_id);

                // Undo what was settled at the counter (cash/card/UPI/exchange voucher minus change).
                $settled = $sale->payments->whereIn('method', [SalePayment::CASH, SalePayment::CARD, SalePayment::UPI, SalePayment::EXCHANGE, SalePayment::POINTS])
                    ->reduce(fn ($s, $p) => $s->plus($p->amount), BigDecimal::zero())->minus($sale->change_given);
                if ($settled->isPositive()) {
                    $this->ledger->record($sale->customer, LedgerEntryType::Refund, debit: (string) $settled, reference: $sale,
                        note: "Money returned · cancelled {$sale->invoice_no}", branchId: $sale->branch_id);
                } elseif ($settled->isNegative()) {
                    $this->ledger->record($sale->customer, LedgerEntryType::Adjustment, credit: (string) $settled->abs(), reference: $sale,
                        note: "Exchange change reversed · cancelled {$sale->invoice_no}", branchId: $sale->branch_id);
                }
            }

            $sale->update([
                'status' => Sale::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => $reason,
                'due_amount' => 0,
            ]);

            activity()->causedBy($user)->performedOn($sale)->withProperties(['invoice' => $sale->invoice_no, 'total' => $sale->grand_total, 'reason' => $reason])->log('Sale cancelled');
            app(LoyaltyService::class)->afterCancel($sale, $user);
            rescue(fn () => app(Alerts::class)->saleCancelled($sale, $user));

            return $sale;
        });
    }
}
