<?php

namespace App\Modules\Purchases\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Inventory\Http\Controllers\VariantSearchController;
use App\Modules\Inventory\Models\ProductVariant;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Services\PurchaseOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    public function __construct(private PurchaseOrderService $orders) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:50'], 'status' => ['nullable', Rule::in(array_keys(PurchaseOrder::STATUSES))]]);

        return Inertia::render('Purchases/Orders/Index', [
            'orders' => PurchaseOrder::where('branch_id', current_branch()->id())
                ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('reference_no', 'like', "%{$s}%")
                    ->orWhereHas('supplier', fn ($c) => $c->where('name', 'like', "%{$s}%")->orWhere('company_name', 'like', "%{$s}%"))))
                ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->with('supplier:id,name,company_name')->withCount('items')->latest('id')->paginate(25)->withQueryString()
                ->through(fn (PurchaseOrder $o) => [
                    'id' => $o->id, 'reference_no' => $o->reference_no, 'order_date' => $o->order_date->toDateString(),
                    'expected_date' => $o->expected_date?->toDateString(), 'supplier' => $o->supplier->company_name ?: $o->supplier->name,
                    'items_count' => $o->items_count, 'estimated_total' => (float) $o->estimated_total, 'status' => $o->status,
                ]),
            'filters' => $filters,
            'statuses' => PurchaseOrder::STATUSES,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Purchases/Orders/Form', ['today' => now()->toDateString()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $order = $this->orders->save($this->validated($request), current_branch()->get(), $request->user());

        return to_route('purchase-orders.show', $order)->with('success', "Purchase order {$order->reference_no} saved.");
    }

    public function show(PurchaseOrder $purchaseOrder): Response
    {
        $this->authorizeBranch($purchaseOrder);
        $purchaseOrder->load(['items.variant.product:id,name,unit_id', 'items.variant.product.unit:id,short_name', 'supplier', 'user:id,name',
            'purchases:id,purchase_order_id,reference_no,supplier_invoice_no,received_date,grand_total']);

        return Inertia::render('Purchases/Orders/Show', [
            'order' => [
                ...$purchaseOrder->only(['id', 'reference_no', 'status', 'notes']),
                'order_date' => $purchaseOrder->order_date->toDateString(),
                'expected_date' => $purchaseOrder->expected_date?->toDateString(),
                'estimated_total' => (float) $purchaseOrder->estimated_total,
                'supplier' => ['id' => $purchaseOrder->supplier->id, 'name' => $purchaseOrder->supplier->company_name ?: $purchaseOrder->supplier->name,
                    'phone' => $purchaseOrder->supplier->phone],
                'user' => $purchaseOrder->user->name,
                'items' => $purchaseOrder->items->map(fn ($i) => [
                    'id' => $i->id, 'name' => $i->variant->fullName(), 'sku' => $i->variant->sku, 'unit' => $i->variant->product->unit->short_name,
                    'quantity' => (float) $i->quantity, 'received' => (float) $i->received_quantity, 'unit_cost' => (float) $i->unit_cost,
                ]),
                'purchases' => $purchaseOrder->purchases->map(fn ($p) => ['id' => $p->id, 'reference_no' => $p->reference_no,
                    'bill' => $p->supplier_invoice_no, 'date' => $p->received_date->toDateString(), 'total' => (float) $p->grand_total]),
            ],
            'statuses' => PurchaseOrder::STATUSES,
        ]);
    }

    public function edit(PurchaseOrder $purchaseOrder): Response
    {
        $this->authorizeBranch($purchaseOrder);
        abort_unless($purchaseOrder->status === 'draft', 403, 'Only draft orders can be edited.');
        $purchaseOrder->load('items', 'supplier');
        $variants = ProductVariant::withTrashed()->with(VariantSearchController::WITH)->whereIn('id', $purchaseOrder->items->pluck('product_variant_id'))->get()->keyBy('id');

        return Inertia::render('Purchases/Orders/Form', [
            'today' => now()->toDateString(),
            'order' => [
                'id' => $purchaseOrder->id, 'reference_no' => $purchaseOrder->reference_no,
                'supplier' => Contact::forPos($purchaseOrder->supplier),
                'order_date' => $purchaseOrder->order_date->toDateString(), 'expected_date' => $purchaseOrder->expected_date?->toDateString(),
                'notes' => $purchaseOrder->notes,
                'items' => $purchaseOrder->items->map(fn ($i) => [...VariantSearchController::present($variants[$i->product_variant_id]),
                    'quantity' => (float) $i->quantity, 'unit_cost' => (float) $i->unit_cost]),
            ],
        ]);
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorizeBranch($purchaseOrder);
        $this->orders->save($this->validated($request), current_branch()->get(), $request->user(), $purchaseOrder);

        return to_route('purchase-orders.show', $purchaseOrder)->with('success', 'Purchase order updated.');
    }

    public function status(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorizeBranch($purchaseOrder);
        $data = $request->validate(['status' => ['required', Rule::in(['ordered', 'cancelled'])]]);
        $this->orders->setStatus($purchaseOrder, $data['status']);

        return back()->with('success', $data['status'] === 'ordered' ? 'Order placed.' : 'Order cancelled.');
    }

    public function print(PurchaseOrder $purchaseOrder): View
    {
        $this->authorizeBranch($purchaseOrder);
        $purchaseOrder->load(['items.variant.product.unit', 'supplier', 'branch', 'user:id,name']);

        return view('print.purchase-order', [
            'order' => $purchaseOrder,
            'business' => settings()->group('business'),
            'gstin' => $purchaseOrder->branch->gstin ?: settings('business.gstin'),
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'supplier_id' => ['required', 'integer'],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'place' => ['boolean'],
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.variant_id' => ['required', 'integer', 'distinct', Rule::exists('product_variants', 'id')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999'],
        ], ['supplier_id.required' => 'Choose a supplier.', 'items.required' => 'Add at least one item.', 'items.*.variant_id.distinct' => 'Each item only once.']);
    }

    private function authorizeBranch(PurchaseOrder $o): void
    {
        abort_unless(current_branch()->allowedFor(auth()->user())->contains('id', $o->branch_id), 403);
    }
}
