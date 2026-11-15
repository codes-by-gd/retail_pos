<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Inventory\Http\Controllers\VariantSearchController;
use App\Modules\Inventory\Models\ProductVariant;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Services\QuotationService;
use App\Support\AmountInWords;
use App\Support\Gst\IndianStates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;

class QuotationController extends Controller
{
    public function __construct(private QuotationService $quotations) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:50'], 'status' => ['nullable', Rule::in(array_keys(Quotation::STATUSES))]]);

        $quotations = Quotation::where('branch_id', current_branch()->id())
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('reference_no', 'like', "%{$s}%")->orWhere('customer_name', 'like', "%{$s}%")))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->with('user:id,name')->withCount('items')->latest('id')->paginate(25)->withQueryString()
            ->through(fn (Quotation $q) => [
                'id' => $q->id, 'reference_no' => $q->reference_no, 'date' => $q->date->toDateString(), 'valid_until' => $q->valid_until?->toDateString(),
                'customer' => $q->customer_name, 'items_count' => $q->items_count, 'grand_total' => (float) $q->grand_total,
                'status' => $q->status, 'expired' => $q->isExpired(), 'user' => $q->user->name,
            ]);

        return Inertia::render('Quotations/Index', ['quotations' => $quotations, 'filters' => $filters, 'statuses' => Quotation::STATUSES]);
    }

    public function create(): Response
    {
        return Inertia::render('Quotations/Form', ['today' => now()->toDateString(), 'defaultValidity' => now()->addDays(15)->toDateString()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $q = $this->quotations->save($this->validated($request), current_branch()->get(), $request->user());

        return to_route('quotations.show', $q)->with('success', "Quotation {$q->reference_no} saved.");
    }

    public function show(Quotation $quotation): Response
    {
        $this->authorizeBranch($quotation);
        $quotation->load(['items', 'user:id,name', 'customer:id,code']);

        return Inertia::render('Quotations/Show', [
            'quotation' => [
                ...$quotation->only(['id', 'reference_no', 'customer_id', 'customer_name', 'customer_phone', 'status', 'notes', 'is_inter_state', 'bill_discount_type']),
                ...collect(['subtotal', 'line_discount_total', 'bill_discount_value', 'bill_discount_total', 'taxable_total', 'cgst_total', 'sgst_total', 'igst_total', 'round_off', 'grand_total'])
                    ->mapWithKeys(fn ($f) => [$f => (float) $quotation->{$f}])->all(),
                'date' => $quotation->date->toDateString(),
                'valid_until' => $quotation->valid_until?->toDateString(),
                'expired' => $quotation->isExpired(),
                'editable' => $quotation->isEditable(),
                'user' => $quotation->user->name,
                'items' => $quotation->items->map(fn ($i) => [
                    'id' => $i->id, 'name' => $i->product_name, 'variant' => $i->variant_name, 'sku' => $i->sku, 'unit' => $i->unit,
                    'quantity' => (float) $i->quantity, 'unit_price' => (float) $i->unit_price,
                    'discount' => (float) $i->line_discount + (float) $i->bill_discount_share, 'gst_rate' => (float) $i->gst_rate,
                    'taxable' => (float) $i->taxable_value, 'total' => (float) $i->line_total,
                ]),
            ],
            'statuses' => Quotation::STATUSES,
        ]);
    }

    public function edit(Quotation $quotation): Response
    {
        $this->authorizeBranch($quotation);
        abort_unless($quotation->isEditable(), 403, 'This quotation can no longer be changed.');
        $quotation->load('items', 'customer.group');

        $variants = ProductVariant::withTrashed()->with(VariantSearchController::WITH)->whereIn('id', $quotation->items->pluck('product_variant_id'))->get()->keyBy('id');

        return Inertia::render('Quotations/Form', [
            'today' => now()->toDateString(),
            'defaultValidity' => now()->addDays(15)->toDateString(),
            'quotation' => [
                'id' => $quotation->id,
                'reference_no' => $quotation->reference_no,
                'customer' => $quotation->customer ? Contact::forPos($quotation->customer) : null,
                'customer_name' => $quotation->customer_id ? '' : $quotation->customer_name,
                'customer_phone' => $quotation->customer_id ? '' : $quotation->customer_phone,
                'date' => $quotation->date->toDateString(),
                'valid_until' => $quotation->valid_until?->toDateString(),
                'bill_discount_type' => $quotation->bill_discount_type ?? 'percent',
                'bill_discount_value' => (float) $quotation->bill_discount_value,
                'notes' => $quotation->notes,
                'items' => $quotation->items->filter(fn ($i) => $variants->has($i->product_variant_id))->values()->map(fn ($i) => [
                    ...VariantSearchController::present($variants[$i->product_variant_id]),
                    'quantity' => (float) $i->quantity, 'discount_type' => $i->discount_type ?? 'percent', 'discount_value' => (float) $i->discount_value,
                ]),
            ],
        ]);
    }

    public function update(Request $request, Quotation $quotation): RedirectResponse
    {
        $this->authorizeBranch($quotation);
        $this->quotations->save($this->validated($request), current_branch()->get(), $request->user(), $quotation);

        return to_route('quotations.show', $quotation)->with('success', 'Quotation updated.');
    }

    public function status(Request $request, Quotation $quotation): RedirectResponse
    {
        $this->authorizeBranch($quotation);
        $data = $request->validate(['status' => ['required', Rule::in(['sent', 'accepted', 'rejected'])]]);
        if ($quotation->status === 'converted') {
            return back()->with('error', 'This quotation was already converted to a bill.');
        }
        $quotation->update(['status' => $data['status']]);

        return back()->with('success', 'Marked as '.strtolower(Quotation::STATUSES[$data['status']]).'.');
    }

    public function convert(Request $request, Quotation $quotation): RedirectResponse
    {
        $this->authorizeBranch($quotation);
        $cart = $this->quotations->convert($quotation, $request->user(), current_branch()->get());

        return to_route('pos.terminal', ['recall' => $cart->id])->with('success', "Quotation {$quotation->reference_no} loaded into the POS.");
    }

    public function destroy(Quotation $quotation): RedirectResponse
    {
        $this->authorizeBranch($quotation);
        if ($quotation->status === 'converted') {
            return back()->with('error', 'A converted quotation is kept for the record.');
        }
        $quotation->delete();

        return to_route('quotations.index')->with('success', "Quotation {$quotation->reference_no} deleted.");
    }

    public function print(Quotation $quotation): View
    {
        $this->authorizeBranch($quotation);
        $quotation->load(['items', 'branch', 'user:id,name', 'customer']);

        return view('print.quotation', [
            'q' => $quotation,
            'business' => settings()->group('business'),
            'invoice' => settings()->group('invoice'),
            'branch' => $quotation->branch,
            'gstin' => $quotation->branch->gstin ?: settings('business.gstin'),
            'words' => AmountInWords::rupees($quotation->grand_total),
            'placeOfSupply' => IndianStates::name($quotation->place_of_supply).' ('.$quotation->place_of_supply.')',
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'customer_id' => ['nullable', 'integer'],
            'customer_name' => ['required_without:customer_id', 'nullable', 'string', 'max:150'],
            'customer_phone' => ['nullable', 'string', 'max:15'],
            'date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:date'],
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.variant_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'items.*.discount_type' => ['nullable', Rule::in(['percent', 'amount'])],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
            'bill_discount_type' => ['nullable', Rule::in(['percent', 'amount'])],
            'bill_discount_value' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['customer_name.required_without' => 'Choose a customer or type a name.', 'items.required' => 'Add at least one item.']);
    }

    private function authorizeBranch(Quotation $q): void
    {
        abort_unless(current_branch()->allowedFor(auth()->user())->contains('id', $q->branch_id), 403);
    }
}
