<?php

namespace App\Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Settings\Models\Branch;
use App\Modules\Users\Http\Requests\UserRequest;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'role', 'status']);

        $users = User::query()
            ->with(['roles:id,name', 'branches:id,name,code'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$s}%")
                ->orWhere('email', 'like', "%{$s}%")
                ->orWhere('phone', 'like', "%{$s}%")))
            ->when($filters['role'] ?? null, fn ($q, $r) => $q->role($r))
            ->when(($filters['status'] ?? null) === 'active', fn ($q) => $q->where('is_active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'phone' => $u->phone,
                'is_active' => $u->is_active,
                'last_login_at' => $u->last_login_at?->toIso8601String(),
                'roles' => $u->roles->pluck('name'),
                'branches' => $u->branches->pluck('code'),
            ]);

        return Inertia::render('Users/Index', [
            'users' => $users,
            'filters' => $filters,
            'roles' => Role::orderBy('name')->pluck('name'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Users/Form', $this->formOptions());
    }

    public function store(UserRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $user = User::create($request->userAttributes());
            $user->syncRoles([$request->validated('role')]);
            $user->branches()->sync($request->validated('branch_ids'));
        });

        return to_route('users.index')->with('success', 'User created.');
    }

    public function edit(User $user): Response
    {
        abort_if($user->isAdmin() && ! auth()->user()->isAdmin(), 403, 'Only an Admin can edit an Admin account.');

        return Inertia::render('Users/Form', [
            ...$this->formOptions(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'is_active' => $user->is_active,
                'has_pin' => $user->pin !== null,
                'role' => $user->roles->first()?->name,
                'branch_ids' => $user->branches()->pluck('branches.id'),
            ],
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $role = $request->validated('role');
        $isSelf = $request->user()->is($user);

        if ($isSelf && ! $request->boolean('is_active')) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }
        if ($user->isAdmin() && ($role !== Permissions::ADMIN_ROLE || ! $request->boolean('is_active'))) {
            if ($isSelf) {
                return back()->with('error', 'You cannot remove your own Admin role.');
            }
            if (User::role(Permissions::ADMIN_ROLE)->active()->count() <= 1) {
                return back()->with('error', 'At least one active Admin is required.');
            }
        }

        DB::transaction(function () use ($request, $user, $role) {
            $user->update($request->userAttributes());
            $user->syncRoles([$role]);
            $user->branches()->sync($request->validated('branch_ids'));
        });

        return to_route('users.index')->with('success', 'User updated.');
    }

    private function formOptions(): array
    {
        return [
            'roles' => Role::orderBy('name')->pluck('name'),
            'branches' => Branch::active()->orderBy('name')->get(['id', 'name', 'code']),
            'canAssignAdmin' => auth()->user()->isAdmin(),
        ];
    }
}
