{{--
    Light/dark toggle.

    Both buttons are always in the DOM and CSS decides which is visible
    (Tabler's .hide-theme-dark / .hide-theme-light), so switching needs no
    re-render and no icon swap in JS. The click is picked up by the delegated
    [data-mn-theme-toggle] handler in public/js/app.js, which flips the
    attribute, sets the cookie and — when logged in — saves it to the user row.

    Expects: $theme ('light'|'dark') from the enclosing layout.
--}}
<a href="#" class="nav-link px-2 hide-theme-dark" title="Switch to dark theme"
   data-mn-theme-toggle="dark" data-bs-toggle="tooltip" data-bs-placement="bottom"
   role="button" aria-pressed="{{ $theme === 'dark' ? 'true' : 'false' }}">
    <span class="visually-hidden">Switch to dark theme</span>
    <i class="ti ti-moon fs-2"></i>
</a>

<a href="#" class="nav-link px-2 hide-theme-light" title="Switch to light theme"
   data-mn-theme-toggle="light" data-bs-toggle="tooltip" data-bs-placement="bottom"
   role="button" aria-pressed="{{ $theme === 'dark' ? 'true' : 'false' }}">
    <span class="visually-hidden">Switch to light theme</span>
    <i class="ti ti-sun fs-2"></i>
</a>
