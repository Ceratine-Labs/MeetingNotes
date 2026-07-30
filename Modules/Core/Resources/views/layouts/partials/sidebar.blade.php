{{--
    Application sidebar.

    Entirely database-driven (house hard rule #6 — no hardcoded nav in Blade).
    Each module seeds its own entries through MenuSeeder -> MenuService::seed(),
    and MenuService::visibleFor() returns them grouped by section, already
    filtered to routes that exist and roles the user holds.

    Adding a nav item means writing a MenuSeeder in the owning module. Editing
    this file to add a link is always the wrong fix.

    Icons are Tabler icon names without the `ti-` prefix, e.g. 'dashboard'
    seeds as <i class="ti ti-dashboard">.
--}}
<aside class="navbar navbar-vertical navbar-expand-lg">
    <div class="container-fluid">
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                data-bs-target="#sidebar-menu" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="navbar-brand navbar-brand-autodark">
            <a href="{{ route('core.dashboard') }}" class="text-decoration-none d-flex align-items-center">
                <i class="ti ti-file-text text-primary fs-2 me-2"></i>
                <span class="fw-semibold">{{ config('app.name', 'MeetingNotes') }}</span>
            </a>
        </div>

        {{-- Mobile: account menu sits in the collapsed header bar. --}}
        <div class="navbar-nav flex-row d-lg-none">
            @include('core::layouts.partials.user-menu')
        </div>

        <div class="collapse navbar-collapse" id="sidebar-menu">
            <ul class="navbar-nav pt-lg-3">
                @foreach ($menu as $section => $items)
                    @if ($section !== '')
                        <li class="nav-section-title">{{ $section }}</li>
                    @endif

                    @foreach ($items as $item)
                        @php
                            // Match the item and anything below it, so
                            // /app/minutes/{id}/edit keeps "Minutes" lit.
                            $isActive = request()->routeIs($item->route_name)
                                || request()->routeIs($item->route_name . '.*');
                        @endphp
                        <li class="nav-item">
                            <a class="nav-link {{ $isActive ? 'active' : '' }}"
                               href="{{ route($item->route_name) }}"
                               @if ($isActive) aria-current="page" @endif>
                                @if ($item->icon)
                                    <span class="nav-link-icon d-md-none d-lg-inline-block">
                                        <i class="ti ti-{{ $item->icon }}"></i>
                                    </span>
                                @endif
                                <span class="nav-link-title">{{ $item->label }}</span>
                            </a>
                        </li>
                    @endforeach
                @endforeach
            </ul>

            {{--
                Usage meter — owned by the Billing module. includeIf so Core
                carries no hard dependency on Billing; if the module is absent
                or the org has no metered plan, the slot simply renders nothing.
            --}}
            <div class="mt-auto pt-3">
                @includeIf('billing::partials.usage-meter')
            </div>
        </div>
    </div>
</aside>
