<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Masters a real shop needs — no demo users, products or sales. Safe to re-run (idempotent).
 * Used by `php artisan pos:install`; run again after upgrades that add permissions.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([RolesAndPermissionsSeeder::class, DefaultCatalogSeeder::class, DefaultContactsSeeder::class, DefaultExpenseCategoriesSeeder::class]);
    }
}
