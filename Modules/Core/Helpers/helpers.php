<?php

use Modules\Core\Services\AssetService;
use Modules\Core\Services\SettingsService;

if (! function_exists('setting')) {
    /**
     * Read an application setting.
     *
     * Shorthand for SettingsService::get() — decrypts secret values
     * transparently. Use setting_service()->set() to write.
     *
     * @param  string  $key  Dotted setting key, e.g. "llm.default_provider".
     * @param  mixed  $default  Returned when the key has never been set.
     */
    function setting(string $key, mixed $default = null): mixed
    {
        return app(SettingsService::class)->get($key, $default);
    }
}

if (! function_exists('setting_service')) {
    /**
     * Resolve the settings service, for writes and bulk operations.
     */
    function setting_service(): SettingsService
    {
        return app(SettingsService::class);
    }
}

if (! function_exists('mn_asset')) {
    /**
     * URL for one of our own or vendored static files, cache-busted by mtime.
     *
     * Use this in Blade for anything under public/ — there is no bundler in
     * this project, so plain asset() would let browsers serve a stale
     * stylesheet forever after a deploy. See AssetService for the details.
     *
     * @param  string  $path  Path relative to public/, e.g. "css/theme.css".
     */
    function mn_asset(string $path): string
    {
        return app(AssetService::class)->url($path);
    }
}
