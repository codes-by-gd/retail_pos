<?php

namespace App\Modules\Contacts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contacts\Models\CustomerGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerGroupController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Contacts/Groups', [
            'groups' => CustomerGroup::query()->withCount('contacts')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        CustomerGroup::create($this->validated($request));

        return back()->with('success', 'Customer group created.');
    }

    public function update(Request $request, CustomerGroup $customerGroup): RedirectResponse
    {
        $customerGroup->update($this->validated($request, $customerGroup));

        return back()->with('success', 'Customer group updated.');
    }

    public function destroy(CustomerGroup $customerGroup): RedirectResponse
    {
        if ($customerGroup->contacts()->exists()) {
            return back()->with('error', 'Move the customers in this group first.');
        }

        $customerGroup->delete();

        return back()->with('success', 'Customer group deleted.');
    }

    private function validated(Request $request, ?CustomerGroup $group = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('customer_groups')->ignore($group)],
            'price_type' => ['required', Rule::in([CustomerGroup::PRICE_RETAIL, CustomerGroup::PRICE_WHOLESALE])],
            'discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);
    }
}
