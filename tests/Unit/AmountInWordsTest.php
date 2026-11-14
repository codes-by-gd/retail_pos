<?php

use App\Support\AmountInWords;

it('writes amounts in Indian words', function () {
    expect(AmountInWords::rupees(1799))->toBe('Rupees One Thousand Seven Hundred Ninety Nine Only')
        ->and(AmountInWords::rupees('125050.50'))->toBe('Rupees One Lakh Twenty Five Thousand Fifty and Fifty Paise Only')
        ->and(AmountInWords::rupees(23000000))->toBe('Rupees Two Crore Thirty Lakh Only')
        ->and(AmountInWords::rupees(0))->toBe('Rupees Zero Only');
});
