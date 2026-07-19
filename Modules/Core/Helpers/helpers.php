<?php

use Modules\Core\Services\SettingsService;

if (! function_exists('setting')) {
    /**
     * Shorthand for SettingsService::get() — decrypts secret values
     * transparently. Use setting_service()->set() to write.
     */
    function setting(string $key, mixed $default = null): mixed
    {
        return app(SettingsService::class)->get($key, $default);
    }
}

if (! function_exists('setting_service')) {
    function setting_service(): SettingsService
    {
        return app(SettingsService::class);
    }
}
