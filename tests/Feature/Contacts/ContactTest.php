<?php

use App\Modules\Contacts\Enums\LedgerEntryType;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\CustomerGroup;
use App\Modules\Contacts\Services\ContactLedgerService;
use App\Modules\Settings\Models\Branch;
use App\Rules\Gstin;
use Database\Seeders\DefaultContactsSeeder;

beforeEach(function () {
    (new DefaultContactsSeeder)->run();
    $this->branch = Branch::factory()->create();
    $this->admin = userWithRole('Admin', [$this->branch]);
});

function customerPayload(array $overrides = []): array
{
    return ['type' => 'customer', 'name' => 'Priya Patel', 'phone' => '+91 98765-43210', 'is_active' => true, ...$overrides];
}

it('creates a customer with a code and a normalised phone number', function () {
    $this->actingAs($this->admin)->post(route('customers.store'), customerPayload())->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('suppliers.store'), ['type' => 'supplier', 'name' => 'Shah Textiles', 'is_active' => true])->assertSessionHasNoErrors();

    expect(Contact::where('name', 'Priya Patel')->first())->code->toBe('CUS-00001')->phone->toBe('9876543210')
        ->and(Contact::where('name', 'Shah Textiles')->value('code'))->toBe('SUP-00001');
});

it('requires a unique phone for customers', function () {
    $this->actingAs($this->admin)->post(route('customers.store'), customerPayload(['phone' => '']))->assertSessionHasErrors('phone');

    Contact::factory()->create(['phone' => '9876543210']);
    $this->actingAs($this->admin)->post(route('customers.store'), customerPayload(['phone' => '09876543210']))->assertSessionHasErrors('phone');
});

it('takes the state from the GSTIN and rejects a mismatch', function () {
    $gstin = '27AAKFK4321B1Z'.Gstin::checksum('27AAKFK4321B1Z');

    $this->actingAs($this->admin)->post(route('customers.store'), customerPayload(['gstin' => strtolower($gstin), 'state_code' => null]))->assertSessionHasNoErrors();
    expect(Contact::where('gstin', $gstin)->value('state_code'))->toBe('27');

    $this->actingAs($this->admin)->post(route('customers.store'), customerPayload(['phone' => '9000000001', 'gstin' => $gstin, 'state_code' => '24']))
        ->assertSessionHasErrors('state_code');
});

it('keeps the opening balance in the ledger and lets it be changed', function () {
    $this->actingAs($this->admin)->post(route('suppliers.store'), ['type' => 'supplier', 'name' => 'Mill', 'is_active' => true, 'opening_balance' => -45000]);
    $mill = Contact::where('name', 'Mill')->first();
    expect($mill->balance())->toBe('-45000.00');

    $this->actingAs($this->admin)->put(route('suppliers.update', $mill), ['type' => 'supplier', 'name' => 'Mill', 'is_active' => true, 'opening_balance' => -40000]);
    expect($mill->balance())->toBe('-40000.00')->and($mill->ledger()->count())->toBe(1);

    $this->actingAs($this->admin)->put(route('suppliers.update', $mill), ['type' => 'supplier', 'name' => 'Mill', 'is_active' => true, 'opening_balance' => 0]);
    expect($mill->ledger()->count())->toBe(0);
});

it('checks permissions by contact type', function () {
    $cashier = userWithRole('Cashier', [$this->branch]);        // customers.manage only
    $keeper = userWithRole('Stock Keeper', [$this->branch]);    // suppliers.manage only

    $this->actingAs($cashier)->post(route('customers.store'), customerPayload())->assertSessionHasNoErrors();
    $this->actingAs($cashier)->post(route('suppliers.store'), ['type' => 'supplier', 'name' => 'X', 'is_active' => true])->assertForbidden();
    $this->actingAs($keeper)->post(route('suppliers.store'), ['type' => 'both', 'name' => 'Y', 'is_active' => true])->assertForbidden();
    $this->actingAs($cashier)->get(route('suppliers.index'))->assertForbidden();
});

it('protects the walk-in customer', function () {
    $walkIn = Contact::where('is_walk_in', true)->first();

    $this->actingAs($this->admin)->delete(route('customers.destroy', $walkIn))->assertSessionHas('error');
    $this->actingAs($this->admin)->put(route('customers.update', $walkIn), ['type' => 'supplier', 'name' => 'Counter sale', 'is_active' => false])->assertSessionHasNoErrors();

    expect($walkIn->fresh())->name->toBe('Counter sale')->type->toBe('customer')->is_active->toBeTrue();
});

