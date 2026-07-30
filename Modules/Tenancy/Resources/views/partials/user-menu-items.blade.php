{{--
    Workspace rows injected into the account dropdown (core::layouts.partials.
    user-menu) via @includeIf.

    Only shown to members who can act on them: settings and members are
    admin/owner actions, so a plain member sees nothing here rather than links
    that 403.
--}}
@auth
    @php
        $current = app(\Modules\Tenancy\Services\OrganisationContext::class)->get();
        $membership = $current?->membershipFor(auth()->user());
    @endphp

    @if ($membership?->canManageOrganisation())
        <div class="dropdown-divider"></div>
        <div class="dropdown-header">{{ $current->name }}</div>

        <a href="{{ route('tenancy.organisations.edit') }}" class="dropdown-item">
            <i class="ti ti-settings me-2"></i>Workspace settings
        </a>

        <a href="{{ route('tenancy.members.index') }}" class="dropdown-item">
            <i class="ti ti-users me-2"></i>Members
        </a>
    @endif
@endauth
