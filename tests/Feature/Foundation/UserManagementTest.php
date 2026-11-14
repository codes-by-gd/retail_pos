<?php

use App\Models\User;
use App\Modules\Settings\Models\Branch;

beforeEach(function () {
    $this->branch = Branch::factory()->create();
});

function newUserPayload(array $overrides = []): array
{
    return [
        'name' => 'Ravi Kumar', 'email' => 'ravi@example.com', 'phone' => '9876543210',
        'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123',
        'pin' => '4321', 'is_active' => true, 'role' => 'Cashier', 'branch_ids' => [], ...$overrides,
    ];
}

it('lets a manager create a cashier with a hashed PIN', function () {
    $manager = userWithRole('Manager', [$this->branch]);

    $this->actingAs($manager)
        ->post(route('users.store'), newUserPayload(['branch_ids' => [$this->branch->id]]))
        ->assertRedirect(route('users.index'));

    $user = User::where('email', 'ravi@example.com')->first();
    expect($user->hasRole('Cashier'))->toBeTrue()
        ->and($user->branches->pluck('id')->all())->toBe([$this->branch->id])
        ->and($user->pin)->not->toBe('4321')
        ->and(Hash::check('4321', $user->pin))->toBeTrue();
});

it('requires a branch for non-admin users', function () {
    $this->actingAs(userWithRole('Admin'))
        ->post(route('users.store'), newUserPayload())
        ->assertSessionHasErrors('branch_ids');
});

it('prevents non-admins from creating or editing admins', function () {
    $manager = userWithRole('Manager', [$this->branch]);
    $admin = userWithRole('Admin');

    $this->actingAs($manager)
        ->post(route('users.store'), newUserPayload(['role' => 'Admin']))
        ->assertSessionHasErrors('role');

    $this->actingAs($manager)->get(route('users.edit', $admin))->assertForbidden();
});

it('forbids cashiers from viewing users', function () {
    $this->actingAs(userWithRole('Cashier', [$this->branch]))->get(route('users.index'))->assertForbidden();
});

it('keeps the password when left blank on update', function () {
    $admin = userWithRole('Admin');
    $user = userWithRole('Cashier', [$this->branch]);
    $oldHash = $user->password;

    $this->actingAs($admin)->put(route('users.update', $user), [
        ...newUserPayload(['email' => $user->email, 'branch_ids' => [$this->branch->id]]),
        'password' => '', 'password_confirmation' => '', 'pin' => '',
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->password)->toBe($oldHash);
});

it('stops an admin from demoting or deactivating themselves', function () {
    $admin = userWithRole('Admin');

    $this->actingAs($admin)->put(route('users.update', $admin), [
        ...newUserPayload(['email' => $admin->email, 'role' => 'Manager', 'branch_ids' => [$this->branch->id]]),
        'password' => '', 'password_confirmation' => '',
    ])->assertSessionHas('error');

    expect($admin->fresh()->isAdmin())->toBeTrue();
});

it('filters the user list by search term', function () {
    $admin = userWithRole('Admin');
    User::factory()->create(['name' => 'Qwyzx Shah']);

    $this->actingAs($admin)->get(route('users.index', ['search' => 'Qwyzx']))
        ->assertInertia(fn ($page) => $page->has('users.data', 1)->where('users.data.0.name', 'Qwyzx Shah'));
});
