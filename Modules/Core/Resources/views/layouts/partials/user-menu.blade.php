{{--
    Account dropdown — avatar initials, name, current organisation role, and
    the links a signed-in user needs from anywhere in the app.

    Organisation-specific rows come from the Tenancy module via includeIf, so
    Core stays independent of it (HMVC: Core must boot with no other module
    present).
--}}
@auth
    @php
        // Initials from the display name — avoids shipping an avatar upload
        // and a storage path just to decorate a dropdown.
        $initials = collect(explode(' ', trim(auth()->user()->name)))
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    @endphp

    <div class="nav-item dropdown">
        <a href="#" class="nav-link d-flex lh-1 p-0 px-2" data-bs-toggle="dropdown"
           aria-label="Open account menu" aria-expanded="false">
            <span class="avatar avatar-sm bg-primary-lt">{{ $initials }}</span>
            <div class="d-none d-xl-block ps-2">
                <div class="text-truncate" style="max-width: 12rem;">{{ auth()->user()->name }}</div>
                <div class="mt-1 small text-secondary text-truncate" style="max-width: 12rem;">
                    {{ auth()->user()->email }}
                </div>
            </div>
        </a>

        <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
            <a href="{{ route('auth.profile.edit') }}" class="dropdown-item">
                <i class="ti ti-user me-2"></i>Profile
            </a>

            @includeIf('tenancy::partials.user-menu-items')
            @includeIf('billing::partials.user-menu-items')

            <div class="dropdown-divider"></div>

            {{--
                Logout is a POST (CSRF-protected) — a GET logout can be
                triggered by any image tag or prefetcher on the page.
            --}}
            <form method="POST" action="{{ route('auth.logout') }}">
                @csrf
                <button type="submit" class="dropdown-item text-danger">
                    <i class="ti ti-logout me-2"></i>Log out
                </button>
            </form>
        </div>
    </div>
@endauth
