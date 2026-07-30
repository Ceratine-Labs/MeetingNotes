<?php

namespace Modules\Core\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Decides which theme (light or dark) a request renders in, and persists a
 * user's choice.
 *
 * The important design point: **the theme is resolved server-side, during
 * render.** Blade stamps the result onto `<html data-bs-theme="...">`, so the
 * very first paint is already correct. Tabler ships its own switcher that
 * decides in localStorage after the document loads; that produces a visible
 * flash of the wrong theme on every navigation, which is why we do not use it
 * (see docs/THEMING.md and docs/VENDOR_ASSETS.md).
 *
 * Resolution order, first hit wins:
 *
 *   1. The authenticated user's saved `theme` column — follows them across
 *      browsers and devices.
 *   2. The `mn_theme` cookie — covers guests on the public site, and logged-in
 *      users whose preference has not synced yet.
 *   3. Light. The house baseline for this product is light-default, with a
 *      deliberately deep dark as the opt-in alternative.
 *
 * Note what is NOT in that list: `prefers-color-scheme`. PHP cannot read a
 * media query, and honouring it would mean deciding in JS after paint — the
 * exact flash we are avoiding. Instead the layout ships a tiny inline script
 * that consults `prefers-color-scheme` **only when no preference exists at
 * all**, sets the cookie, and lets every subsequent request be server-decided.
 */
class ThemeService
{
    public const LIGHT = 'light';

    public const DARK = 'dark';

    /**
     * Name of the first-party cookie holding a guest's preference. Read by
     * PHP during render and written by public/js/app.js on toggle, so the two
     * must agree — change it in both places or theming silently stops
     * persisting for guests.
     */
    public const COOKIE = 'mn_theme';

    /**
     * How long a guest's theme choice survives, in minutes (one year).
     */
    private const COOKIE_LIFETIME = 525600;

    /**
     * The two themes the application ships. Anything else is rejected rather
     * than passed through — the value lands in an HTML attribute, and an
     * unvalidated one would be an injection point.
     *
     * @var list<string>
     */
    public const AVAILABLE = [self::LIGHT, self::DARK];

    /**
     * Resolve the theme for the current request.
     *
     * @return 'light'|'dark' Safe to interpolate straight into the
     *                        data-bs-theme attribute.
     */
    public function current(Request $request): string
    {
        $user = $request->user();

        // `theme` is a nullable column on users; a user who has never toggled
        // has null here and falls through to the cookie.
        if ($user !== null && $this->isValid($user->theme ?? null)) {
            return $user->theme;
        }

        $cookie = $request->cookie(self::COOKIE);

        if (is_string($cookie) && $this->isValid($cookie)) {
            return $cookie;
        }

        return self::LIGHT;
    }

    /**
     * Persist a theme choice.
     *
     * Always queues the cookie (so the choice survives logout and covers the
     * public site) and additionally writes the user's column when there is one
     * to write to.
     *
     * @param  string  $theme  Untrusted — validated before use.
     * @return bool False when the value was not a known theme, so the caller
     *              can return a 422 rather than silently doing nothing.
     */
    public function persist(Request $request, string $theme): bool
    {
        if (! $this->isValid($theme)) {
            return false;
        }

        Cookie::queue(self::COOKIE, $theme, self::COOKIE_LIFETIME);

        $user = $request->user();

        if ($user !== null) {
            // Deliberately a targeted update rather than save(): the theme is
            // a UI preference and must not bump the model's updated_at, fire
            // observers, or collide with a concurrent profile edit.
            $user->newQuery()->whereKey($user->getKey())->update(['theme' => $theme]);
        }

        return true;
    }

    /**
     * The theme the toggle would switch to — used to label the control.
     *
     * @return 'light'|'dark'
     */
    public function opposite(string $theme): string
    {
        return $theme === self::DARK ? self::LIGHT : self::DARK;
    }

    /**
     * Is this one of the themes we ship?
     */
    private function isValid(?string $theme): bool
    {
        return $theme !== null && in_array($theme, self::AVAILABLE, true);
    }
}
