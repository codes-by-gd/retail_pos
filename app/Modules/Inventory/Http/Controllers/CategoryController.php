<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(): Response
    {
        $categories = Category::query()->with('parent:id,name')->withCount(['products', 'children'])->orderBy('name')->get();

        return Inertia::render('Inventory/Catalog/Categories', [
            'categories' => $categories->map(fn (Category $c) => [
                ...$c->only(['id', 'name', 'code', 'parent_id', 'is_active', 'products_count', 'children_count']),
                'parent' => $c->parent?->name,
            ]),
            'parentOptions' => Category::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Category::create($this->validated($request));

        return back()->with('success', 'Category created.');
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $data = $this->validated($request, $category);

        // Prevent cycles: a category cannot move under itself or one of its descendants.
        for ($p = $data['parent_id'] ? Category::find($data['parent_id']) : null; $p; $p = $p->parent) {
            if ($p->is($category)) {
                return back()->with('error', 'A category cannot be placed inside itself.');
            }
        }

        $category->update($data);

        return back()->with('success', 'Category updated.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        if ($category->products()->exists() || $category->children()->exists()) {
            return back()->with('error', 'Move its products and sub-categories first.');
        }

        $category->delete();

        return back()->with('success', 'Category deleted.');
    }

    private function validated(Request $request, ?Category $category = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100',
                Rule::unique('categories')->where('parent_id', $request->input('parent_id'))->whereNull('deleted_at')->ignore($category)],
            'code' => ['nullable', 'string', 'max:20'],
            'parent_id' => ['nullable', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'is_active' => ['required', 'boolean'],
        ], ['name.unique' => 'A category with this name already exists here.']);
    }
}
