<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Settings\Models\Branch;
use App\Rules\Gstin;
use App\Support\Gst\IndianStates;
use App\Support\Permissions;
use Database\Seeders\ProductionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\search;
use function Laravel\Prompts\text;

/**
 * First-time setup of a live shop: tables, masters, business details, first branch + counter and the first Admin.
 * Interactive by default; every answer can also be passed as an option (for scripted installs).
 */
class InstallCommand extends Command
{
    protected $signature = 'pos:install
        {--business= : Business name}
        {--state= : GST state code of the business, e.g. 24 (Gujarat)}
        {--gstin= : Business GSTIN (optional)}
        {--branch= : First branch name, e.g. "Main Store"}
        {--branch-code= : Branch code used in invoice numbers (up to 6 letters/digits), e.g. BR1}
        {--admin-name= : Admin full name}
        {--admin-email= : Admin login email}
        {--admin-password= : Admin password (prompted when omitted)}';

    protected $description = 'Set up a new shop: database, masters, first branch and the first Admin user (no demo data)';

    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->error('This system already has users — pos:install is only for a brand-new installation.');
            $this->line('Upgrading? Run: php artisan migrate --force && php artisan db:seed --class=ProductionSeeder --force');

            return self::FAILURE;
        }

        $this->components->info('Setting up the database');
        $this->call('migrate', ['--force' => true]);
        $this->call('db:seed', ['--class' => ProductionSeeder::class, '--force' => true]);

        $states = IndianStates::options();
        $answers = [
            'business' => $this->option('business') ?? text('Business name', required: true),
            'state' => $this->option('state') ?? search('State (GST)', fn ($q) => collect($states)
                ->filter(fn ($s) => str_contains(strtolower($s['label']), strtolower($q)))->pluck('label', 'value')->all()),
            'gstin' => $this->option('gstin') ?? text('Business GSTIN (leave empty if not registered)'),
            'branch' => $this->option('branch') ?? text('First branch name', default: 'Main Store', required: true),
            'branch_code' => strtoupper($this->option('branch-code') ?? text('Branch code (used in invoice numbers)', default: 'BR1', required: true)),
            'name' => $this->option('admin-name') ?? text('Admin name', required: true),
            'email' => $this->option('admin-email') ?? text('Admin email (login)', required: true),
            'password' => $this->option('admin-password') ?? password('Admin password (min. 8 characters, letters and numbers)', required: true),
        ];
        $answers['gstin'] = $answers['gstin'] ? strtoupper(trim($answers['gstin'])) : null;

        $v = Validator::make($answers, [
            'business' => ['required', 'string', 'max:150'],
            'state' => ['required', 'in:'.implode(',', IndianStates::codes())],
            'gstin' => ['nullable', new Gstin, fn ($a, $value, $fail) => $value && substr($value, 0, 2) !== $answers['state'] ? $fail('The GSTIN does not belong to the chosen state.') : null],
            'branch' => ['required', 'string', 'max:100'],
            'branch_code' => ['required', 'regex:/^[A-Z0-9]{1,6}$/'],
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150'],
            'password' => ['required', Password::min(8)->letters()->numbers()],
        ]);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($answers) {
            settings()->set(array_filter([
                'business.name' => $answers['business'],
                'business.state_code' => $answers['state'],
                'business.gstin' => $answers['gstin'],
            ], fn ($v) => $v !== null));

            $branch = Branch::create(['name' => $answers['branch'], 'code' => $answers['branch_code'], 'state_code' => $answers['state'], 'is_active' => true]);
            $branch->registers()->create(['name' => 'Counter 1']);

            $admin = User::create(['name' => $answers['name'], 'email' => $answers['email'], 'password' => $answers['password'], 'is_active' => true]);
            $admin->syncRoles([Permissions::ADMIN_ROLE]);
            $admin->branches()->sync([$branch->id]);
        });

        $this->call('storage:link');

        $this->components->info('Done. Next steps:');
        $this->components->bulletList([
            "Log in as {$answers['email']} and complete Settings → Business (address, logo, invoice text).",
            'Check GST rates under Catalog Setup, add staff under Users, then products (or import a CSV).',
            'Set up cron, the Reverb process and backups — see docs/10-deployment.md.',
        ]);

        return self::SUCCESS;
    }
}
