<?php

use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Services\ContactLedgerService;
use App\Modules\Settings\Models\Branch;
use Database\Seeders\DefaultContactsSeeder;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    (new DefaultContactsSeeder)->run();
    $this->branch = Branch::factory()->create();
    $this->cashier = userWithRole('Cashier', [$this->branch]);
});

it('finds customers by phone for the POS, exact match first', function () {
    Contact::factory()->create(['name' => 'Aaron', 'phone' => '9876543299']);
    $exact = Contact::factory()->create(['name' => 'Zed', 'phone' => '9876543210']);
    app(ContactLedgerService::class)->setOpeningBalance($exact, 300);
    Contact::factory()->supplier()->create(['name' => 'Supplier', 'phone' => '9876543211']);

    $this->actingAs($this->cashier)->getJson(route('contacts.search', ['q' => '98765 43210']))
        ->assertOk()
        ->assertJsonPath('data.0.id', $exact->id)
        ->assertJsonPath('data.0.balance', 300)
        ->assertJsonCount(1, 'data');   // only exact prefix/suffix matches; suppliers excluded
});

it('quick-adds a customer from the POS', function () {
    $this->actingAs($this->cashier)->postJson(route('contacts.quick-store'), ['name' => 'New Buyer', 'phone' => '+91 99999 88888'])
        ->assertCreated()
        ->assertJsonPath('data.phone', '9999988888')
        ->assertJsonPath('data.code', 'CUS-00001');

    $this->actingAs($this->cashier)->postJson(route('contacts.quick-store'), ['name' => 'Dup', 'phone' => '9999988888'])
        ->assertUnprocessable()->assertJsonValidationErrors('phone');
});

it('imports and exports customers', function () {
    $admin = userWithRole('Admin', [$this->branch]);
    $existing = Contact::factory()->create(['code' => 'CUS-00009', 'name' => 'Old name', 'phone' => '9000000009']);

    $path = tempnam(sys_get_temp_dir(), 'csv');
    $h = fopen($path, 'w');
    foreach ([
        ['code', 'name', 'phone', 'state', 'date_of_birth', 'opening_balance'],
        ['', 'Riya Shah', '98250 11111', 'Gujarat', '1995-02-10', '750'],
        ['CUS-00009', 'New name', '9000000009', '24', '', ''],
        ['', 'No phone', '', '', '', ''],
        ['', 'Dup phone', '9000000009', '', '', ''],
    ] as $r) {
        fputcsv($h, $r, escape: '');
    }
    fclose($h);

    $this->actingAs($admin)->post(route('customers.import.store'), ['file' => new UploadedFile($path, 'c.csv', 'text/csv', null, true)])
        ->assertSessionHas('import_result', fn ($r) => $r['created'] === 1 && $r['updated'] === 1 && count($r['errors']) === 2);

    $riya = Contact::where('name', 'Riya Shah')->first();
    expect($riya)->phone->toBe('9825011111')->state_code->toBe('24')
        ->and($riya->balance())->toBe('750.00')
        ->and($existing->fresh()->name)->toBe('New name');

    $csv = $this->actingAs($admin)->get(route('customers.export'))->assertOk()->streamedContent();
    expect($csv)->toContain('Riya Shah')->not->toContain('Walk-in');
});

it('renders every contacts page', function () {
    $admin = userWithRole('Admin', [$this->branch]);
    $c = Contact::factory()->create();
    $s = Contact::factory()->supplier()->create();

    foreach ([
        route('customers.index') => 'Contacts/Index', route('customers.create') => 'Contacts/Form',
        route('customers.show', $c) => 'Contacts/Show', route('customers.edit', $c) => 'Contacts/Form',
        route('customers.import') => 'Contacts/Import', route('customer-groups.index') => 'Contacts/Groups',
        route('suppliers.index') => 'Contacts/Index', route('suppliers.create') => 'Contacts/Form',
        route('suppliers.show', $s) => 'Contacts/Show', route('suppliers.edit', $s) => 'Contacts/Form',
        route('suppliers.import') => 'Contacts/Import',
    ] as $url => $component) {
        $this->actingAs($admin)->get($url)->assertOk()->assertInertia(fn ($p) => $p->component($component));
    }
});
