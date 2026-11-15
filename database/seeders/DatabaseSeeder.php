<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Settings\Models\Branch;
use App\Rules\Gstin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    /**
     * Demo data for local development. All demo users have the password "password".
     */
    public function run(): void
    {
        $this->call([RolesAndPermissionsSeeder::class, DefaultCatalogSeeder::class, DefaultContactsSeeder::class, DefaultExpenseCategoriesSeeder::class]);

        $gstin = '24AABCR1234A1Z';
        settings()->set([
            'business.name' => 'Demo Fashion House',
            'business.gstin' => $gstin.Gstin::checksum($gstin),
            'business.state_code' => '24',
            'business.city' => 'Vadodara',
        ]);

        $main = Branch::firstOrCreate(['code' => 'BR1'], ['name' => 'Main Store', 'state_code' => '24', 'city' => 'Vadodara']);
        $second = Branch::firstOrCreate(['code' => 'BR2'], ['name' => 'City Mall Outlet', 'state_code' => '24', 'city' => 'Vadodara']);

        foreach ([$main, $second] as $branch) {
            $branch->registers()->firstOrCreate(['name' => 'Counter 1']);
        }
        $main->registers()->firstOrCreate(['name' => 'Counter 2']);

        $users = [
            ['Admin', 'admin@pos.test', 'Admin', [$main->id, $second->id]],
            ['Store Manager', 'manager@pos.test', 'Manager', [$main->id, $second->id]],
            ['Counter Cashier', 'cashier@pos.test', 'Cashier', [$main->id]],
            ['Stock Keeper', 'stock@pos.test', 'Stock Keeper', [$main->id]],
        ];

        foreach ($users as [$name, $email, $role, $branches]) {
            $user = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => 'password', 'pin' => '1234', 'is_active' => true]);
            $user->syncRoles([$role]);
            $user->branches()->sync($branches);
        }

        // Demo catalog, contacts and trading history are created "in the past" so the history reads naturally.
        $fresh = ! Product::withTrashed()->exists();
        if ($fresh) {
            Carbon::setTestNow(now()->subDays(DemoSalesSeeder::DAYS + 1)->setTime(9, 0));
        }
        $this->call([DemoCatalogSeeder::class, DemoContactsSeeder::class]);
        Carbon::setTestNow();
        if ($fresh) {
            $this->call(DemoSalesSeeder::class);
        }
    }
}
