<?php

use App\Modules\Contacts\Models\Contact;
use App\Modules\Sales\Models\Sale;
use App\Support\Branding;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

it('searches everything the user may open and nothing else', function () {
    $ctx = posSetup('Manager');
    $shirt = garmentVariant($ctx->branch, 1799, 5, ['sku' => 'ZEBRA-SHIRT-01']);
    $cust = Contact::factory()->create(['type' => Contact::CUSTOMER, 'name' => 'Zebra Fashion Customer', 'phone' => '9811122233']);
    checkout($ctx, [['variant_id' => $shirt->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 1799]], 1799, ['customer_id' => $cust->id])->assertCreated();
    $invoice = Sale::first()->invoice_no;

    $groups = fn ($user, $q) => collect($this->actingAs($user)->getJson(route('search', ['q' => $q]))->assertOk()->json('results'))->pluck('group')->unique()->values()->all();

    expect($groups($ctx->user, 'zebra'))->toContain('Products', 'Customers')
        ->and($groups($ctx->user, '98111222'))->toContain('Customers', 'Bills')
        ->and(collect($this->actingAs($ctx->user)->getJson(route('search', ['q' => $invoice]))->json('results'))->firstWhere('group', 'Bills')['url'])
        ->toBe(route('sales.show', Sale::first()));

    // a stock keeper can't see customers or bills
    $keeper = userWithRole('Stock Keeper', [$ctx->branch]);
    expect($groups($keeper, 'zebra'))->toBe(['Products'])
        ->and($this->actingAs($keeper)->getJson(route('search', ['q' => 'z']))->json('results'))->toBe([]);
});

it('uploads a logo that appears on prints and can be removed', function () {
    Storage::fake('public');
    Cache::flush();
    $admin = userWithRole('Admin');

    $this->actingAs($admin)->post(route('settings.business.logo'), ['logo' => UploadedFile::fake()->image('logo.png', 400, 120)])->assertSessionHas('success');
    $path = settings('business.logo');
    Storage::disk('public')->assertExists($path);
    expect(Branding::logoUrl())->toContain('/storage/branding/')->and(Branding::logoDataUri())->toStartWith('data:image/png;base64,');

    $this->actingAs($admin)->get(route('dashboard'))->assertInertia(fn ($p) => $p->where('app.logo_url', Branding::logoUrl()));

    $this->actingAs($admin)->post(route('settings.business.logo'), ['logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')])->assertSessionHasErrors('logo');
    $this->actingAs(userWithRole('Manager'))->post(route('settings.business.logo'), ['logo' => UploadedFile::fake()->image('x.png')])->assertForbidden();

    $this->actingAs($admin)->delete(route('settings.business.logo.destroy'))->assertSessionHas('success');
    Storage::disk('public')->assertMissing($path);
    expect(Branding::logoUrl())->toBeNull();
});
