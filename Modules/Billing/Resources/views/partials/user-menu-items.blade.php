{{--
    Billing row for the account dropdown, injected into
    core::layouts.partials.user-menu via @includeIf.

    Owner-only, matching the route's own `organisation.role:owner` gate — a member
    or workspace admin never sees a link that would 403.
--}}
@auth
    @php
        $organisation = app(\Modules\Tenancy\Services\OrganisationContext::class)->get();
        $membership = $organisation?->membershipFor(auth()->user());
    @endphp

    @if ($membership?->canManageBilling())
        <a href="{{ route('billing.index') }}" class="dropdown-item">
            <i class="ti ti-credit-card me-2"></i>Billing &amp; plan
        </a>
    @endif
@endauth
