{{--
    One plan, as a card. Shared between the public pricing page (Site module) and
    the in-app plan picker so the two can never disagree about what a tier
    includes — the copy lives in one file.

    Required:
        $plan     Modules\Billing\Models\Plan

    Optional:
        $current      bool   This is the viewer's active plan.
        $actionUrl    string Where the CTA goes. Omit to render no CTA (public
                             page for a signed-out visitor uses $registerUrl).
        $actionMethod 'get'|'post'  Defaults to post — checkout is a state change.
        $actionLabel  string
        $disabled     bool
        $disabledNote string  Why the CTA is unavailable.
--}}
@php
    $current ??= false;
    $actionUrl ??= null;
    $actionMethod ??= 'post';
    $actionLabel ??= 'Choose ' . $plan->name;
    $disabled ??= false;
    $disabledNote ??= null;

    // Drawn from the plan's own feature flags so marking a different tier as
    // recommended is an admin edit, not a code change.
    $recommended = (bool) $plan->feature('recommended', false);

    $exports = $plan->feature('exports', []);
    $exports = is_array($exports) ? $exports : [];
@endphp

<div class="card h-100 {{ $recommended ? 'mn-plan-featured' : '' }}">
    @if ($recommended)
        <div class="ribbon ribbon-top bg-primary">
            <i class="ti ti-star-filled"></i>
        </div>
    @endif

    <div class="card-body d-flex flex-column">
        <div class="mb-3">
            <div class="d-flex align-items-center gap-2">
                <h3 class="h2 mb-0">{{ $plan->name }}</h3>
                @if ($current)
                    <span class="badge bg-green-lt">Current plan</span>
                @endif
            </div>
            @if ($plan->tagline)
                <div class="text-secondary small mt-1">{{ $plan->tagline }}</div>
            @endif
        </div>

        <div class="mb-3">
            @if ($plan->price_cents === 0)
                <span class="h1 mb-0">Free</span>
                <div class="text-secondary small">No card required, ever</div>
            @else
                <span class="h1 mb-0">R{{ $plan->formattedPrice() }}</span>
                <span class="text-secondary">
                    / {{ $plan->interval === \Modules\Billing\Models\Plan::INTERVAL_ANNUALLY ? 'year' : 'month' }}
                </span>
                <div class="text-secondary small">Excl. VAT where applicable</div>
            @endif
        </div>

        <ul class="list-unstyled space-y mb-4">
            <li class="d-flex align-items-start">
                <i class="ti ti-check text-green me-2 mt-1"></i>
                <span>
                    <strong>
                        {{ $plan->hasUnlimitedGenerations() ? 'Unlimited' : $plan->generation_quota }}
                    </strong>
                    {{ $plan->hasUnlimitedGenerations() ? 'sets of minutes' : Str::plural('set of minutes', $plan->generation_quota) }}
                    a month
                </span>
            </li>

            <li class="d-flex align-items-start">
                <i class="ti ti-check text-green me-2 mt-1"></i>
                <span>
                    <strong>{{ $plan->hasUnlimitedSeats() ? 'Unlimited' : $plan->seat_limit }}</strong>
                    {{ $plan->hasUnlimitedSeats() ? 'team members' : Str::plural('team member', $plan->seat_limit) }}
                </span>
            </li>

            <li class="d-flex align-items-start">
                <i class="ti ti-check text-green me-2 mt-1"></i>
                <span>All nine minute sections, every time</span>
            </li>

            <li class="d-flex align-items-start">
                <i class="ti ti-check text-green me-2 mt-1"></i>
                <span>
                    Export as
                    {{ collect($exports)->map(fn (string $f): string => Str::upper($f))->join(', ', ' and ') }}
                </span>
            </li>

            <li class="d-flex align-items-start">
                <i class="ti ti-{{ $plan->feature('custom_prompts') ? 'check text-green' : 'minus text-secondary' }} me-2 mt-1"></i>
                <span class="{{ $plan->feature('custom_prompts') ? '' : 'text-secondary' }}">
                    Edit the generation prompts
                </span>
            </li>

            <li class="d-flex align-items-start">
                <i class="ti ti-{{ $plan->feature('api') ? 'check text-green' : 'minus text-secondary' }} me-2 mt-1"></i>
                <span class="{{ $plan->feature('api') ? '' : 'text-secondary' }}">API access</span>
            </li>
        </ul>

        {{-- mt-auto pins the CTA to the bottom so buttons line up across cards of
             differing feature-list length. --}}
        <div class="mt-auto">
            @if ($current)
                <button class="btn w-100" disabled>Your current plan</button>
            @elseif ($disabled)
                <button class="btn w-100" disabled>{{ $actionLabel }}</button>
                @if ($disabledNote)
                    <div class="small text-secondary text-center mt-2">{{ $disabledNote }}</div>
                @endif
            @elseif ($actionUrl && $actionMethod === 'post')
                <form method="POST" action="{{ $actionUrl }}">
                    @csrf
                    <button type="submit" class="btn w-100 {{ $recommended ? 'btn-primary' : 'btn-outline-primary' }}">
                        {{ $actionLabel }}
                    </button>
                </form>
            @elseif ($actionUrl)
                <a href="{{ $actionUrl }}"
                   class="btn w-100 {{ $recommended ? 'btn-primary' : 'btn-outline-primary' }}">
                    {{ $actionLabel }}
                </a>
            @endif
        </div>
    </div>
</div>
