<?php

namespace App\Modules\Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Sales\Services\SaleReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReturnController extends Controller
{
    /** Find an invoice to return against (any branch the user may access). */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate(['invoice_no' => ['required', 'string', 'max:20']]);
        $sale = Sale::with(['items', 'customer:id,name,is_walk_in'])
            ->where('invoice_no', strtoupper(trim($data['invoice_no'])))
            ->whereIn('branch_id', current_branch()->allowedFor($request->user())->pluck('id'))
            ->first();

        if (! $sale) {
            return response()->json(['message' => 'No invoice with this number.'], 404);
        }
        if ($sale->status !== Sale::COMPLETED) {
            return response()->json(['message' => "Invoice {$sale->invoice_no} is {$sale->status}."], 422);
        }

        return response()->json(['sale' => [
            'id' => $sale->id,
            'invoice_no' => $sale->invoice_no,
            'sold_at' => $sale->sold_at->toIso8601String(),
            'customer' => $sale->customer_name,
            'walk_in' => $sale->customer->is_walk_in,
            'grand_total' => (float) $sale->grand_total,
            'items' => $sale->items->map(fn ($i) => [
                'id' => $i->id,
                'name' => $i->displayName(),
                'sku' => $i->sku,
                'unit' => $i->unit,
                'quantity' => (float) $i->quantity,
                'returnable' => (float) $i->quantity - (float) $i->returned_quantity,
                'unit_amount' => round((float) $i->line_total / (float) $i->quantity, 2),
            ]),
        ]]);
    }

    public function store(Request $request, SaleReturnService $returns): JsonResponse
    {
        $data = $request->validate([
            'sale_id' => ['required', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sale_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
            'items.*.restock' => ['boolean'],
            'refund_method' => ['required', Rule::in(SaleReturnService::REFUND_METHODS)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $sale = Sale::whereKey($data['sale_id'])->whereIn('branch_id', current_branch()->allowedFor($request->user())->pluck('id'))->firstOrFail();
        $return = $returns->create($sale, $data['items'], $data['refund_method'], $data['reason'] ?? null, $request->user(),
            PosController::currentSession($request), current_branch()->get());

        return response()->json(['return' => $this->present($return)], 201);
    }

    /** Look up an unused exchange credit note to use as payment. */
    public function exchangeCredit(Request $request): JsonResponse
    {
        $data = $request->validate(['credit_note_no' => ['required', 'string', 'max:20']]);
        $return = SaleReturn::where('credit_note_no', strtoupper(trim($data['credit_note_no'])))->first();

        if (! $return || $return->refund_method !== 'exchange') {
            return response()->json(['message' => 'No exchange credit with this number.'], 404);
        }
        if ($return->used_in_sale_id) {
            return response()->json(['message' => 'This exchange credit was already used.'], 422);
        }

        return response()->json(['return' => $this->present($return)]);
    }

    private function present(SaleReturn $r): array
    {
        return [
            'id' => $r->id,
            'credit_note_no' => $r->credit_note_no,
            'total' => (float) $r->total,
            'refund_method' => $r->refund_method,
            'print_url' => route('sale-returns.print', $r),
        ];
    }
}
