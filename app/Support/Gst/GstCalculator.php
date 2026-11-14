<?php

namespace App\Support\Gst;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Bill calculator — the single source of truth for GST on sales.
 * Mirrored exactly (integer paise) by resources/js/Pos/gst.js for the live POS preview;
 * the server recalculates every bill with this class before saving.
 *
 * Per line:
 *   gross        = unit price × qty
 *   line discount = % of gross or a fixed amount (≤ gross)
 *   bill discount = % of Σ(net) or a fixed amount, shared across lines in proportion to their net value
 *   rate          = normal rate, or the higher rate when the per-piece taxable value exceeds the price limit
 *                   (price-based garments rate; the test uses the lower rate to back out GST from inclusive prices)
 *   inclusive prices: taxable = amount × 100 / (100 + rate + cess); GST = amount − taxable − cess
 *   exclusive prices: GST = taxable × rate / 100 on top
 *   intra-state: CGST = ½ GST (rounded), SGST = rest · inter-state: IGST = GST
 * Bill total is rounded to the nearest rupee when $roundOff is on.
 */
final class GstCalculator
{
    /**
     * @param  list<array{
     *     key?: mixed, unit_price: string|int|float, quantity: string|int|float, price_includes_tax: bool,
     *     rate: string|int|float, cess_rate?: string|int|float, threshold?: string|int|float|null, rate_above?: string|int|float|null,
     *     discount_type?: ?string, discount_value?: string|int|float
     * }>  $lines
     * @param  array{type: ?string, value: string|int|float}|null  $billDiscount
     */
    public static function calculate(array $lines, ?array $billDiscount, bool $interState, bool $roundOff = true): array
    {
        $zero = BigDecimal::zero()->toScale(2);
        $rows = [];

        // 1. gross and line discounts
        foreach ($lines as $i => $l) {
            $qty = BigDecimal::of((string) $l['quantity']);
            $gross = BigDecimal::of((string) $l['unit_price'])->multipliedBy($qty)->toScale(2, RoundingMode::HALF_UP);
            $lineDiscount = self::discount($gross, $l['discount_type'] ?? null, $l['discount_value'] ?? 0);
            $rows[$i] = ['in' => $l, 'qty' => $qty, 'gross' => $gross, 'line_discount' => $lineDiscount, 'net' => $gross->minus($lineDiscount)];
        }

        // 2. bill discount, shared in proportion to net value (remainder to the last line with value)
        $sumNet = array_reduce($rows, fn ($s, $r) => $s->plus($r['net']), $zero);
        $billTotal = self::discount($sumNet, $billDiscount['type'] ?? null, $billDiscount['value'] ?? 0);
        $lastWithValue = null;
        foreach ($rows as $i => $r) {
            if ($r['net']->isPositive()) {
                $lastWithValue = $i;
            }
        }
        $allocated = $zero;
        foreach ($rows as $i => &$r) {
            if ($billTotal->isZero() || ! $r['net']->isPositive()) {
                $share = $zero;
            } elseif ($i === $lastWithValue) {
                $share = $billTotal->minus($allocated);
            } else {
                $share = $billTotal->multipliedBy($r['net'])->dividedBy($sumNet, 2, RoundingMode::HALF_UP);
            }
            $allocated = $allocated->plus($share);
            $r['bill_share'] = $share;
            $r['amount'] = $r['net']->minus($share);
        }
        unset($r);

        // 3. tax per line
        $totals = array_fill_keys(['subtotal', 'line_discount', 'bill_discount', 'taxable', 'cgst', 'sgst', 'igst', 'cess', 'lines'], $zero);
        $out = [];
        foreach ($rows as $i => $r) {
            $l = $r['in'];
            $inclusive = (bool) $l['price_includes_tax'];
            $cessRate = BigDecimal::of((string) ($l['cess_rate'] ?? 0));
            $rate = self::rateFor($r['amount'], $r['qty'], $inclusive, $l, $cessRate);
            $amount = $r['amount'];

            if ($inclusive) {
                $taxable = $amount->multipliedBy(100)->dividedBy(BigDecimal::of(100)->plus($rate)->plus($cessRate), 2, RoundingMode::HALF_UP);
                $cess = $taxable->multipliedBy($cessRate)->dividedBy(100, 2, RoundingMode::HALF_UP);
                $gst = $amount->minus($taxable)->minus($cess);
                $lineTotal = $amount;
            } else {
                $taxable = $amount;
                $gst = $taxable->multipliedBy($rate)->dividedBy(100, 2, RoundingMode::HALF_UP);
                $cess = $taxable->multipliedBy($cessRate)->dividedBy(100, 2, RoundingMode::HALF_UP);
                $lineTotal = $taxable->plus($gst)->plus($cess);
            }

            if ($interState) {
                [$cgst, $sgst, $igst] = [$zero, $zero, $gst];
            } else {
                $cgst = $gst->dividedBy(2, 2, RoundingMode::HALF_UP);
                [$sgst, $igst] = [$gst->minus($cgst), $zero];
            }

            $out[$i] = [
                'key' => $l['key'] ?? $i,
                'gross' => (string) $r['gross'],
                'line_discount' => (string) $r['line_discount'],
                'bill_discount_share' => (string) $r['bill_share'],
                'taxable_value' => (string) $taxable,
                'gst_rate' => (string) $rate->toScale(2),
                'cgst' => (string) $cgst,
                'sgst' => (string) $sgst,
                'igst' => (string) $igst,
                'cess_rate' => (string) $cessRate->toScale(2),
                'cess' => (string) $cess,
                'line_total' => (string) $lineTotal,
            ];

            $totals['subtotal'] = $totals['subtotal']->plus($r['gross']);
            $totals['line_discount'] = $totals['line_discount']->plus($r['line_discount']);
            $totals['bill_discount'] = $totals['bill_discount']->plus($r['bill_share']);
            $totals['taxable'] = $totals['taxable']->plus($taxable);
            $totals['cgst'] = $totals['cgst']->plus($cgst);
            $totals['sgst'] = $totals['sgst']->plus($sgst);
            $totals['igst'] = $totals['igst']->plus($igst);
            $totals['cess'] = $totals['cess']->plus($cess);
            $totals['lines'] = $totals['lines']->plus($lineTotal);
        }

        $grand = $roundOff ? $totals['lines']->toScale(0, RoundingMode::HALF_UP)->toScale(2) : $totals['lines'];

        return [
            'lines' => array_values($out),
            'totals' => [
                'subtotal' => (string) $totals['subtotal'],
                'line_discount_total' => (string) $totals['line_discount'],
                'bill_discount_total' => (string) $totals['bill_discount'],
                'taxable_total' => (string) $totals['taxable'],
                'cgst_total' => (string) $totals['cgst'],
                'sgst_total' => (string) $totals['sgst'],
                'igst_total' => (string) $totals['igst'],
                'cess_total' => (string) $totals['cess'],
                'tax_total' => (string) $totals['cgst']->plus($totals['sgst'])->plus($totals['igst'])->plus($totals['cess']),
                'round_off' => (string) $grand->minus($totals['lines']),
                'grand_total' => (string) $grand,
            ],
        ];
    }

