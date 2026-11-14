<?php

namespace Database\Seeders;

use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\CustomerGroup;
use App\Modules\Contacts\Services\ContactService;
use App\Rules\Gstin;
use Illuminate\Database\Seeder;

/** Demo customers and apparel suppliers. Skipped if non-walk-in contacts exist. */
class DemoContactsSeeder extends Seeder
{
    public function run(ContactService $contacts): void
    {
        if (Contact::where('is_walk_in', false)->withTrashed()->exists()) {
            return;
        }

        $gold = CustomerGroup::firstOrCreate(['name' => 'Gold Members'], ['price_type' => 'retail', 'discount_percent' => 5]);
        $wholesale = CustomerGroup::where('name', 'Wholesale')->value('id');
        $gstin = fn (string $first14) => $first14.Gstin::checksum($first14);

        // Birthdays relative to today so the "upcoming" filter has data.
        $soon = fn (int $days, int $age) => now()->addDays($days)->subYears($age)->toDateString();

        $customers = [
            ['Priya Patel', '9876543210', 'Alkapuri', 'Vadodara', '390007', $gold->id, 0, $soon(3, 32), null, 0],
            ['Rahul Mehta', '9898012345', 'Manjalpur', 'Vadodara', '390011', null, 0, $soon(45, 28), $soon(10, 3), 0],
            ['Anjali Desai', '9925098765', 'Gotri Road', 'Vadodara', '390021', $gold->id, 5000, $soon(120, 41), null, 1850],
            ['Vikram Singh', '9712345678', 'Nizampura', 'Vadodara', '390002', null, 0, null, null, 0],
            ['Sneha Joshi', '9824198241', 'Karelibaug', 'Vadodara', '390018', null, 0, $soon(20, 25), null, 0],
            ['Mohammed Shaikh', '9909912345', 'Fatehgunj', 'Vadodara', '390002', null, 0, null, $soon(5, 8), 0],
        ];
        foreach ($customers as [$name, $phone, $address, $city, $pin, $group, $credit, $dob, $anniv, $opening]) {
            $contacts->create([
                'type' => Contact::CUSTOMER, 'name' => $name, 'phone' => $phone, 'address' => $address, 'city' => $city,
                'pincode' => $pin, 'state_code' => '24', 'customer_group_id' => $group, 'credit_limit' => $credit,
                'date_of_birth' => $dob, 'anniversary' => $anniv, 'is_active' => true, 'opening_balance' => $opening,
            ]);
        }

        // B2B customer (boutique) from another state — IGST will apply.
        $contacts->create([
            'type' => Contact::CUSTOMER, 'name' => 'Kavita Rao', 'company_name' => 'Kavita Boutique', 'phone' => '9820098200',
            'gstin' => $gstin('27AAKFK4321B1Z'), 'state_code' => '27', 'address' => 'Linking Road, Bandra', 'city' => 'Mumbai',
            'pincode' => '400050', 'customer_group_id' => $wholesale, 'credit_limit' => 50000, 'is_active' => true, 'opening_balance' => 12500,
        ]);

        $suppliers = [
            ['Ramesh Shah', 'Shah Textiles', '9824012345', $gstin('24AAFFS1234K1Z'), '24', 'Ring Road', 'Surat', '395002', 30, -45000],
            ['Arvind Kumar', 'Arvind Garments Pvt Ltd', '8041234567', $gstin('29AACCA5678L1Z'), '29', 'Peenya Industrial Area', 'Bengaluru', '560058', 45, -82000],
            ['Sanjay Jain', 'Jain Hosiery Works', '9414012345', $gstin('08AAEFJ9876M1Z'), '08', 'Sanganer', 'Jaipur', '302029', 15, 0],
            ['Neha Kapoor', 'Kapoor Fashion Distributors', '9811098110', $gstin('07AAHFK2468N1Z'), '07', 'Gandhi Nagar', 'Delhi', '110031', 30, -15500],
        ];
        foreach ($suppliers as [$name, $company, $phone, $gst, $state, $address, $city, $pin, $terms, $opening]) {
            $contacts->create([
                'type' => Contact::SUPPLIER, 'name' => $name, 'company_name' => $company, 'phone' => $phone, 'gstin' => $gst,
                'state_code' => $state, 'address' => $address, 'city' => $city, 'pincode' => $pin, 'payment_terms_days' => $terms,
                'is_active' => true, 'opening_balance' => $opening,
            ]);
        }

        // A tailor who buys fabric from us and does job work for us.
        $contacts->create([
            'type' => Contact::BOTH, 'name' => 'Imran Tailors', 'phone' => '9601234567', 'address' => 'Mandvi', 'city' => 'Vadodara',
            'state_code' => '24', 'credit_limit' => 10000, 'payment_terms_days' => 7, 'is_active' => true,
            'notes' => 'Alterations and stitching job work.',
        ]);
    }
}
