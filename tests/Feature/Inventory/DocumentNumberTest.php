<?php

use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Services\DocumentNumberService;
use App\Support\FinancialYear;
use Illuminate\Support\Carbon;

it('numbers documents per branch, type and financial year', function () {
    $a = Branch::factory()->create(['code' => 'BR1']);
    $b = Branch::factory()->create(['code' => 'BR2']);
    $svc = app(DocumentNumberService::class);
    $date = Carbon::parse('2026-11-15');

    expect($svc->next('ADJ', $a, $date))->toBe('ADJ/BR1/26-27/0001')
        ->and($svc->next('ADJ', $a, $date))->toBe('ADJ/BR1/26-27/0002')
        ->and($svc->next('ADJ', $b, $date))->toBe('ADJ/BR2/26-27/0001')
        ->and($svc->next('TRF', $a, $date))->toBe('TRF/BR1/26-27/0001')
        ->and($svc->next('ADJ', $a, Carbon::parse('2027-04-01')))->toBe('ADJ/BR1/27-28/0001');
});

it('derives the Indian financial year from 1 April', function () {
    expect(FinancialYear::label(Carbon::parse('2026-03-31')))->toBe('25-26')
        ->and(FinancialYear::label(Carbon::parse('2026-04-01')))->toBe('26-27');

    settings()->set('business.financial_year_start', '01-01');
    expect(FinancialYear::label(Carbon::parse('2026-06-01')))->toBe('26');
});
