<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\TaxRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaxRateController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Inventory/Catalog/TaxRates', [
            'taxRates' => TaxRate::query()->withCount('products')->orderByRaw('threshold_amount IS NULL')->orderBy('rate')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        TaxRate::create($this->validated($request));

        return back()->with('success', 'GST rate created.');
    }

    public function update(Request $request, TaxRate $taxRate): RedirectResponse
    {
        $data = $this->validated($request);

        // Changing the % of a rate in use would silently re-price products; ask for a new rate instead.
        $changed = fn (string $field) => bccomp((string) ($data[$field] ?? '-1'), (string) ($taxRate->{$field} ?? '-1'), 2) !== 0;
        if ($taxRate->products()->withTrashed()->exists()
            && ($changed('rate') || $changed('cess_rate') || $changed('threshold_amount') || $changed('rate_above'))) {
            return back()->with('error', 'This rate is used by products — create a new rate and move the products instead.');
        }

        $taxRate->update($data);

        return back()->with('success', 'GST rate updated.');
    }

    public function destroy(TaxRate $taxRate): RedirectResponse
    {
        if ($taxRate->products()->withTrashed()->exists()) {
            return back()->with('error', 'This rate is used by products. Deactivate it instead.');
        }

        $taxRate->delete();

        return back()->with('success', 'GST rate deleted.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'cess_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'threshold_amount' => ['nullable', 'required_with:rate_above', 'numeric', 'gt:0'],
            'rate_above' => ['nullable', 'required_with:threshold_amount', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['required', 'boolean'],
        ], [
            'threshold_amount.required_with' => 'Enter the price limit for the higher rate.',
            'rate_above.required_with' => 'Enter the GST % that applies above the price limit.',
        ]);
    }
}
