<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\ProductBarcode;

class BarcodeService
{
    /**
     * In-store EAN-13 for a variant: "2" prefix (GS1 range reserved for internal use),
     * 11-digit body derived from the variant id, plus check digit. E.g. variant 42 → 2000000000428.
     */
    public function generateFor(int $variantId): string
    {
        for ($prefix = 20; $prefix <= 29; $prefix++) {
            $body = $prefix.str_pad((string) $variantId, 10, '0', STR_PAD_LEFT);
            $code = $body.self::ean13CheckDigit($body);
            if (! ProductBarcode::where('barcode', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException("Could not generate a unique barcode for variant {$variantId}.");
    }

    public static function ean13CheckDigit(string $first12): int
    {
        $sum = 0;
        foreach (str_split($first12) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - ($sum % 10)) % 10;
    }

    public static function isValidEan13(string $code): bool
    {
        return (bool) preg_match('/^\d{13}$/', $code) && self::ean13CheckDigit(substr($code, 0, 12)) === (int) $code[12];
    }
}
