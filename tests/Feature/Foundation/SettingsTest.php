<?php

use App\Modules\Settings\Services\SettingsService;

function validSettings(array $overrides = []): array
{
    return array_replace_recursive([
        'business' => ['name' => 'Acme Mart', 'state_code' => '24', 'financial_year_start' => '04-01', 'gstin' => ''],
        'invoice' => ['prefix' => 'INV'],
        'tax' => ['prices_include_tax' => true, 'round_off' => true],
        'pos' => ['allow_negative_stock' => false, 'beep_on_scan' => true, 'receipt_size' => '80mm', 'print_after_sale' => true, 'accept_card' => true, 'accept_upi' => true],
    ], $overrides);
}

it('returns defaults for unsaved keys', function () {
    expect(settings('business.financial_year_start'))->toBe('04-01')
        ->and(settings('unknown.key', 'fallback'))->toBe('fallback');
});

it('saves business settings', function () {
    $this->actingAs(userWithRole('Admin'))
        ->put(route('settings.business.update'), validSettings())
        ->assertSessionHasNoErrors();

    app(SettingsService::class)->flush();
    expect(settings('business.name'))->toBe('Acme Mart')
        ->and(settings('pos.receipt_size'))->toBe('80mm');
});

it('validates the GSTIN', function () {
    $this->actingAs(userWithRole('Admin'))
        ->put(route('settings.business.update'), validSettings(['business' => ['gstin' => '24ABCDE1234F1Z0']]))
        ->assertSessionHasErrors('business.gstin');
});

it('is limited to users with settings.manage', function () {
    $this->actingAs(userWithRole('Manager'))->get(route('settings.business.edit'))->assertForbidden();
    $this->actingAs(userWithRole('Admin'))->get(route('settings.business.edit'))->assertOk();
});
