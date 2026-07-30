{{--
    Workspace switcher for the app navbar.

    Included by core::layouts.app via @includeIf, so Core carries no hard
    dependency on this module. It resolves its own data rather than relying on a
    view composer, because it is included from a Core layout that must keep
    working with Tenancy absent.

    Renders nothing at all when the user has only one workspace and cannot
    create another — a dropdown with a single item that does nothing is worse
    than no control.
--}}
@auth
    @php
        $resolver = app(\Modules\Tenancy\Services\OrganisationResolver::class);
        $current = app(\Modules\Tenancy\Services\OrganisationContext::class)->get();
        $available = $resolver->organisationsFor(auth()->user());

        $ownedCount = \Modules\Tenancy\Models\Membership::query()
            ->where('user_id', auth()->id())
            ->where('role', \Modules\Tenancy\Models\Membership::ROLE_OWNER)
            ->count();
        $canCreate = $ownedCount < config('tenancy.max_organisations_per_user');
    @endphp

    @if ($current !== null && ($available->count() > 1 || $canCreate))
        <div class="dropdown">
            <a href="#" class="btn btn-sm btn-ghost-secondary dropdown-toggle"
               data-bs-toggle="dropdown" aria-expanded="false">
                <i class="ti ti-building me-1"></i>
                <span class="text-truncate d-inline-block align-bottom" style="max-width: 10rem;">
                    {{ $current->name }}
                </span>
            </a>

            <div class="dropdown-menu dropdown-menu-end">
                <div class="dropdown-header">Workspaces</div>

                @foreach ($available as $organisation)
                    @if ($organisation->is($current))
                        <span class="dropdown-item active d-flex align-items-center">
                            <i class="ti ti-check me-2"></i>{{ $organisation->name }}
                        </span>
                    @else
                        {{-- POST: switching mutates the user's current-workspace
                             pointer, so it must be CSRF-protected. --}}
                        <form method="POST"
                              action="{{ route('tenancy.organisations.switch', $organisation) }}">
                            @csrf
                            <button type="submit" class="dropdown-item d-flex align-items-center">
                                <span class="me-2" style="width: 1em;"></span>{{ $organisation->name }}
                            </button>
                        </form>
                    @endif
                @endforeach

                @if ($canCreate)
                    <div class="dropdown-divider"></div>
                    <a href="{{ route('tenancy.organisations.create') }}" class="dropdown-item">
                        <i class="ti ti-plus me-2"></i>New workspace
                    </a>
                @endif
            </div>
        </div>
    @endif
@endauth
