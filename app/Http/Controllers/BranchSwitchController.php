<?php

namespace App\Http\Controllers;

use App\Modules\Settings\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BranchSwitchController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate(['branch_id' => ['required', 'integer']]);

        $branch = current_branch()->allowedFor($request->user())->firstWhere('id', $data['branch_id']);
        abort_unless($branch instanceof Branch, 403, 'You do not have access to this branch.');

        current_branch()->switchTo($branch);

        return back()->with('success', "Switched to {$branch->name}.");
    }
}
