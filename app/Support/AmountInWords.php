<?php

namespace App\Support;

/** Indian-style amount in words: 125050.50 → "Rupees One Lakh Twenty Five Thousand Fifty and Fifty Paise Only". */
final class AmountInWords
{
    private const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
        'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];

    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    public static function rupees(string|float|int $amount): string
    {
        $amount = round((float) $amount, 2);
        $rupees = (int) floor($amount);
        $paise = (int) round(($amount - $rupees) * 100);

        $words = $rupees === 0 ? 'Zero' : self::number($rupees);
        $out = "Rupees {$words}";
        if ($paise > 0) {
            $out .= ' and '.self::number($paise).' Paise';
        }

        return $out.' Only';
    }

    private static function number(int $n): string
    {
        $parts = [];
        foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand'], [100, 'Hundred']] as [$size, $name]) {
            if ($n >= $size) {
                $parts[] = self::number(intdiv($n, $size)).' '.$name;
                $n %= $size;
            }
        }
        if ($n > 0) {
            $parts[] = $n < 20 ? self::ONES[$n] : trim(self::TENS[intdiv($n, 10)].' '.self::ONES[$n % 10]);
        }

        return implode(' ', $parts);
    }
}