it('only deletes contacts without transactions', function () {
    $c = Contact::factory()->create();
    app(ContactLedgerService::class)->record($c, LedgerEntryType::Sale, debit: 500);

    $this->actingAs($this->admin)->delete(route('customers.destroy', $c))->assertSessionHas('error');

    $d = Contact::factory()->create();
    app(ContactLedgerService::class)->setOpeningBalance($d, 100);
    $this->actingAs($this->admin)->delete(route('customers.destroy', $d))->assertRedirect(route('customers.index'));
    expect(Contact::find($d->id))->toBeNull()->and(Contact::find($c->id))->not->toBeNull();
});

it('builds a statement with running and brought-forward balances', function () {
    $c = Contact::factory()->create();
    $ledger = app(ContactLedgerService::class);
    $ledger->record($c, LedgerEntryType::Sale, debit: 1000, date: now()->subDays(10));
    $ledger->record($c, LedgerEntryType::PaymentReceived, credit: 400, date: now()->subDays(5));
    $ledger->record($c, LedgerEntryType::Sale, debit: 250, date: now());

    $all = $ledger->statement($c);
    expect(array_column($all['rows'], 'balance'))->toBe([1000.0, 600.0, 850.0])->and($all['closing'])->toBe('850.00');

    $recent = $ledger->statement($c, now()->subDays(6)->toDateString());
    expect($recent['opening'])->toBe('1000.00')->and($recent['rows'])->toHaveCount(2)->and($recent['closing'])->toBe('850.00');

    $this->actingAs($this->admin)->get(route('customers.show', $c))
        ->assertInertia(fn ($p) => $p->where('contact.balance', 850)->has('statement.rows', 3));
});

it('lists customers including "both" and filters them', function () {
    $priya = Contact::factory()->create(['name' => 'Priya', 'phone' => '9876543210', 'date_of_birth' => now()->addDays(5)->subYears(30)]);
    Contact::factory()->create(['name' => 'Tailor', 'type' => 'both', 'phone' => '9111111111']);
    Contact::factory()->supplier()->create(['name' => 'Mill', 'phone' => '9222222222']);
    app(ContactLedgerService::class)->setOpeningBalance($priya, 500);

    $this->actingAs($this->admin)->get(route('customers.index'))
        ->assertInertia(fn ($p) => $p->has('contacts.data', 3));   // walk-in + Priya + Tailor
    $this->actingAs($this->admin)->get(route('suppliers.index'))
        ->assertInertia(fn ($p) => $p->has('contacts.data', 2));   // Mill + Tailor
    $this->actingAs($this->admin)->get(route('customers.index', ['search' => '98765']))
        ->assertInertia(fn ($p) => $p->has('contacts.data', 1)->where('contacts.data.0.name', 'Priya'));
    $this->actingAs($this->admin)->get(route('customers.index', ['balance' => 'receivable']))
        ->assertInertia(fn ($p) => $p->has('contacts.data', 1)->where('contacts.data.0.balance', 500));
    $this->actingAs($this->admin)->get(route('customers.index', ['occasion' => 'birthday']))
        ->assertInertia(fn ($p) => $p->has('contacts.data', 1)->where('contacts.data.0.name', 'Priya'));
});

it('does not show a pure supplier on the customer pages', function () {
    $mill = Contact::factory()->supplier()->create();

    $this->actingAs($this->admin)->get(route('customers.show', $mill))->assertNotFound();
    $this->actingAs($this->admin)->get(route('suppliers.show', $mill))->assertOk();
});

it('manages customer groups', function () {
    $this->actingAs($this->admin)->post(route('customer-groups.store'), ['name' => 'Gold', 'price_type' => 'retail', 'discount_percent' => 5])
        ->assertSessionHasNoErrors();
    $gold = CustomerGroup::where('name', 'Gold')->first();
    Contact::factory()->create(['customer_group_id' => $gold->id]);

    $this->actingAs($this->admin)->delete(route('customer-groups.destroy', $gold))->assertSessionHas('error');
    expect(CustomerGroup::find($gold->id))->not->toBeNull();
});