    /** Percent of base (capped at 100%) or a fixed amount (capped at base). */
    private static function discount(BigDecimal $base, ?string $type, string|int|float $value): BigDecimal
    {
        $value = BigDecimal::of((string) ($value ?: 0));
        if (! $type || ! $value->isPositive() || ! $base->isPositive()) {
            return BigDecimal::zero()->toScale(2);
        }

        $amount = $type === 'percent'
            ? $base->multipliedBy(BigDecimal::min($value, BigDecimal::of(100)))->dividedBy(100, 2, RoundingMode::HALF_UP)
            : $value->toScale(2, RoundingMode::HALF_UP);

        return BigDecimal::min($amount, $base)->toScale(2);
    }

    /**
     * Price-based rate: use rate_above when the taxable value of ONE piece exceeds the threshold.
     * Exact comparison (no rounding): inclusive → amount × 100 > threshold × qty × (100 + rate + cess).
     */
    private static function rateFor(BigDecimal $amount, BigDecimal $qty, bool $inclusive, array $line, BigDecimal $cessRate): BigDecimal
    {
        $rate = BigDecimal::of((string) $line['rate']);
        $threshold = $line['threshold'] ?? null;
        $above = $line['rate_above'] ?? null;

        if ($threshold === null || $above === null || ! $qty->isPositive()) {
            return $rate;
        }

        $threshold = BigDecimal::of((string) $threshold);
        $exceeds = $inclusive
            ? $amount->multipliedBy(100)->isGreaterThan($threshold->multipliedBy($qty)->multipliedBy(BigDecimal::of(100)->plus($rate)->plus($cessRate)))
            : $amount->isGreaterThan($threshold->multipliedBy($qty));

        return $exceeds ? BigDecimal::of((string) $above) : $rate;
    }
}
