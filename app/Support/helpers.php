<?php

use App\Modules\Settings\Services\SettingsService;
use App\Support\CurrentBranch;

if (! function_exists('settings')) {
    /** settings() returns the service; settings('key', $default) returns a value. */
    function settings(?string $key = null, mixed $default = null): mixed
    {
        $service = app(SettingsService::class);

        return $key === null ? $service : $service->get($key, $default);
    }
}

if (! function_exists('current_branch')) {
    function current_branch(): CurrentBranch
    {
        return app(CurrentBranch::class);
    }
}
