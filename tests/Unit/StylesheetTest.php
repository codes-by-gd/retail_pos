<?php

/*
 * Guards the hand-written stylesheets. A "star-slash" inside a CSS comment ends it early; the rest of the
 * comment then becomes broken CSS and Tailwind silently drops the theme (dark mode and colours disappear).
 */
it('has well-formed comments in the app stylesheets', function (string $file) {
    // quoted strings (e.g. @source '…/views/*.php') aren't comments
    $css = preg_replace('#\'[^\'\n]*\'|"[^"\n]*"#', "''", file_get_contents(__DIR__."/../../resources/css/{$file}"));

    // every comment must open and close exactly once, with nothing left over that looks like a comment end
    $withoutComments = preg_replace('#/\*.*?\*/#s', '', $css);
    expect(substr_count($css, '/*'))->toBe(substr_count($css, '*/'))
        ->and($withoutComments)->not->toContain('*/')
        ->and($withoutComments)->not->toContain('/*');
})->with(['app.css', 'theme.css']);

it('keeps the dark-mode theme blocks in theme.css', function () {
    $css = file_get_contents(__DIR__.'/../../resources/css/theme.css');

    expect($css)->toContain('@theme inline {')
        ->toContain(':root {')
        ->toContain('.dark {')
        ->toContain('--text-color-gray-900: var(--t-gray-900);')
        ->toContain('--background-color-white: var(--surface);');
});
