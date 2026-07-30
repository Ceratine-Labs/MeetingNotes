{{--
    Public / marketing layout — landing page, pricing, features, legal pages.

    Differences from the app shell that matter:
      * Indexable. Pages extending this should set @section('robots', 'index, follow');
        head.blade.php defaults to noindex for everything else.
      * Horizontal navbar with sign-in / get-started CTAs instead of a sidebar.
      * Loads Tabler's marketing stylesheet (opted in below).
      * Renders for guests AND signed-in users — the nav swaps the CTA for a
        link back into the app, so a logged-in visitor who lands on the
        pricing page is not asked to register again.

    Sections:
        title, meta_description, robots, content
        nav_cta   overrides the right-hand navbar buttons

    Variables from Core's view composer: $theme.
--}}
@section('tabler_marketing', true)
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="{{ $theme }}">
<head>
    @include('core::layouts.partials.head')
</head>
<body class="d-flex flex-column">
    <div class="page">
        <header class="navbar navbar-expand-md sticky-top border-bottom">
            <div class="container-xl">
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                        data-bs-target="#site-nav" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <a href="{{ route('site.home') }}"
                   class="navbar-brand navbar-brand-autodark text-decoration-none me-4">
                    <i class="ti ti-file-text text-primary fs-2 me-2"></i>
                    <span class="fw-semibold">{{ config('app.name', 'MeetingNotes') }}</span>
                </a>

                <div class="navbar-nav flex-row order-md-last align-items-center">
                    @include('core::layouts.partials.theme-toggle')

                    <div class="ms-2">
                        @hasSection('nav_cta')
                            @yield('nav_cta')
                        @else
                            @auth
                                <a href="{{ route('core.dashboard') }}" class="btn btn-primary">
                                    Open the app<i class="ti ti-arrow-right ms-1"></i>
                                </a>
                            @else
                                <a href="{{ route('auth.login') }}" class="btn btn-link text-secondary">Log in</a>
                                <a href="{{ route('auth.register') }}" class="btn btn-primary">Start free</a>
                            @endauth
                        @endif
                    </div>
                </div>

                <div class="collapse navbar-collapse" id="site-nav">
                    <ul class="navbar-nav">
                        <li class="nav-item {{ request()->routeIs('site.features') ? 'active' : '' }}">
                            <a class="nav-link" href="{{ route('site.features') }}">How it works</a>
                        </li>
                        <li class="nav-item {{ request()->routeIs('site.pricing') ? 'active' : '' }}">
                            <a class="nav-link" href="{{ route('site.pricing') }}">Pricing</a>
                        </li>
                    </ul>
                </div>
            </div>
        </header>

        <div class="page-wrapper">
            @include('core::layouts.partials.flash')

            @yield('content')

            <footer class="footer footer-transparent mt-auto border-top">
                <div class="container-xl py-4">
                    <div class="row align-items-center g-3">
                        <div class="col-md">
                            <div class="d-flex align-items-center">
                                <i class="ti ti-file-text text-primary fs-2 me-2"></i>
                                <div>
                                    <div class="fw-semibold">{{ config('app.name', 'MeetingNotes') }}</div>
                                    <div class="small text-secondary">
                                        Professional meeting minutes, generated from your transcript.
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-auto">
                            <ul class="list-inline list-inline-dots mb-0">
                                <li class="list-inline-item">
                                    <a href="{{ route('site.features') }}" class="link-secondary">How it works</a>
                                </li>
                                <li class="list-inline-item">
                                    <a href="{{ route('site.pricing') }}" class="link-secondary">Pricing</a>
                                </li>
                                <li class="list-inline-item">
                                    <a href="{{ route('site.terms') }}" class="link-secondary">Terms</a>
                                </li>
                                <li class="list-inline-item">
                                    <a href="{{ route('site.privacy') }}" class="link-secondary">Privacy</a>
                                </li>
                            </ul>
                            <div class="small text-secondary mt-2 text-md-end">
                                &copy; {{ now()->year }} Ceratine Labs
                            </div>
                        </div>
                    </div>
                </div>
            </footer>
        </div>
    </div>

    @include('core::layouts.partials.foot')
</body>
</html>
