<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UnitController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Inventory/Catalog/Units', [
            'units' => Unit::query()->withCount('products')->orderBy('name')->get(['id', 'name', 'short_name', 'allow_decimal']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Unit::create($this->validated($request));

        return back()->with('success', 'Unit created.');
    }

    public function update(Request $request, Unit $unit): RedirectResponse
    {
        $unit->update($this->validated($request, $unit));

        return back()->with('success', 'Unit updated.');
    }

    public function destroy(Unit $unit): RedirectResponse
    {
        if ($unit->products()->withTrashed()->exists()) {
            return back()->with('error', 'This unit is used by products.');
        }

        $unit->delete();

        return back()->with('success', 'Unit deleted.');
    }

    private function validated(Request $request, ?Unit $unit = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'short_name' => ['required', 'string', 'max:10', Rule::unique('units')->ignore($unit)],
            'allow_decimal' => ['required', 'boolean'],
        ]);
    }
}
