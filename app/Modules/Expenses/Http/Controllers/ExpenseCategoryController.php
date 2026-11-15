<?php

namespace App\Modules\Expenses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Expenses\Models\ExpenseCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseCategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Expenses/Categories', [
            'categories' => ExpenseCategory::withCount('expenses')->orderBy('name')->get(['id', 'name', 'is_active']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        ExpenseCategory::create($this->validated($request));

        return back()->with('success', 'Category created.');
    }

    public function update(Request $request, ExpenseCategory $expenseCategory): RedirectResponse
    {
        $expenseCategory->update($this->validated($request, $expenseCategory));

        return back()->with('success', 'Category updated.');
    }

    public function destroy(ExpenseCategory $expenseCategory): RedirectResponse
    {
        if ($expenseCategory->expenses()->exists()) {
            return back()->with('error', 'This category has expenses. Deactivate it instead.');
        }
        $expenseCategory->delete();

        return back()->with('success', 'Category deleted.');
    }

    private function validated(Request $request, ?ExpenseCategory $category = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('expense_categories')->ignore($category)],
            'is_active' => ['required', 'boolean'],
        ]);
    }
}
