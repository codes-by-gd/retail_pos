<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SalePayment;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Sales list & invoice view. Cancellation and fuller order management come in Phase 4. */
class SaleController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:50'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'user_id' => ['nullable', 'integer'],
            'payment_status' => ['nullable', 'in:paid,partial,due'],
        ]);
        $branchId = current_branch()->id();

        $query = Sale::query()->where('branch_id', $branchId)
            ->when($filters['search'] ?? null, function ($q, $s) {
                $digits = preg_replace('/\D+/', '', $s);
                $q->where(fn ($q) => $q->where('invoice_no', 'like', '%'.strtoupper($s).'%')->orWhere('customer_name', 'like', "%{$s}%")
                    ->when(strlen($digits) >= 4, fn ($q) => $q->orWhere('customer_phone', 'like', "%{$digits}%")));
            })
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('sold_at', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('sold_at', '<', now()->parse($d)->addDay()))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['payment_status'] ?? null, fn ($q, $s) => $q->where('payment_status', $s));

        $summary = (clone $query)->where('status', Sale::COMPLETED)->selectRaw('COUNT(*) as bills, COALESCE(SUM(grand_total), 0) as total')->first();

        $sales = $query->with(['cashier:id,name', 'payments:id,sale_id,method'])->withCount('items')->latest('sold_at')->latest('id')
            ->paginate(25)->withQueryString()
            ->through(fn (Sale $s) => [
                'id' => $s->id,
                'invoice_no' => $s->invoice_no,
                'sold_at' => $s->sold_at->toIso8601String(),
                'customer' => $s->customer_name,
                'phone' => $s->customer_phone,
                'items_count' => $s->items_count,
                'grand_total' => (float) $s->grand_total,
                'methods' => $s->payments->pluck('method')->unique()->map(fn ($m) => SalePayment::LABELS[$m] ?? $m)->values(),
                'payment_status' => $s->payment_status,
                'status' => $s->status,
                'cashier' => $s->cashier->name,
            ]);

        return Inertia::render('Sales/Index', [
            'sales' => $sales,
            'filters' => $filters,
            'summary' => ['bills' => (int) $summary->bills, 'total' => (float) $summary->total],
            'cashiers' => User::whereIn('id', Sale::where('branch_id', $branchId)->select('user_id'))->orderBy('name')->get(['id as value', 'name as label']),
        ]);
    }

    public function show(Sale $sale): Response
    {
        $this->authorizeBranch($sale);
        $sale->load(['items', 'payments', 'cashier:id,name', 'branch:id,name,code', 'customer:id,code,is_walk_in,type',
            'returns' => fn ($q) => $q->with('items:id,sale_return_id,sale_item_id,quantity', 'user:id,name')]);

        return Inertia::render('Sales/Show', [
            'sale' => [
                ...$sale->only(['id', 'invoice_no', 'customer_name', 'customer_phone', 'customer_gstin', 'place_of_supply', 'is_inter_state',
                    'price_type', 'status', 'payment_status', 'note', 'bill_discount_type']),
                ...collect(['subtotal', 'line_discount_total', 'bill_discount_value', 'bill_discount_total', 'taxable_total', 'cgst_total', 'sgst_total',
                    'igst_total', 'cess_total', 'round_off', 'grand_total', 'paid_total', 'change_given', 'account_amount'])
                    ->mapWithKeys(fn ($f) => [$f => (float) $sale->{$f}])->all(),
                'sold_at' => $sale->sold_at->toIso8601String(),
                'cashier' => $sale->cashier->name,
                'branch' => $sale->branch->name,
                'customer' => $sale->customer->is_walk_in ? null : ['id' => $sale->customer->id, 'code' => $sale->customer->code],
                'items' => $sale->items->map(fn ($i) => [
                    'id' => $i->id, 'name' => $i->product_name, 'variant' => $i->variant_name, 'sku' => $i->sku, 'hsn' => $i->hsn_code,
                    'unit' => $i->unit, 'quantity' => (float) $i->quantity, 'returned' => (float) $i->returned_quantity,
                    'unit_price' => (float) $i->unit_price, 'list_price' => (float) $i->list_price, 'mrp' => $i->mrp ? (float) $i->mrp : null,
                    'discount' => (float) $i->line_discount + (float) $i->bill_discount_share, 'taxable' => (float) $i->taxable_value,
                    'gst_rate' => (float) $i->gst_rate, 'tax' => (float) $i->cgst + (float) $i->sgst + (float) $i->igst + (float) $i->cess,
                    'total' => (float) $i->line_total,
                ]),
                'payments' => $sale->payments->map(fn ($p) => ['method' => SalePayment::LABELS[$p->method] ?? $p->method, 'amount' => (float) $p->amount, 'reference' => $p->reference]),
                'returns' => $sale->returns->map(fn ($r) => [
                    'id' => $r->id, 'credit_note_no' => $r->credit_note_no, 'total' => (float) $r->total, 'refund_method' => $r->refund_method,
                    'returned_at' => $r->returned_at->toIso8601String(), 'user' => $r->user->name, 'reason' => $r->reason,
                    'items' => $r->items->count(), 'print_url' => route('sale-returns.print', $r),
                ]),
                'print_url' => route('sales.print', $sale),
            ],
        ]);
    }

    private function authorizeBranch(Sale $sale): void
    {
        abort_unless(current_branch()->allowedFor(auth()->user())->contains('id', $sale->branch_id), 403);
    }
}
