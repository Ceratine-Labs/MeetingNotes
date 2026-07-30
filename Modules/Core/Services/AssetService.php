<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Resolves URLs for the application's own and vendored static assets.
 *
 * Why this exists instead of Laravel's asset() helper:
 *
 * There is no bundler in this project. Every stylesheet and script is a plain
 * file committed under public/ (public/css, public/js) or public/vendor for
 * third-party libraries — see docs/VENDOR_ASSETS.md for the reasoning. That
 * removes a Node dependency from the deploy target, but it also removes the
 * content-hashed filenames a bundler would have given us, so a browser holding
 * a cached theme.css would never see a CSS change.
 *
 * This service restores cache-busting by appending the file's modification
 * time as a query string: public/css/theme.css -> /css/theme.css?v=1753876543.
 * A deploy rewrites mtimes, the query string changes, browsers refetch.
 *
 * The mtime lookups are memoised for a day in production because the files
 * cannot change without a deploy, and a deploy clears the cache. In local and
 * testing environments the cache is bypassed entirely so an edit shows up on
 * the next refresh without an artisan command.
 */
class AssetService
{
    /**
     * How long a resolved asset URL stays memoised, in seconds.
     */
    private const CACHE_TTL = 86400;

    /**
     * Build a cache-busted URL for a file inside public/.
     *
     * Falls back to the un-versioned URL when the file is missing rather than
     * throwing: a missing optional asset (say, a module's own stylesheet)
     * should produce one 404 in the network tab, not a 500 on every page.
     *
     * @param  string  $path  Path relative to public/, e.g. "css/theme.css".
     */
    public function url(string $path): string
    {
        $path = ltrim($path, '/');

        if (app()->environment(['local', 'testing'])) {
            return $this->build($path);
        }

        return Cache::remember(
            'core.asset.'.$path,
            self::CACHE_TTL,
            fn (): string => $this->build($path)
        );
    }

    /**
     * Resolve the URL and stamp it with the file's modification time.
     */
    private function build(string $path): string
    {
        $absolute = public_path($path);

        if (! is_file($absolute)) {
            return asset($path);
        }

        return asset($path).'?v='.filemtime($absolute);
    }
}
