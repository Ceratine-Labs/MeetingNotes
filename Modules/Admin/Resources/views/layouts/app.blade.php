{{--
    Back-office shell.

    Reuses Core's head and foot partials — same vendored, CDN-free assets and the
    same server-resolved theme — but has its own sidebar, because the back-office
    navigation is static and staff-only rather than the customer's database-driven
    menu.

    The dark top bar is a deliberate visual signal: it must be obvious at a glance
    that this is the back office and not the customer application. Staff who can see
    every customer's billing should never be in any doubt about which side of the
    product they are looking at.

    Sections: title, page_pretitle, page_title, page_actions, content.
    $theme is injected by Core's view composer (admin::layouts.app is in its list).
--}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="{{ $theme }}">
<head>
    @include('core::layouts.partials.head')
</head>
<body class="layout-fluid">
    <div class="page">
        <aside class="navbar navbar-vertical navbar-expand-lg" data-bs-theme="dark">
            <div class="container-fluid">
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                        data-bs-target="#admin-menu" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <div class="navbar-brand">
                    <a href="{{ route('admin.dashboard') }}" class="text-decoration-none d-flex align-items-center">
                        <i class="ti ti-shield-lock text-primary fs-2 me-2"></i>
                        <div>
                            <div class="fw-semibold">{{ config('app.name') }}</div>
                            <div class="small text-secondary" style="line-height: 1;">Back office</div>
                        </div>
                    </a>
                </div>

                <div class="collapse navbar-collapse" id="admin-menu">
                    <ul class="navbar-nav pt-lg-3">
                        @php
                            /*
                             * Static nav, unlike the customer sidebar's DB-driven menu.
                             * The back office has one audience and a fixed set of
                             * screens, so a menus table row per link would be
                             * indirection with no payoff.
                             *
                             * Each entry: [route name, label, Tabler icon].
                             */
                            $sections = [
                                'Overview' => [
                                    ['admin.dashboard', 'Dashboard', 'dashboard'],
                                ],
                                'Customers' => [
                                    ['admin.organisations.index', 'Workspaces', 'building'],
                                    ['admin.users.index', 'Users', 'users'],
                                ],
                                'Money' => [
                                    ['admin.plans.index', 'Plans', 'tags'],
                                    ['admin.subscriptions.index', 'Subscriptions', 'refresh'],
                                    ['admin.payments.index', 'Payments', 'credit-card'],
                                    ['admin.webhooks.index', 'Payment webhooks', 'webhook'],
                                ],
                                'System' => [
                                    // Owned by the Llm and Backup modules. Route::has()
                                    // guards each one so the back office still renders
                                    // if a module is removed.
                                    ['llm.admin.settings', 'LLM providers', 'cpu'],
                                    ['llm.admin.prompts', 'Prompt templates', 'message-2'],
                                    ['llm.admin.runs', 'Generation log', 'activity'],
                                    ['backup.admin.index', 'Backups', 'database'],
                                    ['admin.audit.index', 'Audit log', 'history'],
                                ],
                            ];
                        @endphp

                        @foreach ($sections as $section => $items)
                            <li class="nav-section-title">{{ $section }}</li>

                            @foreach ($items as [$route, $label, $icon])
                                @continue(! Route::has($route))

                                <li class="nav-item">
                                    <a class="nav-link {{ request()->routeIs($route) ? 'active' : '' }}"
                                       href="{{ route($route) }}">
                                        <span class="nav-link-icon">
                                            <i class="ti ti-{{ $icon }}"></i>
                                        </span>
                                        <span class="nav-link-title">{{ $label }}</span>
                                    </a>
                                </li>
                            @endforeach
                        @endforeach
                    </ul>

                    <div class="mt-auto pt-3">
                        <a href="{{ route('site.home') }}" class="nav-link" target="_blank" rel="noopener">
                            <span class="nav-link-icon"><i class="ti ti-external-link"></i></span>
                            <span class="nav-link-title">Public site</span>
                        </a>
                    </div>
                </div>
            </div>
        </aside>

        <header class="navbar navbar-expand-md d-print-none">
            <div class="container-fluid">
                {{-- ms-auto pins the account menu to the far right; the navbar has
                     nothing else in it to push against. --}}
                <div class="navbar-nav flex-row align-items-center ms-auto">
                    @include('core::layouts.partials.theme-toggle')

                    <div class="nav-item dropdown ms-2">
                        <a href="#" class="nav-link d-flex lh-1 p-0 px-2" data-bs-toggle="dropdown"
                           aria-label="Open back-office account menu">
                            <span class="avatar avatar-sm bg-red-lt">
                                <i class="ti ti-shield-lock"></i>
                            </span>
                            <div class="d-none d-xl-block ps-2">
                                <div>{{ auth('admin')->user()->name }}</div>
                                <div class="mt-1 small text-secondary">Back office</div>
                            </div>
                        </a>

                        <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                            <div class="dropdown-header">{{ auth('admin')->user()->email }}</div>
                            <div class="dropdown-divider"></div>
                            <form method="POST" action="{{ route('admin.logout') }}">
                                @csrf
                                <button type="submit" class="dropdown-item text-danger">
                                    <i class="ti ti-logout me-2"></i>Sign out
                                </button>
                            </form>
                        </div>
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
                                <div class="col-auto ms-auto">
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
        </div>
    </div>

    @include('core::layouts.partials.foot')
</body>
</html>
