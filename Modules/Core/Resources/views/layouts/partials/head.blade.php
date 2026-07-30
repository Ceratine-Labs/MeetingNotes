{{--
    Shared <head> for every layout (app shell, guest/auth pages, marketing,
    admin). One partial so an asset can never drift between shells.

    Every URL here is local. Nothing loads from a CDN — see
    docs/VENDOR_ASSETS.md for why, and use mn_asset() (not asset()) for
    anything new so the mtime cache-buster is applied.

    Available to including layouts:
      $theme  — 'light'|'dark', already resolved server-side by ThemeService.
                Stamped on <html> by the layout, not here.
--}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="color-scheme" content="{{ $theme === 'dark' ? 'dark light' : 'light dark' }}">

<title>@yield('title', config('app.name', 'MeetingNotes'))</title>
<meta name="description" content="@yield('meta_description', 'Turn a meeting transcript into complete, professional minutes: decisions, action items, attendance and next steps, in the same structure every time.')">

{{-- Public pages are indexable; anything behind auth is not. --}}
@hasSection('robots')
    <meta name="robots" content="@yield('robots')">
@else
    <meta name="robots" content="noindex, nofollow">
@endif

<link rel="icon" href="{{ mn_asset('favicon.ico') }}" sizes="any">

{{--
    PWA: installable on desktop and mobile. The manifest is a route, not a
    static file, so its name follows config('app.name'). The service worker
    (public/sw.js) is registered by app.js; theme-color is kept in sync with
    the live theme by the toggle so the installed titlebar follows along.
--}}
<link rel="manifest" href="{{ route('site.manifest') }}">
<meta name="theme-color" content="{{ $theme === 'dark' ? '#04060a' : '#ffffff' }}">
<link rel="apple-touch-icon" href="{{ mn_asset('icons/apple-touch-icon.png') }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ config('app.name', 'MeetingNotes') }}">

{{--
    Fonts first: Inter is what makes Tabler look like Tabler, and preloading
    the latin subset stops a visible font swap on the first paint. Only the
    one file we know every page needs is preloaded — preloading the italic
    and latin-ext files would cost bytes most pages never use.
--}}
<link rel="preload" href="{{ mn_asset('vendor/inter/files/inter-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ mn_asset('vendor/inter/inter.css') }}">

{{-- Tabler core (bundles Bootstrap 5 — we never load Bootstrap separately). --}}
<link rel="stylesheet" href="{{ mn_asset('vendor/tabler/css/tabler.min.css') }}">
<link rel="stylesheet" href="{{ mn_asset('vendor/tabler-icons/tabler-icons.min.css') }}">

{{--
    Widgets used across effectively every screen. Their JS counterparts are
    loaded in foot.blade.php.

    Note we vendor sweetalert2.min.js (not the "all" build), so its stylesheet
    is a separate file and MUST be linked here — the plain build injects no
    styles of its own and the dialog renders unstyled without this.
--}}
<link rel="stylesheet" href="{{ mn_asset('vendor/sweetalert2/sweetalert2.min.css') }}">
<link rel="stylesheet" href="{{ mn_asset('vendor/tom-select/tom-select.bootstrap5.min.css') }}">

{{--
    Optional stylesheets, opted into per page so no shell pays for CSS it does
    not use. Declare in the page with an explicit truthy value —
    `@section('charts', true)` — because @hasSection only sees sections the
    child view actually registered.

      @section('tabler_marketing', true) — landing/pricing hero + typography
      @section('tabler_payments', true)  — card-brand logos on billing screens
      @section('charts', true)           — ApexCharts (usage + admin analytics)
      @section('tour', true)             — IntroJS guided tours
--}}
@hasSection('tabler_marketing')
    <link rel="stylesheet" href="{{ mn_asset('vendor/tabler/css/tabler-marketing.min.css') }}">
@endif
@hasSection('tabler_payments')
    <link rel="stylesheet" href="{{ mn_asset('vendor/tabler/css/tabler-payments.min.css') }}">
@endif
@hasSection('charts')
    <link rel="stylesheet" href="{{ mn_asset('vendor/apexcharts/apexcharts.css') }}">
@endif
@hasSection('tour')
    <link rel="stylesheet" href="{{ mn_asset('vendor/introjs/introjs.min.css') }}">
@endif

{{-- Our theme layer — must come after Tabler so its variable overrides win. --}}
<link rel="stylesheet" href="{{ mn_asset('css/theme.css') }}">

@stack('head')

{{--
    First-visit theme detection.

    The server has already decided this render's theme. This script only
    matters for a visitor with NO stored preference at all: it asks the OS
    via prefers-color-scheme, and if the answer disagrees with what we just
    rendered it corrects the attribute and drops the cookie so every later
    request is decided server-side with no JS involved.

    It is inline and synchronous on purpose — an external file would paint
    the wrong theme first. Kept to a few lines for the same reason.
--}}
<script>
    (function () {
        if (document.cookie.indexOf('{{ \Modules\Core\Services\ThemeService::COOKIE }}=') !== -1) {
            return; // An explicit preference exists; the server already used it.
        }

        var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        var theme = prefersDark ? 'dark' : 'light';

        document.documentElement.setAttribute('data-bs-theme', theme);
        document.cookie = '{{ \Modules\Core\Services\ThemeService::COOKIE }}=' + theme +
            '; path=/; max-age=31536000; SameSite=Lax';
    })();
</script>
