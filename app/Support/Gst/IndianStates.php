<?php

namespace App\Support\Gst;

/**
 * GST state / union-territory codes (first two digits of a GSTIN).
 * The state of the seller (branch) vs. the place of supply (customer) decides
 * whether a sale is intra-state (CGST + SGST) or inter-state (IGST).
 */
final class IndianStates
{
    public const ALL = [
        '01' => 'Jammu and Kashmir',
        '02' => 'Himachal Pradesh',
        '03' => 'Punjab',
        '04' => 'Chandigarh',
        '05' => 'Uttarakhand',
        '06' => 'Haryana',
        '07' => 'Delhi',
        '08' => 'Rajasthan',
        '09' => 'Uttar Pradesh',
        '10' => 'Bihar',
        '11' => 'Sikkim',
        '12' => 'Arunachal Pradesh',
        '13' => 'Nagaland',
        '14' => 'Manipur',
        '15' => 'Mizoram',
        '16' => 'Tripura',
        '17' => 'Meghalaya',
        '18' => 'Assam',
        '19' => 'West Bengal',
        '20' => 'Jharkhand',
        '21' => 'Odisha',
        '22' => 'Chhattisgarh',
        '23' => 'Madhya Pradesh',
        '24' => 'Gujarat',
        '26' => 'Dadra and Nagar Haveli and Daman and Diu',
        '27' => 'Maharashtra',
        '29' => 'Karnataka',
        '30' => 'Goa',
        '31' => 'Lakshadweep',
        '32' => 'Kerala',
        '33' => 'Tamil Nadu',
        '34' => 'Puducherry',
        '35' => 'Andaman and Nicobar Islands',
        '36' => 'Telangana',
        '37' => 'Andhra Pradesh',
        '38' => 'Ladakh',
        '97' => 'Other Territory',
    ];

    /** @return list<string> Note: PHP casts keys like '24' to int, so cast back to keep '24' === '24'. */
    public static function codes(): array
    {
        return array_map(fn ($code) => str_pad((string) $code, 2, '0', STR_PAD_LEFT), array_keys(self::ALL));
    }

    public static function name(?string $code): ?string
    {
        return $code === null ? null : (self::ALL[$code] ?? self::ALL[(int) $code] ?? null);
    }

    /** @return list<array{value: string, label: string}> for select inputs */
    public static function options(): array
    {
        return collect(self::ALL)
            ->map(fn ($name, $code) => ['value' => $c = str_pad((string) $code, 2, '0', STR_PAD_LEFT), 'label' => "{$c} - {$name}"])
            ->values()
            ->all();
    }
}
