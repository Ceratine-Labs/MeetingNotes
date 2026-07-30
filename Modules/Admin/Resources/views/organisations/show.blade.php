@extends('admin::layouts.app')

@section('title', $organisation->name . ' — back office')
@section('page_pretitle', 'Workspace')
@section('page_title', $organisation->name)

@section('content')
    <div class="row row-cards">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Subscription</h3></div>
                <div class="card-body">
                    @if ($subscription)
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <div class="text-secondary small">Plan</div>
                                <div class="h3 mb-0">{{ $subscription->plan_name }}</div>
                                <div class="text-secondary">
                                    R{{ number_format($subscription->price_cents / 100, 2) }}
                                    &middot; {{ $subscription->statusLabel() }}
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="text-secondary small">Current period ends</div>
                                <div class="h3 mb-0">
                                    {{ $subscription->current_period_end->toFormattedDayDateString() }}
                                </div>
                                <div class="text-secondary">
                                    {{ $subscription->current_period_end->diffForHumans() }}
                                </div>
                            </div>
                        </div>

                        @if ($subscription->paystack_subscription_code)
                            <hr>
                            <dl class="row mb-0 small">
                                <dt class="col-sm-4 text-secondary">Paystack subscription</dt>
                                <dd class="col-sm-8"><code>{{ $subscription->paystack_subscription_code }}</code></dd>
                                <dt class="col-sm-4 text-secondary">Paystack customer</dt>
                                <dd class="col-sm-8 mb-0"><code>{{ $subscription->paystack_customer_code }}</code></dd>
                            </dl>
                        @endif
                    @else
                        <div class="text-secondary">No subscription on record.</div>
                    @endif
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header"><h3 class="card-title">Members</h3></div>
                <div class="table-responsive">
                    <table class="table card-table table-vcenter">
                        <thead><tr><th>Name</th><th>Email</th><th>Role</th></tr></thead>
                        <tbody>
                            @foreach ($memberships as $membership)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.users.show', $membership->user_id) }}">
                                            {{ $membership->user->name }}
                                        </a>
                                    </td>
                                    <td class="text-secondary">{{ $membership->user->email }}</td>
                                    <td><span class="badge bg-secondary-lt">{{ $membership->roleLabel() }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header"><h3 class="card-title">Payments</h3></div>
                <div class="table-responsive">
                    <table class="table card-table table-vcenter">
                        <thead>
                            <tr><th>Date</th><th>Reference</th><th class="text-end">Amount</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($payments as $payment)
                                <tr>
                                    <td class="text-nowrap">
                                        {{ ($payment->paid_at ?? $payment->created_at)->toFormattedDayDateString() }}
                                    </td>
                                    <td>
                                        <a href="{{ route('admin.payments.show', $payment) }}">
                                            <code class="small">{{ $payment->reference }}</code>
                                        </a>
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
                            @empty
                                <tr><td colspan="4" class="text-secondary">No payments.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Usage</h3></div>
                <div class="card-body">
                    <dl class="mb-0">
                        <dt class="text-secondary small">This period</dt>
                        <dd>
                            {{ $quota->used }}
                            @if (! $quota->isUnlimited())
                                <span class="text-secondary">/ {{ $quota->limit }}</span>
                            @else
                                <span class="text-secondary">(unlimited)</span>
                            @endif
                        </dd>
                        <dt class="text-secondary small">Generated this month</dt>
                        <dd>{{ number_format($usageThisPeriod) }}</dd>
                        <dt class="text-secondary small">Meetings, all time</dt>
                        <dd>{{ number_format($meetingCount) }}</dd>
                        <dt class="text-secondary small">Timezone</dt>
                        <dd class="mb-0">{{ $organisation->timezone }}</dd>
                    </dl>
                    {{-- Stated explicitly so nobody goes looking for a link that is
                         deliberately absent. Support does not require reading a
                         customer's board minutes; impersonation (audited) does. --}}
                    <hr>
                    <div class="text-secondary small">
                        <i class="ti ti-lock me-1"></i>Transcript and minutes content is not
                        readable from the back office.
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header"><h3 class="card-title">Change plan manually</h3></div>
                <div class="card-body">
                    <p class="text-secondary small">
                        Grants the entitlement in this system only. Any live Paystack subscription
                        is left running and must be cancelled there separately.
                    </p>

                    <form method="POST" action="{{ route('admin.organisations.plan', $organisation) }}"
                          data-confirm="This changes what {{ $organisation->name }} is entitled to immediately. Their Paystack subscription is not touched."
                          data-confirm-title="Change this workspace's plan?"
                          data-confirm-button="Yes, change plan">
                        @csrf

                        <div class="mb-3">
                            <label for="plan_code" class="form-label required">Plan</label>
                            <select name="plan_code" id="plan_code" class="form-select" required>
                                @foreach ($plans as $plan)
                                    <option value="{{ $plan->code }}"
                                        @selected($subscription?->plan_code === $plan->code)>
                                        {{ $plan->name }} — R{{ $plan->formattedPrice() }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="reason" class="form-label required">Reason</label>
                            <textarea name="reason" id="reason" rows="2" class="form-control"
                                      placeholder="Comped for beta feedback" required minlength="5"></textarea>
                            <small class="form-hint">Recorded in the audit log.</small>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">Change plan</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
