<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Http\Requests\BranchRequest;
use App\Modules\Settings\Models\Branch;
use App\Support\Gst\IndianStates;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Branches/Index', [
            'branches' => Branch::query()->withCount('users')->orderBy('name')->get()->each->append('state_name'),
            'states' => IndianStates::options(),
            'defaultStateCode' => settings('business.state_code'),
        ]);
    }

    public function store(BranchRequest $request): RedirectResponse
    {
        $branch = Branch::create($request->validated());
        $branch->registers()->create(['name' => 'Counter 1']);

        return back()->with('success', 'Branch created.');
    }

    public function update(BranchRequest $request, Branch $branch): RedirectResponse
    {
        $data = $request->validated();

        if (! $data['is_active'] && $branch->is_active && Branch::active()->count() === 1) {
            return back()->with('error', 'At least one branch must stay active.');
        }

        $branch->update($data);

        return back()->with('success', 'Branch updated.');
    }

    public function destroy(Branch $branch): RedirectResponse
    {
        if (Branch::count() === 1) {
            return back()->with('error', 'You cannot delete the only branch.');
        }

        // Later phases will also block deletion when the branch has stock or sales.
        $branch->delete();

        return back()->with('success', 'Branch deleted.');
    }
}
