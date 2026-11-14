<?php

use App\Models\User;
use App\Modules\Inventory\Models\ProductVariant;
use App\Modules\Inventory\Models\TaxRate;
use App\Modules\Pos\Http\Controllers\PosController;
use App\Modules\Pos\Models\Register;
use App\Modules\Pos\Services\RegisterService;
use App\Modules\Settings\Models\Branch;
use App\Support\CurrentBranch;
use Database\Seeders\DefaultCatalogSeeder;
use Database\Seeders\DefaultContactsSeeder;

/** Branch BR1 (Gujarat) with an open register shift (₹1,000 float) and a cashier. */
function posSetup(string $role = 'Cashier'): object
{
    (new DefaultCatalogSeeder)->run();
    (new DefaultContactsSeeder)->run();
    $branch = Branch::factory()->create(['code' => 'BR1', 'state_code' => '24']);
    $user = userWithRole($role, [$branch]);
    $register = Register::create(['branch_id' => $branch->id, 'name' => 'Counter 1']);
    test()->actingAs($user);
    $session = app(RegisterService::class)->open($register, $user, 1000);

    return (object) compact('branch', 'user', 'register', 'session');
}

/** A garment SKU (Garments GST, prices incl. GST) with stock at $branch. */
function garmentVariant(Branch $branch, float $price = 1799, float $stock = 10, array $variant = []): ProductVariant
{
    return makeVariant(
        ['selling_price' => $price, 'mrp' => $variant['mrp'] ?? $price, 'purchase_price' => round($price * 0.6, 2), ...$variant],
        ['tax_rate_id' => TaxRate::where('name', 'Garments')->value('id'), 'price_includes_tax' => true],
        $branch, $stock, round($price * 0.6, 2),
    );
}

/** POST as the POS: session carries the branch and register shift. */
function posAs(object $ctx, ?User $as = null)
{
    return test()->actingAs($as ?? $ctx->user)->withSession([
        CurrentBranch::SESSION_KEY => $ctx->branch->id,
        PosController::SESSION_KEY => $ctx->session->id,
    ]);
}

function checkout(object $ctx, array $items, array $payments, float $expected, array $extra = [], ?User $as = null)
{
    return posAs($ctx, $as)->postJson(route('pos.checkout'), [
        'items' => $items, 'payments' => $payments, 'expected_total' => $expected, ...$extra,
    ]);
}
