<?php

namespace App\Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Roles/Index', [
            'roles' => Role::query()->withCount(['users', 'permissions'])->orderBy('name')->get()
                ->map(fn (Role $r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'users_count' => $r->users_count,
                    'permissions_count' => $r->name === Permissions::ADMIN_ROLE ? count(Permissions::all()) : $r->permissions_count,
                    'locked' => $r->name === Permissions::ADMIN_ROLE,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Roles/Form', ['groups' => Permissions::GROUPS]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
        $role->syncPermissions($data['permissions']);

        return to_route('roles.index')->with('success', 'Role created.');
    }

    public function edit(Role $role): Response|RedirectResponse
    {
        if ($role->name === Permissions::ADMIN_ROLE) {
            return to_route('roles.index')->with('error', 'The Admin role always has every permission.');
        }

        return Inertia::render('Roles/Form', [
            'groups' => Permissions::GROUPS,
            'role' => ['id' => $role->id, 'name' => $role->name, 'permissions' => $role->permissions->pluck('name')],
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_if($role->name === Permissions::ADMIN_ROLE, 403, 'The Admin role cannot be edited.');

        $data = $this->validated($request, $role);
        $before = $role->permissions->pluck('name')->all();
        $role->update(['name' => $data['name']]);
        $role->syncPermissions($data['permissions']);
        $after = $data['permissions'];
        activity()->causedBy($request->user())->performedOn($role)->withProperties(array_filter([
            'role' => $role->name,
            'added' => implode(', ', array_diff($after, $before)),
            'removed' => implode(', ', array_diff($before, $after)),
        ]))->log('Changed role permissions');

        return to_route('roles.index')->with('success', 'Role updated.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->name === Permissions::ADMIN_ROLE) {
            return back()->with('error', 'The Admin role cannot be deleted.');
        }
        if ($role->users()->exists()) {
            return back()->with('error', 'Reassign the users of this role before deleting it.');
        }

        $role->delete();

        return back()->with('success', 'Role deleted.');
    }

    private function validated(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('roles', 'name')->ignore($role), Rule::notIn([Permissions::ADMIN_ROLE])],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(Permissions::all())],
        ]);
    }
}
