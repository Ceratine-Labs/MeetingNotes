<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'MeetingNotes')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { min-height: 100vh; }
        .sidebar { width: 240px; min-height: 100vh; }
        .sidebar .nav-link { color: var(--bs-body-color); border-radius: .375rem; }
        .sidebar .nav-link.active { background: var(--bs-primary); color: #fff; }
        .sidebar .nav-link:hover:not(.active) { background: var(--bs-secondary-bg); }
        .sidebar-section { font-size: .7rem; text-transform: uppercase; letter-spacing: .08em; opacity: .6; }
        main { min-width: 0; }
    </style>
    @stack('head')
</head>
<body class="d-flex">
    <nav class="sidebar d-flex flex-column flex-shrink-0 p-3 border-end">
        <a href="{{ route('core.dashboard') }}" class="d-flex align-items-center mb-3 text-decoration-none">
            <i class="bi bi-journal-text fs-4 me-2 text-primary"></i>
            <span class="fs-5 fw-semibold">MeetingNotes</span>
        </a>
        <hr class="mt-0">
        @foreach (app(\Modules\Core\Services\MenuService::class)->visibleFor(auth()->user()) as $section => $items)
            @if ($section !== '')
                <div class="sidebar-section mt-2 mb-1 px-2">{{ $section }}</div>
            @endif
            <ul class="nav nav-pills flex-column mb-2">
                @foreach ($items as $item)
                    <li class="nav-item">
                        <a href="{{ route($item->route_name) }}"
                           class="nav-link {{ request()->routeIs($item->route_name . '*') ? 'active' : '' }}">
                            @if ($item->icon)<i class="bi bi-{{ $item->icon }} me-2"></i>@endif
                            {{ $item->label }}
                        </a>
                    </li>
                @endforeach
            </ul>
        @endforeach
        <div class="mt-auto">
            <hr>
            @auth
                <div class="d-flex align-items-center justify-content-between">
                    <span class="small text-truncate">{{ auth()->user()->name }}</span>
                    <form method="POST" action="{{ route('auth.logout') }}" class="mb-0">
                        @csrf
                        <button class="btn btn-sm btn-outline-secondary" title="Log out">
                            <i class="bi bi-box-arrow-right"></i>
                        </button>
                    </form>
                </div>
            @endauth
        </div>
    </nav>

    <main class="flex-grow-1 p-4">
        @if (session('status'))
            <div class="alert alert-success alert-dismissible fade show">
                {{ session('status') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif
        @yield('content')
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // House rule: SweetAlert2 for every confirm — no native dialogs.
        // Any form with data-confirm="message" gets an async confirmation.
        document.addEventListener('submit', function (e) {
            const form = e.target;
            if (!form.dataset.confirm || form.dataset.confirmed) return;
            e.preventDefault();
            Swal.fire({
                text: form.dataset.confirm,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, continue',
                theme: 'dark',
            }).then((r) => {
                if (r.isConfirmed) {
                    form.dataset.confirmed = '1';
                    form.requestSubmit();
                }
            });
        });
    </script>
    @stack('scripts')
</body>
</html>
