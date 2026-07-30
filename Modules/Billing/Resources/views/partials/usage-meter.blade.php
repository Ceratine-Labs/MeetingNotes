{{--
    Generation-allowance meter for the app sidebar.

    Included by core::layouts.partials.sidebar via @includeIf, so Core carries no
    hard dependency on Billing. It resolves its own data because it is included
    from a Core layout that must keep rendering with this module absent.

    Renders nothing for unlimited plans — a progress bar that can never fill is
    just noise in the sidebar.
--}}
@auth
    @php
        $organisation = app(\Modules\Tenancy\Services\OrganisationContext::class)->get();
        $quota = $organisation !== null
            ? app(\Modules\Billing\Services\QuotaService::class)->statusFor($organisation)
            : null;
    @endphp

    @if ($quota !== null && ! $quota->isUnlimited())
        @php
            // Amber when running low, red when exhausted, primary otherwise.
            $barClass = match (true) {
                $quota->remaining() === 0 => 'bg-danger',
                $quota->shouldWarn() => 'bg-warning',
                default => 'bg-primary',
            };
        @endphp

        <div class="px-3 py-2 border-top">
            <div class="d-flex justify-content-between align-items-baseline mb-1">
                <span class="small text-secondary">{{ $quota->planName }} plan</span>
                <span class="small text-secondary">{{ $quota->summary() }}</span>
            </div>

            <div class="progress progress-sm mb-2">
                <div class="progress-bar {{ $barClass }}"
                     style="width: {{ $quota->percentUsed() }}%"
                     role="progressbar"
                     aria-valuenow="{{ $quota->used }}"
                     aria-valuemin="0"
                     aria-valuemax="{{ $quota->limit }}"
                     aria-label="Generations used this period">
                </div>
            </div>

            @if ($quota->remaining() === 0)
                <div class="small text-danger mb-1">
                    <i class="ti ti-alert-circle me-1"></i>No generations left
                </div>
            @elseif ($quota->shouldWarn())
                <div class="small text-warning mb-1">
                    <i class="ti ti-alert-triangle me-1"></i>{{ $quota->remaining() }} left
                </div>
            @endif

            <div class="small text-secondary">
                Resets {{ $quota->periodEnd->diffForHumans() }}
            </div>

            {{-- Upgrade link only for owners — billing is owner-only, so showing it
                 to a member would be a link straight to a 403. --}}
            @if ($organisation->membershipFor(auth()->user())?->canManageBilling() && Route::has('billing.plans'))
                <a href="{{ route('billing.plans') }}" class="btn btn-sm btn-outline-primary w-100 mt-2">
                    <i class="ti ti-arrow-up me-1"></i>Upgrade
                </a>
            @endif
        </div>
    @endif
@endauth
