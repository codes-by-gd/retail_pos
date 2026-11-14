<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Attribute;
use App\Modules\Inventory\Models\AttributeValue;
use App\Modules\Inventory\Services\ProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Variant dimensions (Colour, Size, Waist…) and their ordered values. */
class AttributeController extends Controller
{
    public function index(): Response
    {
        $attributes = Attribute::query()
            ->with(['values' => fn ($q) => $q->withCount('variants')])
            ->orderBy('sort_order')->orderBy('name')
            ->get()
            ->map(fn (Attribute $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'sort_order' => $a->sort_order,
                'values' => $a->values->map(fn ($v) => ['id' => $v->id, 'value' => $v->value, 'used' => $v->variants_count])->values(),
                'used' => $a->values->sum('variants_count'),
            ]);

        return Inertia::render('Inventory/Catalog/Attributes', ['attributes' => $attributes]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data) {
            $attribute = Attribute::create(['name' => $data['name'], 'sort_order' => $data['sort_order'] ?? 0]);
            foreach ($data['values'] as $i => $v) {
                $attribute->values()->create(['value' => trim($v['value']), 'sort_order' => $i]);
            }
        });

        return back()->with('success', 'Attribute created.');
    }

    public function update(Request $request, Attribute $attribute, ProductService $products): RedirectResponse
    {
        $data = $this->validated($request, $attribute);

        $keepIds = collect($data['values'])->pluck('id')->filter()->map(fn ($id) => (int) $id);
        $removed = $attribute->values()->whereNotIn('id', $keepIds)->withCount('variants')->get();
        if ($inUse = $removed->firstWhere('variants_count', '>', 0)) {
            return back()->with('error', "“{$inUse->value}” is used by {$inUse->variants_count} product variants and can't be removed.");
        }

        DB::transaction(function () use ($attribute, $data, $removed, $products) {
            $attribute->update(['name' => $data['name'], 'sort_order' => $data['sort_order'] ?? 0]);
            AttributeValue::whereIn('id', $removed->pluck('id'))->delete();

            $renamed = [];
            foreach ($data['values'] as $i => $v) {
                $value = trim($v['value']);
                if (! empty($v['id'])) {
                    $model = $attribute->values()->findOrFail($v['id']);
                    if ($model->value !== $value) {
                        $renamed[] = $model->id;
                    }
                    $model->update(['value' => $value, 'sort_order' => $i]);
                } else {
                    $attribute->values()->create(['value' => $value, 'sort_order' => $i]);
                }
            }

            if ($renamed || $attribute->wasChanged('sort_order')) {
                $products->refreshVariantNames($attribute->wasChanged('sort_order') ? $attribute->values()->pluck('id')->all() : $renamed);
            }
        });

        return back()->with('success', 'Attribute updated.');
    }

    public function destroy(Attribute $attribute): RedirectResponse
    {
        if ($attribute->values()->whereHas('variants')->exists()) {
            return back()->with('error', 'This attribute is used by product variants.');
        }

        $attribute->delete();

        return back()->with('success', 'Attribute deleted.');
    }

    private function validated(Request $request, ?Attribute $attribute = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('attributes')->ignore($attribute)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'values' => ['required', 'array', 'min:1', 'max:200'],
            'values.*.id' => ['nullable', 'integer'],
            'values.*.value' => ['required', 'string', 'max:50'],
        ], ['values.required' => 'Add at least one value.']);

        $lower = array_map(fn ($v) => mb_strtolower(trim($v['value'])), $data['values']);
        if (count($lower) !== count(array_unique($lower))) {
            throw ValidationException::withMessages(['values' => 'Each value can appear only once.']);
        }

        return $data;
    }
}
