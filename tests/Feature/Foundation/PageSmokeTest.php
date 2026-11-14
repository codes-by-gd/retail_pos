<?php

use App\Modules\Settings\Models\Branch;
use Spatie\Permission\Models\Role;

it('renders every foundation page for an admin', function () {
    $branch = Branch::factory()->create();
    $admin = userWithRole('Admin', [$branch]);
    $cashier = userWithRole('Cashier', [$branch]);

    $pages = [
        route('dashboard') => 'Dashboard',
        route('profile.edit') => 'Profile/Edit',
        route('users.index') => 'Users/Index',
        route('users.create') => 'Users/Form',
        route('users.edit', $cashier) => 'Users/Form',
        route('roles.index') => 'Roles/Index',
        route('roles.create') => 'Roles/Form',
        route('roles.edit', Role::findByName('Cashier')) => 'Roles/Form',
        route('settings.business.edit') => 'Settings/Business',
        route('settings.branches.index') => 'Settings/Branches/Index',
    ];

    foreach ($pages as $url => $component) {
        $this->actingAs($admin)->get($url)->assertOk()->assertInertia(fn ($page) => $page->component($component));
    }
});
