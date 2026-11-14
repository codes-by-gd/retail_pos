<?php

use App\Support\Gst\GstCalculator;

// Garments: 5% up to ₹2,500 per piece (taxable), 18% above.
function garment(string|float $price, string|float $qty = 1, array $extra = []): array
{
    return ['unit_price' => $price, 'quantity' => $qty, 'price_includes_tax' => true, 'rate' => 5, 'threshold' => 2500, 'rate_above' => 18, ...$extra];
}

it('backs GST out of an inclusive garment price and splits CGST/SGST', function () {
    $r = GstCalculator::calculate([garment(1799)], null, interState: false);

    expect($r['lines'][0])->taxable_value->toBe('1713.33')->gst_rate->toBe('5.00')
        ->cgst->toBe('42.84')->sgst->toBe('42.83')->igst->toBe('0.00')->line_total->toBe('1799.00')
        ->and($r['totals']['grand_total'])->toBe('1799.00');
});

it('applies 18% above ₹2,500 per piece and 5% once discounts bring it below', function () {
    $full = GstCalculator::calculate([garment(2999)], null, false)['lines'][0];
    expect($full)->gst_rate->toBe('18.00')->taxable_value->toBe('2541.53');

    $tenOff = GstCalculator::calculate([garment(2999, 1, ['discount_type' => 'percent', 'discount_value' => 10])], null, false)['lines'][0];
    expect($tenOff)->gst_rate->toBe('18.00')->line_total->toBe('2699.10');

    $fifteenOff = GstCalculator::calculate([garment(2999, 1, ['discount_type' => 'percent', 'discount_value' => 15])], null, false)['lines'][0];
    expect($fifteenOff)->gst_rate->toBe('5.00')->taxable_value->toBe('2427.76')->line_total->toBe('2549.15');
});

it('tests the limit per piece, not per line', function () {
    // 3 shirts at ₹1,799 = ₹5,397 on the line, but each piece is under the limit → 5%
    expect(GstCalculator::calculate([garment(1799, 3)], null, false)['lines'][0]['gst_rate'])->toBe('5.00');
});

it('uses the limit inclusive of exactly ₹2,500 taxable', function () {
    // ₹2,625 incl. 5% = ₹2,500 taxable → still 5%; one paisa more → 18%
    expect(GstCalculator::calculate([garment(2625)], null, false)['lines'][0]['gst_rate'])->toBe('5.00')
        ->and(GstCalculator::calculate([garment('2625.01')], null, false)['lines'][0]['gst_rate'])->toBe('18.00');
});

it('adds GST on top for exclusive prices and uses IGST for other states', function () {
    $line = ['unit_price' => 1000, 'quantity' => 2, 'price_includes_tax' => false, 'rate' => 18];

    $intra = GstCalculator::calculate([$line], null, false);
    expect($intra['lines'][0])->taxable_value->toBe('2000.00')->cgst->toBe('180.00')->sgst->toBe('180.00')
        ->and($intra['totals']['grand_total'])->toBe('2360.00');

    $inter = GstCalculator::calculate([$line], null, true);
    expect($inter['lines'][0])->igst->toBe('360.00')->cgst->toBe('0.00')
        ->and($inter['totals']['igst_total'])->toBe('360.00');
});

it('shares a bill discount across lines without losing a paisa', function () {
    $r = GstCalculator::calculate([garment(1000), garment(500)], ['type' => 'amount', 'value' => 100], false);

    expect(array_column($r['lines'], 'bill_discount_share'))->toBe(['66.67', '33.33'])
        ->and($r['totals']['bill_discount_total'])->toBe('100.00')
        ->and($r['totals']['grand_total'])->toBe('1400.00');

    $pct = GstCalculator::calculate([garment(1799), garment(549)], ['type' => 'percent', 'value' => 5], false);
    expect($pct['totals']['bill_discount_total'])->toBe('117.40');   // 5% of 2348
});

it('rounds the bill to the nearest rupee', function () {
    // 1.25 m of fabric at ₹290 = ₹362.50 → ₹363
    $fabric = ['unit_price' => 290, 'quantity' => '1.25', 'price_includes_tax' => true, 'rate' => 5];
    $r = GstCalculator::calculate([$fabric], null, false);
    expect($r['totals'])->grand_total->toBe('363.00')->round_off->toBe('0.50');

    expect(GstCalculator::calculate([$fabric], null, false, roundOff: false)['totals']['grand_total'])->toBe('362.50');
});

it('caps discounts at the amount and handles cess', function () {
    $r = GstCalculator::calculate([garment(500, 1, ['discount_type' => 'amount', 'discount_value' => 900])], ['type' => 'percent', 'value' => 150], false);
    expect($r['totals'])->line_discount_total->toBe('500.00')->grand_total->toBe('0.00');

    $cess = GstCalculator::calculate([['unit_price' => 100, 'quantity' => 1, 'price_includes_tax' => false, 'rate' => 18, 'cess_rate' => 12]], null, false);
    expect($cess['lines'][0])->cess->toBe('12.00')->line_total->toBe('130.00');
});

it('keeps totals equal to the sum of lines', function () {
    $r = GstCalculator::calculate([garment(1799, 2), garment(549, 3), garment(2999)], ['type' => 'percent', 'value' => 7], true);
    $sum = array_sum(array_map(fn ($l) => (float) $l['line_total'], $r['lines']));

    expect(round($sum + (float) $r['totals']['round_off'], 2))->toBe((float) $r['totals']['grand_total']);
});
