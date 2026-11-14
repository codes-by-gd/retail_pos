<?php

namespace Database\Seeders;

use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\CustomerGroup;
use Illuminate\Database\Seeder;

/** The built-in walk-in customer and default customer groups. Idempotent. */
class DefaultContactsSeeder extends Seeder
{
    public function run(): void
    {
        Contact::firstOrCreate(['is_walk_in' => true], [
            'code' => 'WALK-IN', 'type' => Contact::CUSTOMER, 'name' => 'Walk-in Customer', 'is_active' => true,
        ]);

        CustomerGroup::firstOrCreate(['name' => 'Regular'], ['price_type' => CustomerGroup::PRICE_RETAIL, 'discount_percent' => 0]);
        CustomerGroup::firstOrCreate(['name' => 'Wholesale'], ['price_type' => CustomerGroup::PRICE_WHOLESALE, 'discount_percent' => 0]);
    }
}
