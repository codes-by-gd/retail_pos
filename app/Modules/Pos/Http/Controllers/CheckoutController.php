<?php

namespace App\Modules\Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\SalePayment;
use App\Modules\Sales\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    public function __invoke(Request $request, CheckoutService $checkout): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.variant_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'items.*.unit_price' => ['nullable', 'numeric', 'gt:0', 'max:9999999'],
            'items.*.discount_type' => ['nullable', Rule::in(['percent', 'amount'])],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'bill_discount_type' => ['nullable', Rule::in(['percent', 'amount'])],
            'bill_discount_value' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'payments' => ['required', 'array', 'min:1', 'max:10'],
            'payments.*.method' => ['required', Rule::in(array_keys(SalePayment::LABELS))],
            'payments.*.amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
            'payments.*.sale_return_id' => ['nullable', 'integer'],
            'expected_total' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
            'held_cart_id' => ['nullable', 'integer'],
        ], ['items.required' => 'The bill is empty.']);

        foreach ($data['items'] as $i => $item) {
            if (($item['discount_type'] ?? null) === 'percent' && (float) ($item['discount_value'] ?? 0) > 100) {
                throw ValidationException::withMessages(["items.$i.discount_value" => 'A discount cannot be more than 100%.']);
            }
        }

        $session = PosController::currentSession($request) ?? abort(409, 'The register is not open. Reload the POS.');
        $sale = $checkout->checkout($session, $request->user(), $data);

        return response()->json([
            'sale' => [
                'id' => $sale->id,
                'invoice_no' => $sale->invoice_no,
                'grand_total' => (float) $sale->grand_total,
                'change_given' => (float) $sale->change_given,
                'account_amount' => (float) $sale->account_amount,
                'customer' => $sale->customer_name,
                'print_url' => route('sales.print', $sale),
            ],
        ], 201);
    }
}
