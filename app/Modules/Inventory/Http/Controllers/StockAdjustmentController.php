<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Services\StockAdjustmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StockAdjustmentController extends Controller
{
    public function __construct(private StockAdjustmentService $adjustments) {}

    public function index(): Response
    {
        $adjustments = StockAdjustment::query()
            ->where('branch_id', current_branch()->id())
            ->with('user:id,name')
            ->withCount('items')
            ->latest('id')
            ->paginate(20)
            ->through(fn (StockAdjustment $a) => [
                'id' => $a->id,
                'reference_no' => $a->reference_no,
                'date' => $a->date->toDateString(),
                'reason' => StockAdjustment::REASONS[$a->reason] ?? $a->reason,
                'items_count' => $a->items_count,
                'user' => $a->user->name,
                'note' => $a->note,
            ]);

        return Inertia::render('Inventory/Adjustments/Index', ['adjustments' => $adjustments]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/Adjustments/Create', [
            'reasons' => collect(StockAdjustment::REASONS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', Rule::in(array_keys(StockAdjustment::REASONS))],
            'mode' => ['required', Rule::in(['change', 'count'])],
            'note' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.product_variant_id' => ['required', 'integer', 'distinct', Rule::exists('product_variants', 'id')->whereNull('deleted_at')],
            'items.*.quantity' => ['required_if:mode,change', 'nullable', 'numeric', 'not_in:0'],
            'items.*.counted' => ['required_if:mode,count', 'nullable', 'numeric', 'min:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ], [
            'items.*.product_variant_id.distinct' => 'Each item can be added only once.',
            'items.*.quantity.not_in' => 'Quantity cannot be zero.',
        ]);

        $adjustment = $this->adjustments->create(current_branch()->get(), $data, $request->user());

        return to_route('stock-adjustments.show', $adjustment)->with('success', "Adjustment {$adjustment->reference_no} saved.");
    }

    public function show(StockAdjustment $stockAdjustment): Response
    {
        abort_unless(current_branch()->allowedFor(auth()->user())->contains('id', $stockAdjustment->branch_id), 403);

        $stockAdjustment->load(['user:id,name', 'branch:id,name', 'items.variant.product:id,name,unit_id', 'items.variant.product.unit:id,short_name']);

        return Inertia::render('Inventory/Adjustments/Show', [
            'adjustment' => [
                ...$stockAdjustment->only(['id', 'reference_no', 'note']),
                'date' => $stockAdjustment->date->toDateString(),
                'reason' => StockAdjustment::REASONS[$stockAdjustment->reason] ?? $stockAdjustment->reason,
                'branch' => $stockAdjustment->branch->name,
                'user' => $stockAdjustment->user->name,
                'created_at' => $stockAdjustment->created_at->toIso8601String(),
                'items' => $stockAdjustment->items->map(fn ($i) => [
                    'id' => $i->id,
                    'name' => $i->variant->fullName(),
                    'sku' => $i->variant->sku,
                    'unit' => $i->variant->product->unit->short_name,
                    'quantity' => (float) $i->quantity,
                    'unit_cost' => (float) $i->unit_cost,
                    'value' => round((float) $i->quantity * (float) $i->unit_cost, 2),
                ]),
            ],
        ]);
    }
}
