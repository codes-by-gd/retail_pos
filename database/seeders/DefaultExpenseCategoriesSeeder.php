<?php

namespace Database\Seeders;

use App\Modules\Expenses\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

/** Typical running costs of a clothing store. Safe to re-run. */
class DefaultExpenseCategoriesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ExpenseCategory::DEFAULTS as $name) {
            ExpenseCategory::firstOrCreate(['name' => $name]);
        }
    }
}
