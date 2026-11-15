<?php

use App\Modules\Settings\Services\BackupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

it('shows the audit log only with permission, with changes and filters', function () {
    $admin = userWithRole('Admin');
    $manager = userWithRole('Manager');
    $this->actingAs($manager)->get(route('activity.index'))->assertOk();   // Manager has activity.view
    $this->actingAs(userWithRole('Cashier'))->get(route('activity.index'))->assertForbidden();

    $manager->update(['name' => 'Renamed Manager']);   // model event with old → new
    activity()->causedBy($admin)->withProperties(['file' => 'x'])->log('Created database backup');

    $page = $this->actingAs($admin)->get(route('activity.index', ['search' => 'backup']))->viewData('page')['props']['activities']['data'];
    expect($page)->toHaveCount(1)->and($page[0])->user->toBe($admin->name)->and($page[0]['details'][0]['value'])->toBe('x');

    $page = $this->actingAs($admin)->get(route('activity.index', ['subject' => 'User']))->viewData('page')['props']['activities']['data'];
    $renamed = collect($page)->first(fn ($a) => collect($a['changes'])->contains('new', 'Renamed Manager'));
    expect($renamed)->not->toBeNull()->and($renamed['description'])->toBe('Updated user');
});

it('logs logins and permission changes', function () {
    $user = userWithRole('Manager');
    $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);
    expect(Activity::where('description', 'Logged in')->where('causer_id', $user->id)->exists())->toBeTrue();

    $role = Role::findByName('Cashier');
    $this->actingAs(userWithRole('Admin'))->put(route('roles.update', $role), ['name' => 'Cashier', 'permissions' => ['pos.access', 'sales.view']])->assertRedirect();
    $log = Activity::where('description', 'Changed role permissions')->latest('id')->first();
    expect($log->properties['removed'])->toContain('pos.discount');
});

beforeEach(function () {
    config(['backups.path' => sys_get_temp_dir().'/pos-backups-'.uniqid()]);
});

afterEach(function () {
    File::deleteDirectory(config('backups.path'));
});

it('lists, downloads and deletes backups without allowing other files', function () {
    $service = app(BackupService::class);
    $dir = $service->directory();
    File::put("{$dir}/pos-20261115-120000.sql.gz", gzencode('-- dump'));
    File::put("{$dir}/notes.txt", 'secret');
    $admin = userWithRole('Admin');

    try {
        $this->actingAs($admin)->get(route('settings.backups.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->has('backups', 1)->where('backups.0.name', 'pos-20261115-120000.sql.gz'));
        $this->actingAs($admin)->get(route('settings.backups.download', 'pos-20261115-120000.sql.gz'))->assertOk();
        $this->actingAs($admin)->get(route('settings.backups.download', 'notes.txt'))->assertNotFound();
        $this->actingAs($admin)->get('/settings/backups/..%2F..%2F.env')->assertNotFound();
        $this->actingAs(userWithRole('Manager'))->get(route('settings.backups.index'))->assertForbidden();

        // "Back up now": a real dump on MySQL; in-memory SQLite can't be backed up → friendly error
        $response = $this->actingAs($admin)->post(route('settings.backups.store'));
        if (DB::connection()->getDriverName() === 'sqlite') {
            $response->assertSessionHas('error');
        } else {
            $response->assertSessionHas('success');
            expect($service->list())->toHaveCount(2);
        }

        $this->actingAs($admin)->delete(route('settings.backups.destroy', 'pos-20261115-120000.sql.gz'))->assertSessionHas('success');
        expect(File::exists("{$dir}/pos-20261115-120000.sql.gz"))->toBeFalse()->and(File::exists("{$dir}/notes.txt"))->toBeTrue();
    } finally {
        File::delete(["{$dir}/notes.txt", "{$dir}/pos-20261115-120000.sql.gz"]);
    }
});

it('prunes old backups and reports failures as alerts', function () {
    $service = app(BackupService::class);
    $dir = $service->directory();
    File::put("{$dir}/pos-20200101-000000.sql.gz", 'old');
    touch("{$dir}/pos-20200101-000000.sql.gz", now()->subDays(30)->getTimestamp());
    expect($service->prune(14))->toBe(1);

    $admin = userWithRole('Admin');
    $this->mock(BackupService::class)->shouldReceive('create')->andThrow(new DomainException('disk full'));
    $this->artisan('pos:backup')->assertFailed();
    expect($admin->fresh()->notifications->first()->data['type'])->toBe('backup');
});
