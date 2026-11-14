<?php

namespace App\Rules;

use App\Support\Gst\IndianStates;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a 15-character GSTIN: format, state code and check digit.
 * Format: 2-digit state code + 10-char PAN + entity number + 'Z' + checksum.
 */
class Gstin implements ValidationRule
{
    private const CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $gstin = strtoupper(trim((string) $value));

        if (! preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $gstin)) {
            $fail('The :attribute is not a valid GSTIN format.');

            return;
        }

        if (! in_array(substr($gstin, 0, 2), IndianStates::codes(), true)) {
            $fail('The :attribute has an invalid state code.');

            return;
        }

        if (self::checksum(substr($gstin, 0, 14)) !== $gstin[14]) {
            $fail('The :attribute check digit is incorrect.');
        }
    }

    public static function checksum(string $first14): string
    {
        $sum = 0;
        for ($i = 0; $i < 14; $i++) {
            $product = strpos(self::CHARS, $first14[$i]) * ($i % 2 === 0 ? 1 : 2);
            $sum += intdiv($product, 36) + ($product % 36);
        }

        return self::CHARS[(36 - ($sum % 36)) % 36];
    }
}
