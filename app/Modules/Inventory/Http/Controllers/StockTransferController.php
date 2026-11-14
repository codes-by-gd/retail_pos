<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Services\StockTransferService;
use App\Modules\Settings\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StockTransferController extends Controller
{
    public function __construct(private StockTransferService $transfers) {}

    public function index(Request $request): Response
    {
        $branchId = current_branch()->id();
        $direction = $request->input('direction');

        $transfers = StockTransfer::query()
            ->where(fn ($q) => match ($direction) {
                'incoming' => $q->where('to_branch_id', $branchId),
                'outgoing' => $q->where('from_branch_id', $branchId),
                default => $q->where('to_branch_id', $branchId)->orWhere('from_branch_id', $branchId),
            })
            ->with(['fromBranch:id,name,code', 'toBranch:id,name,code', 'user:id,name'])
            ->withCount('items')
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (StockTransfer $t) => [
                'id' => $t->id,
                'reference_no' => $t->reference_no,
                'from' => $t->fromBranch->name,
                'to' => $t->toBranch->name,
                'incoming' => $t->to_branch_id === $branchId,
                'status' => $t->status,
                'items_count' => $t->items_count,
                'user' => $t->user->name,
                'created_at' => $t->created_at->toIso8601String(),
            ]);

        return Inertia::render('Inventory/Transfers/Index', [
            'transfers' => $transfers,
            'filters' => ['direction' => $direction],
            'pendingIncoming' => StockTransfer::where('to_branch_id', $branchId)->where('status', StockTransfer::IN_TRANSIT)->count(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/Transfers/Create', [
            'destinations' => Branch::active()->whereKeyNot(current_branch()->id())->orderBy('name')
                ->get()->map(fn ($b) => ['value' => $b->id, 'label' => $b->name]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $from = current_branch()->get();

        $data = $request->validate([
            'to_branch_id' => ['required', Rule::exists('branches', 'id')->where('is_active', true)->whereNull('deleted_at'), Rule::notIn([$from->id])],
            'note' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.product_variant_id' => ['required', 'integer', 'distinct', Rule::exists('product_variants', 'id')->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ], [
            'to_branch_id.not_in' => 'Choose a different destination branch.',
            'items.*.product_variant_id.distinct' => 'Each item can be added only once.',
        ]);

        $transfer = $this->transfers->send($from, Branch::findOrFail($data['to_branch_id']), $data['items'], $request->user(), $data['note'] ?? null);

        return to_route('stock-transfers.show', $transfer)->with('success', "Transfer {$transfer->reference_no} sent.");
    }

    public function show(StockTransfer $stockTransfer): Response
    {
        $this->authorizeBranch($stockTransfer);
        $stockTransfer->load(['fromBranch:id,name', 'toBranch:id,name', 'user:id,name', 'receiver:id,name', 'items.variant.product:id,name,unit_id', 'items.variant.product.unit:id,short_name']);
        $branchId = current_branch()->id();

        return Inertia::render('Inventory/Transfers/Show', [
            'transfer' => [
                ...$stockTransfer->only(['id', 'reference_no', 'status', 'note']),
                'from' => $stockTransfer->fromBranch->name,
                'to' => $stockTransfer->toBranch->name,
                'user' => $stockTransfer->user->name,
                'receiver' => $stockTransfer->receiver?->name,
                'created_at' => $stockTransfer->created_at->toIso8601String(),
                'received_at' => $stockTransfer->received_at?->toIso8601String(),
                'items' => $stockTransfer->items->map(fn ($i) => [
                    'id' => $i->id,
                    'name' => $i->variant->fullName(),
                    'sku' => $i->variant->sku,
                    'unit' => $i->variant->product->unit->short_name,
                    'quantity' => (float) $i->quantity,
                    'unit_cost' => (float) $i->unit_cost,
                ]),
            ],
            // Receive at the destination; cancel from the source.
            'canReceive' => $stockTransfer->status === StockTransfer::IN_TRANSIT && $stockTransfer->to_branch_id === $branchId,
            'canCancel' => $stockTransfer->status === StockTransfer::IN_TRANSIT && $stockTransfer->from_branch_id === $branchId,
        ]);
    }

    public function receive(Request $request, StockTransfer $stockTransfer): RedirectResponse
    {
        abort_unless($stockTransfer->to_branch_id === current_branch()->id(), 403, 'Switch to the receiving branch to receive this transfer.');
        $this->transfers->receive($stockTransfer, $request->user());

        return back()->with('success', "Transfer {$stockTransfer->reference_no} received.");
    }

    public function cancel(StockTransfer $stockTransfer): RedirectResponse
    {
        abort_unless($stockTransfer->from_branch_id === current_branch()->id(), 403, 'Only the sending branch can cancel a transfer.');
        $this->transfers->cancel($stockTransfer);

        return back()->with('success', "Transfer {$stockTransfer->reference_no} cancelled; stock returned.");
    }

    private function authorizeBranch(StockTransfer $t): void
    {
        $allowed = current_branch()->allowedFor(auth()->user())->pluck('id');
        abort_unless($allowed->contains($t->from_branch_id) || $allowed->contains($t->to_branch_id), 403);
    }
}
