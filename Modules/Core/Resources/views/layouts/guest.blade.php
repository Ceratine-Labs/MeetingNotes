{{--
    Guest layout — login, register, password reset, email verification, invite
    acceptance. A single centred card on an empty page: no sidebar, no nav, no
    escape hatches, because everything on these screens is one focused task.

    Sections:
        title    <title> text
        heading  card heading (e.g. "Log in to your account")
        content  the form
        footer   the "no account? register" style link below the card

    Variables from Core's view composer: $theme.
--}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="{{ $theme }}">
<head>
    @include('core::layouts.partials.head')
</head>
<body class="d-flex flex-column bg-surface-secondary">
    <div class="page page-center">
        <div class="container container-tight py-4">
            {{-- Wordmark doubles as the way back out to the public site. --}}
            <div class="text-center mb-4">
                <a href="{{ route('site.home') }}" class="navbar-brand navbar-brand-autodark text-decoration-none">
                    <i class="ti ti-file-text text-primary fs-1 me-2"></i>
                    <span class="fs-2 fw-semibold">{{ config('app.name', 'MeetingNotes') }}</span>
                </a>
            </div>

            <div class="card card-md">
                <div class="card-body">
                    @hasSection('heading')
                        <h2 class="h3 text-center mb-4">@yield('heading')</h2>
                    @endif

                    @include('core::layouts.partials.flash')

                    @yield('content')
                </div>
            </div>

            @hasSection('footer')
                <div class="text-center text-secondary mt-3">@yield('footer')</div>
            @endif

            {{-- Theme toggle is offered here too: the login page is often the
                 first thing a user sees, and it should not be the one screen
                 where they cannot get out of a theme they dislike. --}}
            <div class="text-center mt-4">
                <span class="d-inline-flex">@include('core::layouts.partials.theme-toggle')</span>
            </div>
        </div>
    </div>

    @include('core::layouts.partials.foot')
</body>
</html>
