<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Brand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BrandController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Inventory/Catalog/Brands', [
            'brands' => Brand::query()->withCount('products')->orderBy('name')->get(['id', 'name', 'is_active']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Brand::create($this->validated($request));

        return back()->with('success', 'Brand created.');
    }

    public function update(Request $request, Brand $brand): RedirectResponse
    {
        $brand->update($this->validated($request, $brand));

        return back()->with('success', 'Brand updated.');
    }

    public function destroy(Brand $brand): RedirectResponse
    {
        if ($brand->products()->exists()) {
            return back()->with('error', 'This brand is used by products. Deactivate it instead.');
        }

        $brand->delete();

        return back()->with('success', 'Brand deleted.');
    }

    private function validated(Request $request, ?Brand $brand = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('brands')->ignore($brand)],
            'is_active' => ['required', 'boolean'],
        ]);
    }
}
