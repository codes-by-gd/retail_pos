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

        return DB::transaction(function () use ($type, $branch, $fy, $pad) {
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

            return sprintf('%s/%s/%s/%s', $type, $branch->code, $fy, str_pad((string) $sequence->last_number, $pad, '0', STR_PAD_LEFT));
        });
    }
}
