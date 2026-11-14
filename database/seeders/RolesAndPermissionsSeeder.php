<?php

namespace Database\Seeders;

use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent: creates missing permissions & default roles. Safe to re-run after
 * adding permissions to App\Support\Permissions. Never removes custom roles.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        Role::findOrCreate(Permissions::ADMIN_ROLE, 'web');

        foreach (Permissions::DEFAULT_ROLES as $role => $patterns) {
            $role = Role::findOrCreate($role, 'web');
            // Only fill a role on first creation so admin customisations are not overwritten.
            if ($role->wasRecentlyCreated || $role->permissions()->doesntExist()) {
                $role->syncPermissions(Permissions::resolve($patterns));
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
