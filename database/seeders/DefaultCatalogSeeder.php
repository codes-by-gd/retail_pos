<?php

namespace Database\Seeders;

use App\Modules\Inventory\Models\Attribute;
use App\Modules\Inventory\Models\TaxRate;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Masters every clothing store needs: GST rates, units, and size/colour attributes. Idempotent.
 *
 * GST on garments is price-based (rates as revised from 22 Sep 2025): 5% when the sale value of a
 * piece is up to ₹2,500, 18% above. Verify with your tax adviser and adjust under Catalog setup → GST rates.
 */
class DefaultCatalogSeeder extends Seeder
{
    public function run(): void
    {
        TaxRate::firstOrCreate(['name' => 'Garments'], ['rate' => 5, 'cess_rate' => 0, 'threshold_amount' => 2500, 'rate_above' => 18, 'is_active' => true]);
        foreach ([0, 5, 18] as $rate) {
            TaxRate::firstOrCreate(['name' => "GST {$rate}%"], ['rate' => $rate, 'cess_rate' => 0, 'is_active' => true]);
        }

        $units = [
            ['Pieces', 'pcs', false], ['Set', 'set', false], ['Pair', 'pair', false], ['Metre', 'mtr', true],
            ['Dozen', 'dzn', false], ['Pack', 'pack', false], ['Box', 'box', false],
        ];
        foreach ($units as [$name, $short, $decimal]) {
            Unit::firstOrCreate(['short_name' => $short], ['name' => $name, 'allow_decimal' => $decimal]);
        }

        $attributes = [
            'Colour' => ['Black', 'White', 'Navy', 'Blue', 'Sky Blue', 'Grey', 'Red', 'Maroon', 'Green', 'Olive', 'Yellow', 'Mustard',
                'Pink', 'Peach', 'Purple', 'Teal', 'Beige', 'Khaki', 'Brown', 'Cream', 'Multicolour'],
            'Size' => ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL', '4XL', 'Free Size'],
            'Shirt Size' => ['36', '38', '39', '40', '42', '44', '46'],
            'Waist' => ['26', '28', '30', '32', '34', '36', '38', '40', '42'],
            'Kids Age' => ['0-6M', '6-12M', '1-2Y', '2-3Y', '3-4Y', '4-5Y', '5-6Y', '6-7Y', '7-8Y', '8-9Y', '9-10Y', '10-12Y', '12-14Y'],
        ];
        $order = 0;
        foreach ($attributes as $name => $values) {
            // Colour first so variant names read "Navy / 40".
            $attribute = Attribute::firstOrCreate(['name' => $name], ['sort_order' => $order++]);
            foreach ($values as $i => $value) {
                $attribute->values()->firstOrCreate(['value' => $value], ['sort_order' => $i]);
            }
        }
    }
}
