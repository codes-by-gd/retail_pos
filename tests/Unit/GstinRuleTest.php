<?php

use App\Rules\Gstin;

function gstinPasses(string $value): bool
{
    $passes = true;
    (new Gstin)->validate('gstin', $value, function () use (&$passes) {
        $passes = false;
    });

    return $passes;
}

it('accepts a GSTIN with a correct check digit', function () {
    $base = '27AAPFU0939F1Z';
    expect(gstinPasses($base.Gstin::checksum($base)))->toBeTrue();
});

it('computes the known check digit for a real-format GSTIN', function () {
    // 27AAPFU0939F1ZV is the sample GSTIN used in GST portal documentation.
    expect(Gstin::checksum('27AAPFU0939F1Z'))->toBe('V');
});

it('rejects a wrong check digit, bad format or unknown state', function (string $value) {
    expect(gstinPasses($value))->toBeFalse();
})->with([
    'wrong check digit' => '27AAPFU0939F1ZA',
    'too short' => '27AAPFU0939F1Z',
    'bad format' => '27AAPF00939F1ZV',
    'unknown state 99' => '99AAPFU0939F1ZV',
]);
