<?php

use App\Models\User;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Inventory\Models\Product;
use App\Modules\Settings\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;

it('installs a clean shop with one admin and refuses to run twice', function () {
    $this->artisan('pos:install', [
        '--business' => 'Shree Fashions', '--state' => '24', '--gstin' => '', '--branch' => 'Main Road', '--branch-code' => 'mr1',
        '--admin-name' => 'Owner', '--admin-email' => 'owner@shop.test', '--admin-password' => 'Secret123',
    ])->assertSuccessful();

    $admin = User::where('email', 'owner@shop.test')->first();
    $branch = Branch::first();
    expect($admin->isAdmin())->toBeTrue()
        ->and($admin->branches->pluck('id')->all())->toBe([$branch->id])
        ->and($branch)->code->toBe('MR1')->state_code->toBe('24')
        ->and($branch->registers()->count())->toBe(1)
        ->and(settings('business.name'))->toBe('Shree Fashions')
        ->and(User::count())->toBe(1)                                   // no demo users
        ->and(Product::count())->toBe(0)                               // no demo catalog
        ->and(Contact::where('is_walk_in', true)->exists())->toBeTrue()
        ->and(Role::where('name', 'Cashier')->exists())->toBeTrue();

    $this->artisan('pos:install', ['--business' => 'X'])->assertFailed();
});

it('validates installer answers', function () {
    $this->artisan('pos:install', [
        '--business' => 'Shop', '--state' => '24', '--gstin' => '27AAKFK4321B1ZQ', '--branch' => 'Main', '--branch-code' => 'BR1',
        '--admin-name' => 'A', '--admin-email' => 'not-an-email', '--admin-password' => 'short',
    ])->assertFailed();
    expect(User::count())->toBe(0);
});

it('never seeds demo data in production', function () {
    app()->detectEnvironment(fn () => 'production');
    (new DatabaseSeeder)->setContainer(app())->__invoke();
    expect(User::count())->toBe(0)->and(Product::count())->toBe(0);
    app()->detectEnvironment(fn () => 'testing');
});

it('sends users without the dashboard permission to a screen they can use', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $role = Role::create(['name' => 'Counter only'])->givePermissionTo('pos.access');
    $branch = Branch::factory()->create();
    $user = User::factory()->create();
    $user->assignRole($role);
    $user->branches()->sync([$branch->id]);

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('pos.terminal'));
});

it('sends security headers and rejects SVG product images', function () {
    $this->actingAs(userWithRole('Admin'))->get(route('dashboard'))
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $this->actingAs(userWithRole('Admin'))->post(route('products.store'), ['name' => 'X', 'image' => $svg])->assertSessionHasErrors('image');
});

it('shows a friendly error page outside local/testing', function () {
    app()->detectEnvironment(fn () => 'production');
    $this->actingAs(userWithRole('Cashier'))->get(route('settings.business.edit'))
        ->assertForbidden()->assertInertia(fn ($p) => $p->component('Error')->where('status', 403));
    $this->actingAs(userWithRole('Admin'))->get('/sales/999999')->assertNotFound()->assertInertia(fn ($p) => $p->component('Error')->where('status', 404));
    app()->detectEnvironment(fn () => 'testing');
});
