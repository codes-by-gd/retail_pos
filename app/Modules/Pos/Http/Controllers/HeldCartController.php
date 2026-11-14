<?php

namespace App\Modules\Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Pos\Models\HeldCart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Parked bills for the current branch (any cashier can recall them). */
class HeldCartController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => HeldCart::where('branch_id', current_branch()->id())->with('user:id,name')->latest()->get()
            ->map(fn (HeldCart $c) => [
                'id' => $c->id,
                'label' => $c->label,
                'payload' => $c->payload,
                'user' => $c->user->name,
                'items' => count($c->payload['items'] ?? []),
                'total' => $c->payload['total'] ?? null,
                'created_at' => $c->created_at->toIso8601String(),
            ])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'payload' => ['required', 'array'],
            'payload.items' => ['required', 'array', 'min:1', 'max:300'],
        ], ['payload.items.required' => 'The bill is empty.']);

        $cart = HeldCart::create([...$data, 'branch_id' => current_branch()->id(), 'user_id' => $request->user()->id]);

        return response()->json(['id' => $cart->id, 'count' => HeldCart::where('branch_id', current_branch()->id())->count()], 201);
    }

    public function destroy(HeldCart $heldCart): JsonResponse
    {
        abort_unless($heldCart->branch_id === current_branch()->id(), 404);
        $heldCart->delete();

        return response()->json(['count' => HeldCart::where('branch_id', current_branch()->id())->count()]);
    }
}
