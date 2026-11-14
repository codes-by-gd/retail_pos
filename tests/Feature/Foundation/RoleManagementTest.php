<?php

use App\Support\Permissions;
use Spatie\Permission\Models\Role;

it('seeds the default roles with the expected permissions', function () {
    userWithRole('Admin');

    expect(Role::pluck('name')->sort()->values()->all())->toBe(['Admin', 'Cashier', 'Manager', 'Stock Keeper'])
        ->and(Role::findByName('Cashier')->hasPermissionTo('pos.access'))->toBeTrue()
        ->and(Role::findByName('Cashier')->hasPermissionTo('reports.financial'))->toBeFalse()
        ->and(Role::findByName('Manager')->hasPermissionTo('roles.manage'))->toBeFalse();
});

it('gives admins every permission through the gate', function () {
    $admin = userWithRole('Admin');

    foreach (Permissions::all() as $permission) {
        expect($admin->can($permission))->toBeTrue();
    }
});

it('creates a custom role with permissions', function () {
    $this->actingAs(userWithRole('Admin'))->post(route('roles.store'), [
        'name' => 'Senior Cashier', 'permissions' => ['pos.access', 'pos.returns'],
    ])->assertRedirect(route('roles.index'));

    expect(Role::findByName('Senior Cashier')->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['pos.access', 'pos.returns']);
});

it('rejects unknown permissions', function () {
    $this->actingAs(userWithRole('Admin'))
        ->post(route('roles.store'), ['name' => 'X', 'permissions' => ['launch.rockets']])
        ->assertSessionHasErrors('permissions.0');
});

it('protects the Admin role and roles that still have users', function () {
    $admin = userWithRole('Admin');
    userWithRole('Cashier');

    $this->actingAs($admin)->delete(route('roles.destroy', Role::findByName('Admin')))->assertSessionHas('error');
    $this->actingAs($admin)->delete(route('roles.destroy', Role::findByName('Cashier')))->assertSessionHas('error');
    $this->actingAs($admin)->put(route('roles.update', Role::findByName('Admin')), ['name' => 'Admin2'])->assertForbidden();

    expect(Role::where('name', 'Admin')->exists())->toBeTrue();
});

it('blocks managers from managing roles', function () {
    $this->actingAs(userWithRole('Manager'))->get(route('roles.index'))->assertForbidden();
});
