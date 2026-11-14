<?php

use App\Modules\Settings\Models\Branch;
use App\Rules\Gstin;
use App\Support\CurrentBranch;

it('lets an admin create a branch', function () {
    $admin = userWithRole('Admin');
    $gstin = '24AABCR1234A1Z';

    $this->actingAs($admin)->post(route('settings.branches.store'), [
        'name' => 'Highway Store', 'code' => 'hw1', 'state_code' => '24',
        'gstin' => strtolower($gstin.Gstin::checksum($gstin)), 'is_active' => true,
    ])->assertSessionHasNoErrors();

    expect(Branch::where('code', 'HW1')->first())
        ->not->toBeNull()
        ->gstin->toBe($gstin.Gstin::checksum($gstin));
});

it('rejects a GSTIN whose state does not match the branch state', function () {
    $gstin = '27AAPFU0939F1ZV'; // Maharashtra

    $this->actingAs(userWithRole('Admin'))->post(route('settings.branches.store'), [
        'name' => 'X', 'code' => 'X1', 'state_code' => '24', 'gstin' => $gstin, 'is_active' => true,
    ])->assertSessionHasErrors('gstin');
});

it('forbids cashiers from managing branches', function () {
    $this->actingAs(userWithRole('Cashier'))->get(route('settings.branches.index'))->assertForbidden();
});

it('will not delete or deactivate the only branch', function () {
    $branch = Branch::factory()->create();
    $admin = userWithRole('Admin');

    $this->actingAs($admin)->delete(route('settings.branches.destroy', $branch))->assertSessionHas('error');
    $this->actingAs($admin)->put(route('settings.branches.update', $branch), [
        ...$branch->only(['name', 'code', 'state_code']), 'is_active' => false,
    ])->assertSessionHas('error');

    expect($branch->fresh())->not->toBeNull()->is_active->toBeTrue();
});

it('defaults the current branch to the first assigned branch', function () {
    $a = Branch::factory()->create(['name' => 'A Store']);
    $b = Branch::factory()->create(['name' => 'B Store']);
    $cashier = userWithRole('Cashier', [$b]);

    $this->actingAs($cashier)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('branch.current.id', $b->id)
            ->has('branch.available', 1));
});

it('lets users switch only to branches they are assigned to', function () {
    $a = Branch::factory()->create();
    $b = Branch::factory()->create();
    $c = Branch::factory()->create();
    $cashier = userWithRole('Cashier', [$a, $b]);

    $this->actingAs($cashier)->post(route('branch.switch'), ['branch_id' => $b->id])
        ->assertSessionHas(CurrentBranch::SESSION_KEY, $b->id);

    $this->actingAs($cashier)->post(route('branch.switch'), ['branch_id' => $c->id])->assertForbidden();
});

it('lets admins work in every active branch', function () {
    Branch::factory()->count(2)->create();
    Branch::factory()->inactive()->create();

    $this->actingAs(userWithRole('Admin'))->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->has('branch.available', 2));
});
