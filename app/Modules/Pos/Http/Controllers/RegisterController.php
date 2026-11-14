<?php

namespace App\Modules\Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Pos\Models\Register;
use App\Modules\Settings\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Settings → cash registers (counters) per branch. */
class RegisterController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Registers', [
            'registers' => Register::with('branch:id,name,code')->withCount('sessions')->with('openSession:id,register_id')
                ->orderBy('branch_id')->orderBy('name')->get()
                ->map(fn (Register $r) => [
                    ...$r->only(['id', 'branch_id', 'name', 'is_active', 'sessions_count']),
                    'branch' => $r->branch->name,
                    'is_open' => (bool) $r->openSession,
                ]),
            'branches' => Branch::active()->orderBy('name')->get(['id as value', 'name as label']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Register::create($this->validated($request));

        return back()->with('success', 'Register created.');
    }

    public function update(Request $request, Register $register): RedirectResponse
    {
        $data = $this->validated($request, $register);
        if (! $data['is_active'] && $register->openSession()->exists()) {
            return back()->with('error', 'Close the open shift on this register first.');
        }
        $register->update($data);

        return back()->with('success', 'Register updated.');
    }

    public function destroy(Register $register): RedirectResponse
    {
        if ($register->sessions()->exists()) {
            return back()->with('error', 'This register has shifts on record. Deactivate it instead.');
        }
        $register->delete();

        return back()->with('success', 'Register deleted.');
    }

    private function validated(Request $request, ?Register $register = null): array
    {
        return $request->validate([
            'branch_id' => ['required', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:50', Rule::unique('registers')->where('branch_id', $request->input('branch_id'))->ignore($register)],
            'is_active' => ['required', 'boolean'],
        ]);
    }
}
