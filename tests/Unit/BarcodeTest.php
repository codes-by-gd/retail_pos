<?php

use App\Modules\Inventory\Services\BarcodeService;

it('computes EAN-13 check digits', function () {
    expect(BarcodeService::ean13CheckDigit('890100000000'))->toBe(BarcodeService::ean13CheckDigit('890100000000'))
        ->and(BarcodeService::isValidEan13('4006381333931'))->toBeTrue()   // standard example
        ->and(BarcodeService::isValidEan13('4006381333932'))->toBeFalse()
        ->and(BarcodeService::isValidEan13('12345'))->toBeFalse();
});
