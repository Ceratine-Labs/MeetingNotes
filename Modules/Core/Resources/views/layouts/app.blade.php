{{--
    Authenticated application shell — the layout every in-app screen extends.

    Structure (Tabler's vertical-navbar layout):
        aside.navbar-vertical   sidebar, DB-driven via MenuService
        header.navbar           top bar: org switcher, theme toggle, account
        div.page-body           @yield('content')

    Sections a page can define:
        title              <title> text
        page_pretitle      small muted line above the heading
        page_title         page heading (omit to render no header block)
        page_actions       right-aligned buttons in the header block
        content            the page itself
        charts / tour      opt-in vendor bundles, e.g. @section('charts', true)

    Variables injected into every layout by Core's view composer
    (see CoreServiceProvider::boot):
        $theme  'light'|'dark'
        $menu   grouped sidebar entries for the current user
--}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="{{ $theme }}">
<head>
    @include('core::layouts.partials.head')
</head>
<body class="layout-fluid" @auth data-theme-save-url="{{ route('core.theme.store') }}" @endauth>
    <div class="page">
        @include('core::layouts.partials.sidebar')

        <header class="navbar navbar-expand-md d-print-none">
            <div class="container-fluid">
                {{--
                    Search takes the free space on the left; everything identity-related
                    is pinned to the far right by `ms-auto`.

                    Without ms-auto the whole group collapses to the left of an otherwise
                    empty navbar, because there is nothing else in the flex row to push
                    against — which is exactly how it looked before.
                --}}
                <div class="me-md-4 flex-grow-1" style="max-width: 34rem;">
                    {{-- Owned by the Search module; absent module renders nothing. --}}
                    @includeIf('search::partials.navbar-search')
                </div>

                <div class="navbar-nav flex-row align-items-center ms-auto">
                    <div class="nav-item d-none d-md-flex me-2">
                        {{-- Org switcher belongs to Tenancy; absent module renders nothing. --}}
                        @includeIf('tenancy::partials.org-switcher')
                    </div>

                    @include('core::layouts.partials.theme-toggle')

                    <div class="d-none d-md-flex ms-2">
                        @include('core::layouts.partials.user-menu')
                    </div>
                </div>
            </div>
        </header>

        <div class="page-wrapper">
            @hasSection('page_title')
                <div class="page-header d-print-none">
                    <div class="container-fluid">
                        <div class="row g-2 align-items-center">
                            <div class="col">
                                @hasSection('page_pretitle')
                                    <div class="page-pretitle">@yield('page_pretitle')</div>
                                @endif
                                <h2 class="page-title">@yield('page_title')</h2>
                            </div>
                            @hasSection('page_actions')
                                <div class="col-auto ms-auto d-print-none">
                                    <div class="btn-list">@yield('page_actions')</div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            <div class="page-body">
                <div class="container-fluid">
                    @include('core::layouts.partials.flash')
                    @yield('content')
                </div>
            </div>

            <footer class="footer footer-transparent d-print-none">
                <div class="container-fluid">
                    <div class="row text-center align-items-center flex-row-reverse">
                        <div class="col-lg-auto ms-lg-auto">
                            <ul class="list-inline list-inline-dots mb-0">
                                <li class="list-inline-item">
                                    <a href="{{ route('site.terms') }}" class="link-secondary">Terms</a>
                                </li>
                                <li class="list-inline-item">
                                    <a href="{{ route('site.privacy') }}" class="link-secondary">Privacy</a>
                                </li>
                            </ul>
                        </div>
                        <div class="col-12 col-lg-auto mt-3 mt-lg-0">
                            <span class="text-secondary">
                                &copy; {{ now()->year }} {{ config('app.name') }}
                            </span>
                        </div>
                    </div>
                </div>
            </footer>
        </div>
    </div>

    @include('core::layouts.partials.foot')
</body>
</html>
