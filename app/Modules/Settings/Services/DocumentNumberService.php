<?php

namespace App\Modules\Settings\Services;

use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Models\DocumentSequence;
use App\Support\FinancialYear;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Gap-free document numbers per branch, document type and financial year,
 * e.g. ADJ/BR1/25-26/0001. Must be called inside the transaction that saves the document,
 * so a rollback also releases the number.
 */
class DocumentNumberService
{
    public function next(string $type, Branch $branch, ?CarbonInterface $date = null, int $pad = 4): string
    {
        $fy = FinancialYear::label($date);
        $n = $this->increment($type, $branch, $fy);

        return sprintf('%s/%s/%s/%s', $type, $branch->code, $fy, str_pad((string) $n, $pad, '0', STR_PAD_LEFT));
    }

    /**
     * GST documents (tax invoice, credit note) — at most 16 characters, unique per financial year:
     * {prefix}{branch code}/{FY as 2627}/{sequence}, e.g. "BR1/2627/00001", "CNBR1/2627/00001".
     * The sequence is padded to fill the 16 characters (at least 3 digits).
     *
     * @throws \DomainException when prefix + branch code are too long to fit
     */
    public function gstNumber(string $sequenceType, string $prefix, Branch $branch, ?CarbonInterface $date = null): string
    {
        $fy = str_replace('-', '', FinancialYear::label($date));
        $head = $prefix.$branch->code.'/'.$fy.'/';
        $n = (string) $this->increment($sequenceType, $branch, FinancialYear::label($date));
        $pad = max(3, 16 - strlen($head));
        $number = $head.str_pad($n, $pad, '0', STR_PAD_LEFT);

        if (strlen($number) > 16) {
            throw new \DomainException("Document number {$number} is longer than the 16 characters GST allows. Shorten the invoice prefix or branch code.");
        }

        return $number;
    }

    private function increment(string $type, Branch $branch, string $fy): int
    {
        return DB::transaction(function () use ($type, $branch, $fy) {
            $key = ['branch_id' => $branch->id, 'type' => $type, 'financial_year' => $fy];

            $sequence = DocumentSequence::where($key)->lockForUpdate()->first();
            if (! $sequence) {
                try {
                    DocumentSequence::create([...$key, 'last_number' => 0]);
                } catch (UniqueConstraintViolationException) {
                    // Another request created it first — fine, lock it below.
                }
                $sequence = DocumentSequence::where($key)->lockForUpdate()->firstOrFail();
            }

            $sequence->increment('last_number');

            return $sequence->last_number;
        });
    }
}
