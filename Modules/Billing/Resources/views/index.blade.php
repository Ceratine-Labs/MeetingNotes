@extends('core::layouts.app')

{{-- Card-brand logos on the payment history table. --}}
@section('tabler_payments', true)

@section('title', 'Billing — ' . config('app.name'))
@section('page_pretitle', $organisation->name)
@section('page_title', 'Billing')

@section('page_actions')
    <a href="{{ route('billing.plans') }}" class="btn btn-primary">
        <i class="ti ti-arrow-up me-1"></i>Change plan
    </a>
@endsection

@section('content')
    <div class="row row-cards">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Current plan</h3>
                    @if ($subscription)
                        <div class="card-actions">
                            <span class="badge {{ $subscription->status === 'active' ? 'bg-green-lt' : 'bg-yellow-lt' }}">
                                {{ $subscription->statusLabel() }}
                            </span>
                        </div>
                    @endif
                </div>

                <div class="card-body">
                    @if ($subscription === null)
                        {{-- Shouldn't be reachable: every workspace is provisioned onto
                             free at creation. Reachable for data that predates Billing,
                             so it explains itself instead of rendering a broken page. --}}
                        <div class="empty p-0">
                            <p class="empty-title h4">No subscription on record</p>
                            <p class="empty-subtitle text-secondary">
                                Choose a plan to get started.
                            </p>
                        </div>
                    @else
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <div class="text-secondary small">Plan</div>
                                <div class="h3 mb-0">{{ $subscription->plan_name }}</div>
                                <div class="text-secondary">
                                    @if ($subscription->price_cents === 0)
                                        No charge
                                    @else
                                        R{{ number_format($subscription->price_cents / 100, 2) }}
                                        per {{ $subscription->plan?->interval === 'annually' ? 'year' : 'month' }}
                                    @endif
                                </div>
                            </div>

                            <div class="col-sm-6">
                                <div class="text-secondary small">
                                    {{ $subscription->isCancelled() ? 'Access ends' : 'Renews' }}
                                </div>
                                <div class="h3 mb-0">
                                    {{ $subscription->current_period_end->toFormattedDayDateString() }}
                                </div>
                                <div class="text-secondary">
                                    {{ $subscription->current_period_end->diffForHumans() }}
                                </div>
                            </div>
                        </div>

                        @if ($subscription->status === \Modules\Billing\Models\Subscription::STATUS_PAST_DUE)
                            <div class="alert alert-warning mt-3 mb-0" role="alert">
                                <div class="d-flex">
                                    <i class="ti ti-alert-triangle me-2 mt-1"></i>
                                    <div>
                                        <h4 class="alert-title">A renewal payment failed</h4>
                                        <div class="text-secondary">
                                            Your workspace keeps working for now. Update your card by
                                            choosing your plan again, otherwise it will move to the
                                            free plan
                                            {{ $subscription->past_due_since->copy()->addDays(config('billing.grace_period_days'))->diffForHumans() }}.
                                            Nothing you have created will be deleted.
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif

                        @if ($subscription->isCancelled())
                            <div class="alert alert-info mt-3 mb-0" role="alert">
                                <div class="d-flex">
                                    <i class="ti ti-info-circle me-2 mt-1"></i>
                                    <div>
                                        This subscription is cancelled. You keep
                                        {{ $subscription->plan_name }} features until
                                        {{ $subscription->current_period_end->toFormattedDayDateString() }},
                                        then move to the free plan.
                                    </div>
                                </div>
                            </div>
                        @endif
                    @endif
                </div>

                @if ($subscription && ! $subscription->isFree() && ! $subscription->isCancelled())
                    <div class="card-footer">
                        {{-- data-confirm is intercepted by app.js and gated behind
                             SweetAlert2 (house rule: no native confirm). --}}
                        <form method="POST" action="{{ route('billing.cancel') }}"
                              data-confirm="You will keep {{ $subscription->plan_name }} features until {{ $subscription->current_period_end->toFormattedDayDateString() }}, then move to the free plan. None of your minutes are deleted."
                              data-confirm-title="Cancel this subscription?"
                              data-confirm-button="Yes, cancel"
                              data-confirm-danger="1">
                            @csrf
                            <button type="submit" class="btn btn-ghost-danger">Cancel subscription</button>
                        </form>
                    </div>
                @endif
            </div>

            <div class="card mt-3">
                <div class="card-header">
                    <h3 class="card-title">Payment history</h3>
                </div>

                @if ($payments->isEmpty())
                    <div class="card-body">
                        <div class="empty p-0">
                            <p class="empty-title h4">No payments yet</p>
                            <p class="empty-subtitle text-secondary">
                                Payments will appear here once you move to a paid plan.
                            </p>
                        </div>
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table card-table table-vcenter">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Reference</th>
                                    <th>Method</th>
                                    <th class="text-end">Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($payments as $payment)
                                    <tr>
                                        <td class="text-nowrap">
                                            {{ ($payment->paid_at ?? $payment->created_at)->toFormattedDayDateString() }}
                                        </td>
                                        <td><code class="small">{{ $payment->reference }}</code></td>
                                        <td>
                                            @if ($payment->card_brand)
                                                {{ ucfirst($payment->card_brand) }}
                                                @if ($payment->card_last4)
                                                    &middot;&middot;&middot;&middot; {{ $payment->card_last4 }}
                                                @endif
                                            @else
                                                <span class="text-secondary">{{ $payment->channel ?? '—' }}</span>
                                            @endif
                                        </td>
                                        <td class="text-end">R{{ $payment->formattedAmount() }}</td>
                                        <td>
                                            @php
                                                $badge = match ($payment->status) {
                                                    'success' => 'bg-green-lt',
                                                    'failed' => 'bg-red-lt',
                                                    'pending' => 'bg-yellow-lt',
                                                    default => 'bg-secondary-lt',
                                                };
                                            @endphp
                                            <span class="badge {{ $badge }}">{{ ucfirst($payment->status) }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">This period</h3>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-baseline mb-2">
                        <span class="text-secondary">Minutes generated</span>
                        <span class="h3 mb-0">
                            {{ $quota->used }}@if (! $quota->isUnlimited())<span class="text-secondary"> / {{ $quota->limit }}</span>@endif
                        </span>
                    </div>

                    @if ($quota->isUnlimited())
                        <div class="text-secondary small">Unlimited on the {{ $quota->planName }} plan.</div>
                    @else
                        <div class="progress progress-sm mb-2">
                            <div class="progress-bar {{ $quota->remaining() === 0 ? 'bg-danger' : ($quota->shouldWarn() ? 'bg-warning' : 'bg-primary') }}"
                                 style="width: {{ $quota->percentUsed() }}%"
                                 role="progressbar"
                                 aria-valuenow="{{ $quota->used }}"
                                 aria-valuemin="0"
                                 aria-valuemax="{{ $quota->limit }}"
                                 aria-label="Generations used this period"></div>
                        </div>
                        <div class="text-secondary small">
                            {{ $quota->remaining() }} {{ Str::plural('generation', $quota->remaining()) }} left,
                            resetting {{ $quota->periodEnd->diffForHumans() }}.
                        </div>
                    @endif

                    <hr>

                    <div class="text-secondary small">
                        Period: {{ $quota->periodStart->toFormattedDayDateString() }}
                        — {{ $quota->periodEnd->toFormattedDayDateString() }}
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header">
                    <h3 class="card-title">Seats</h3>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-baseline">
                        <span class="text-secondary">Members</span>
                        <span class="h3 mb-0">
                            {{ $organisation->seatsInUse() }}@if ($subscription && ! $subscription->hasUnlimitedSeats())<span class="text-secondary"> / {{ $subscription->seat_limit }}</span>@endif
                        </span>
                    </div>
                    <a href="{{ route('tenancy.members.index') }}" class="btn btn-sm w-100 mt-3">
                        <i class="ti ti-users me-1"></i>Manage members
                    </a>
                </div>
            </div>
        </div>
    </div>
@endsection
