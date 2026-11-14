<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Pos\Models\RegisterSession;
use App\Modules\Sales\Models\Sale;
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

        // A branch with history must stay (deactivate it instead) so reports and documents keep their branch.
        $hasHistory = Sale::where('branch_id', $branch->id)->exists()
            || StockMovement::where('branch_id', $branch->id)->exists()
            || RegisterSession::where('branch_id', $branch->id)->exists();
        if ($hasHistory) {
            return back()->with('error', "{$branch->name} has sales, stock or register history. Deactivate it instead.");
        }

        $branch->registers()->delete();
        $branch->delete();

        return back()->with('success', 'Branch deleted.');
    }
}
