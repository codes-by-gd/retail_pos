<?php

use App\Support\Gst\IndianStates;
use App\Support\Permissions;

it('lists every permission once', function () {
    $all = Permissions::all();
    expect($all)->toEqual(array_values(array_unique($all)));
});

it('expands wildcard and exclusion patterns', function () {
    $resolved = Permissions::resolve(['*', '!roles.manage']);

    expect($resolved)->not->toContain('roles.manage')
        ->and(count($resolved))->toBe(count(Permissions::all()) - 1);
});

it('only references known permissions in default roles', function () {
    foreach (Permissions::DEFAULT_ROLES as $patterns) {
        expect(array_diff(Permissions::resolve($patterns), Permissions::all()))->toBeEmpty();
    }
});

it('returns GST state codes as two-character strings', function () {
    $codes = IndianStates::codes();

    expect($codes)->each->toBeString()->toHaveLength(2)
        ->and($codes)->toContain('24', '07', '97')
        ->and(IndianStates::name('24'))->toBe('Gujarat');
});
