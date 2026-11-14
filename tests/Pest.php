<?php

use App\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductVariant;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Settings\Models\Branch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/** Seed roles/permissions and create a user with the given role, assigned to the given branches. */
function userWithRole(string $role, array $branches = []): User
{
    (new RolesAndPermissionsSeeder)->run();

    $user = User::factory()->create();
    $user->assignRole($role);
    $user->branches()->sync(collect($branches)->map(fn ($b) => is_object($b) ? $b->id : $b));

    return $user;
}

/** A single product with one variant (SKU) and optional opening stock at $branch. */
function makeVariant(array $variant = [], array $product = [], ?Branch $branch = null, float $stock = 0, float $cost = 80): ProductVariant
{
    $p = Product::factory()->withVariant($variant)->create($product);
    $v = $p->variants()->first();

    if ($branch && $stock) {
        app(StockService::class)->move($v, $branch->id, $stock, StockMovementType::Opening, $cost);
    }

    return $v;
}
