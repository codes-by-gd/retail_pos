<?php

namespace App\Modules\Purchases\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contacts\Http\Controllers\ContactPaymentController;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\ContactPayment;
use App\Modules\Inventory\Http\Controllers\VariantSearchController;
use App\Modules\Inventory\Models\ProductVariant;
use App\Modules\Pos\Models\RegisterSession;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Models\PurchaseReturn;
use App\Modules\Purchases\Services\PurchaseReturnService;
use App\Modules\Purchases\Services\PurchaseService;
use App\Support\AmountInWords;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:50'],
            'payment_status' => ['nullable', Rule::in(['paid', 'partial', 'due'])],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
        ]);

        $query = Purchase::where('branch_id', current_branch()->id())
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('reference_no', 'like', "%{$s}%")
                ->orWhere('supplier_invoice_no', 'like', "%{$s}%")
                ->orWhereHas('supplier', fn ($c) => $c->where('name', 'like', "%{$s}%")->orWhere('company_name', 'like', "%{$s}%"))))
            ->when($filters['payment_status'] ?? null, fn ($q, $s) => $q->where('payment_status', $s))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('received_date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('received_date', '<=', $d));

        $totals = (clone $query)->selectRaw('COUNT(*) as bills, COALESCE(SUM(grand_total), 0) as total, COALESCE(SUM(due_amount), 0) as due')->first();

        return Inertia::render('Purchases/Index', [
            'purchases' => $query->with('supplier:id,name,company_name')->withCount('items')->latest('received_date')->latest('id')
                ->paginate(25)->withQueryString()
                ->through(fn (Purchase $p) => [
                    'id' => $p->id, 'reference_no' => $p->reference_no, 'bill' => $p->supplier_invoice_no, 'date' => $p->received_date->toDateString(),
                    'supplier' => $p->supplier->company_name ?: $p->supplier->name, 'items_count' => $p->items_count,
                    'grand_total' => (float) $p->grand_total, 'due_amount' => (float) $p->due_amount, 'payment_status' => $p->payment_status,
                ]),
            'filters' => $filters,
            'totals' => ['bills' => (int) $totals->bills, 'total' => (float) $totals->total, 'due' => (float) $totals->due],
        ]);
    }

    /** Receive goods; ?order= pre-fills the outstanding lines of a purchase order. */
    public function create(Request $request): Response
    {
        $prefill = null;
        if ($orderId = $request->integer('order')) {
            $order = PurchaseOrder::with(['items', 'supplier'])->where('branch_id', current_branch()->id())->findOrFail($orderId);
            abort_unless($order->isOpen(), 403, 'This order is not open for receiving.');
            $variants = ProductVariant::withTrashed()->with(VariantSearchController::WITH)->whereIn('id', $order->items->pluck('product_variant_id'))->get()->keyBy('id');
            $prefill = [
                'purchase_order_id' => $order->id,
                'reference_no' => $order->reference_no,
                'supplier' => Contact::forPos($order->supplier),
                'items' => $order->items->filter(fn ($i) => (float) $i->received_quantity < (float) $i->quantity)->values()->map(fn ($i) => [
                    ...VariantSearchController::present($variants[$i->product_variant_id]),
                    'purchase_order_item_id' => $i->id,
                    'quantity' => (float) $i->quantity - (float) $i->received_quantity,
                    'unit_cost' => (float) $i->unit_cost,
                ]),
            ];
        }

        return Inertia::render('Purchases/Form', [
            'today' => now()->toDateString(),
            'prefill' => $prefill,
            'branchState' => current_branch()->get()->state_code,
            'roundOff' => (bool) settings('tax.round_off'),
            'registers' => ContactPaymentController::openSessions(),
            'paymentMethods' => collect(ContactPayment::METHODS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
        ]);
    }

    public function store(Request $request, PurchaseService $purchases): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'purchase_order_id' => ['nullable', 'integer'],
            'supplier_invoice_no' => ['nullable', 'string', 'max:50'],
            'supplier_invoice_date' => ['nullable', 'date', 'before_or_equal:today'],
            'received_date' => ['required', 'date', 'before_or_equal:today'],
            'other_charges' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'update_cost_price' => ['boolean'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.variant_id' => ['required', 'integer'],
            'items.*.purchase_order_item_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'items.*.discount_type' => ['nullable', Rule::in(['percent', 'amount'])],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
            'payment' => ['nullable', 'array'],
            'payment.amount' => ['nullable', 'numeric', 'min:0'],
            'payment.method' => ['required_with:payment.amount', 'nullable', Rule::in(array_keys(ContactPayment::METHODS))],
            'payment.reference' => ['nullable', 'string', 'max:100'],
            'payment.register_session_id' => ['nullable', 'integer'],
        ], ['supplier_id.required' => 'Choose a supplier.', 'items.required' => 'Add the items received.']);

        if (! empty($data['payment']['amount']) && ! $request->user()->can('payments.manage')) {
            abort(403, 'You are not allowed to record payments.');
        }

        $session = ! empty($data['payment']['register_session_id'])
            ? RegisterSession::whereKey($data['payment']['register_session_id'])->where('branch_id', current_branch()->id())->first() : null;
        $purchase = $purchases->create($data, current_branch()->get(), $request->user(), $session);

        return to_route('purchases.show', $purchase)->with('success', "Purchase {$purchase->reference_no} saved and stock updated.");
    }

    public function show(Purchase $purchase): Response
    {
        $this->authorizeBranch($purchase);
        $purchase->load(['items', 'supplier', 'user:id,name', 'order:id,reference_no', 'returns.user:id,name', 'allocations.payment']);

        return Inertia::render('Purchases/Show', [
            'purchase' => [
                ...$purchase->only(['id', 'reference_no', 'supplier_invoice_no', 'is_inter_state', 'payment_status', 'notes']),
                ...collect(['subtotal', 'discount_total', 'taxable_total', 'cgst_total', 'sgst_total', 'igst_total', 'cess_total', 'other_charges', 'round_off',
                    'grand_total', 'paid_amount', 'due_amount'])->mapWithKeys(fn ($f) => [$f => (float) $purchase->{$f}])->all(),
                'supplier_invoice_date' => $purchase->supplier_invoice_date?->toDateString(),
                'received_date' => $purchase->received_date->toDateString(),
                'supplier' => ['id' => $purchase->supplier->id, 'name' => $purchase->supplier->company_name ?: $purchase->supplier->name, 'gstin' => $purchase->supplier->gstin],
                'order' => $purchase->order ? ['id' => $purchase->order->id, 'reference_no' => $purchase->order->reference_no] : null,
                'user' => $purchase->user->name,
                'items' => $purchase->items->map(fn ($i) => [
                    'id' => $i->id, 'variant_id' => $i->product_variant_id, 'name' => $i->product_name, 'variant' => $i->variant_name, 'sku' => $i->sku,
                    'unit' => $i->unit, 'quantity' => (float) $i->quantity, 'returned' => (float) $i->returned_quantity, 'unit_cost' => (float) $i->unit_cost,
                    'discount' => (float) $i->line_discount, 'taxable' => (float) $i->taxable_value, 'gst_rate' => (float) $i->gst_rate,
                    'tax' => (float) $i->cgst + (float) $i->sgst + (float) $i->igst + (float) $i->cess, 'total' => (float) $i->line_total,
                    'landed' => (float) $i->landed_unit_cost,
                ]),
                'returns' => $purchase->returns->map(fn ($r) => ['id' => $r->id, 'reference_no' => $r->reference_no, 'date' => $r->date->toDateString(),
                    'total' => (float) $r->total, 'reason' => $r->reason, 'user' => $r->user->name, 'print_url' => route('purchase-returns.print', $r)]),
                'payments' => $purchase->allocations->map(fn ($a) => ['reference_no' => $a->payment->reference_no, 'date' => $a->payment->date->toDateString(),
                    'method' => ContactPayment::METHODS[$a->payment->method], 'amount' => (float) $a->amount, 'print_url' => route('payments.print', $a->contact_payment_id)]),
            ],
            'registers' => ContactPaymentController::openSessions(),
            'paymentMethods' => collect(ContactPayment::METHODS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
        ]);
    }

    public function storeReturn(Request $request, Purchase $purchase, PurchaseReturnService $returns): RedirectResponse
    {
        $this->authorizeBranch($purchase);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $return = $returns->create($purchase, $data['items'], $data['reason'] ?? null, $request->user());

        return back()->with('success', "Debit note {$return->reference_no} created; stock reduced.");
    }

    public function printReturn(PurchaseReturn $purchaseReturn): View
    {
        abort_unless(current_branch()->allowedFor(auth()->user())->contains('id', $purchaseReturn->branch_id), 403);
        $purchaseReturn->load(['items.purchaseItem', 'purchase', 'supplier', 'branch', 'user:id,name']);

        return view('print.debit-note', [
            'return' => $purchaseReturn,
            'business' => settings()->group('business'),
            'gstin' => $purchaseReturn->branch->gstin ?: settings('business.gstin'),
            'words' => AmountInWords::rupees($purchaseReturn->total),
        ]);
    }

    private function authorizeBranch(Purchase $p): void
    {
        abort_unless(current_branch()->allowedFor(auth()->user())->contains('id', $p->branch_id), 403);
    }
}
