@extends('admin::layouts.app')

@section('title', 'Back office — ' . config('app.name'))
@section('page_pretitle', 'Overview')
@section('page_title', 'Dashboard')

@section('content')
    @unless ($billingEnabled)
        {{-- Says so once, at the top, rather than leaving zeroes everywhere to be
             misread as "no sales". --}}
        <div class="alert alert-warning" role="alert">
            <div class="d-flex">
                <i class="ti ti-alert-triangle me-2 mt-1"></i>
                <div>
                    <h4 class="alert-title">Billing is switched off</h4>
                    <div class="text-secondary">
                        <code>BILLING_ENABLED</code> is false, so every workspace runs on the free
                        plan and no payment can be taken. Set <code>PAYSTACK_SECRET_KEY</code> and
                        <code>BILLING_ENABLED=true</code>, then push each paid plan to Paystack from
                        the <a href="{{ route('admin.plans.index') }}">plans</a> screen.
                    </div>
                </div>
            </div>
        </div>
    @endunless

    {{-- Headline stats --}}
    <div class="row row-cards">
        @php
            $tiles = [
                ['Workspaces', number_format($counts['organisations']), 'building', 'primary', null],
                ['Users', number_format($counts['users']), 'users', 'primary', null],
                ['Paying workspaces', number_format($counts['paying']), 'cash', 'green', null],
                // Only worth colouring red when it is non-zero — a permanently red
                // zero teaches people to ignore the tile.
                ['Payment failures', number_format($counts['past_due']), 'alert-triangle',
                    $counts['past_due'] > 0 ? 'red' : 'secondary',
                    $counts['past_due'] > 0 ? route('admin.subscriptions.index') : null],
            ];
        @endphp

        @foreach ($tiles as [$label, $value, $icon, $colour, $href])
            <div class="col-sm-6 col-lg-3">
                <div class="card card-sm">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-auto">
                                <span class="bg-{{ $colour }} text-white avatar">
                                    <i class="ti ti-{{ $icon }}"></i>
                                </span>
                            </div>
                            <div class="col">
                                <div class="font-weight-medium">{{ $value }}</div>
                                <div class="text-secondary">
                                    @if ($href)
                                        <a href="{{ $href }}" class="text-reset">{{ $label }}</a>
                                    @else
                                        {{ $label }}
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Revenue --}}
    <div class="row row-cards mt-3">
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Revenue collected</h3></div>
                <div class="card-body">
                    @php
                        // Amounts are stored in cents; divide only at the point of display.
                        $thisMonth = $revenue['this_month'] / 100;
                        $lastMonth = $revenue['last_month'] / 100;
                        $delta = $lastMonth > 0 ? (($thisMonth - $lastMonth) / $lastMonth) * 100 : null;
                    @endphp

                    <div class="d-flex align-items-baseline gap-2">
                        <div class="h1 mb-0">R{{ number_format($thisMonth, 2) }}</div>
                        @if ($delta !== null)
                            <span class="text-{{ $delta >= 0 ? 'green' : 'red' }}">
                                {{ $delta >= 0 ? '+' : '' }}{{ number_format($delta, 1) }}%
                            </span>
                        @endif
                    </div>
                    <div class="text-secondary">This month</div>

                    <hr>

                    <dl class="mb-0">
                        <dt class="text-secondary small">Last month</dt>
                        <dd>R{{ number_format($lastMonth, 2) }}</dd>
                        <dt class="text-secondary small">All time</dt>
                        <dd class="mb-0">R{{ number_format($revenue['all_time'] / 100, 2) }}</dd>
                    </dl>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header"><h3 class="card-title">Usage</h3></div>
                <div class="card-body">
                    <div class="h1 mb-0">{{ number_format($counts['generations_this_month']) }}</div>
                    <div class="text-secondary">Sets of minutes generated this month</div>
                    <hr>
                    <dl class="mb-0">
                        <dt class="text-secondary small">Meetings, all time</dt>
                        <dd class="mb-0">{{ number_format($counts['meetings']) }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Workspaces by plan</h3></div>
                <div class="table-responsive">
                    <table class="table card-table table-vcenter">
                        <thead>
                            <tr><th>Plan</th><th class="text-end">Live subscriptions</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($planBreakdown as $row)
                                <tr>
                                    <td>{{ $row->plan_name }}</td>
                                    <td class="text-end">{{ number_format($row->total) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="2" class="text-secondary">No subscriptions yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($failedWebhooks->isNotEmpty())
                {{-- Prominent because this is the failure that is otherwise invisible:
                     a webhook that arrived, failed to apply, and left a customer on the
                     wrong plan with nothing obviously broken. --}}
                <div class="card mt-3 border-danger">
                    <div class="card-header">
                        <h3 class="card-title text-danger">
                            <i class="ti ti-alert-circle me-1"></i>Webhooks that failed to apply
                        </h3>
                        <div class="card-actions">
                            <a href="{{ route('admin.webhooks.index', ['filter' => 'failed']) }}"
                               class="btn btn-sm">View all</a>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table card-table table-vcenter">
                            <thead>
                                <tr><th>Event</th><th>Attempts</th><th>Error</th><th></th></tr>
                            </thead>
                            <tbody>
                                @foreach ($failedWebhooks as $event)
                                    <tr>
                                        <td><code class="small">{{ $event->event_type }}</code></td>
                                        <td>{{ $event->attempts }}</td>
                                        <td class="text-secondary small">
                                            {{ Str::limit($event->error, 80) }}
                                        </td>
                                        <td class="text-end">
                                            <a href="{{ route('admin.webhooks.show', $event) }}"
                                               class="btn btn-sm">Inspect</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            <div class="card mt-3">
                <div class="card-header">
                    <h3 class="card-title">Recent payments</h3>
                    <div class="card-actions">
                        <a href="{{ route('admin.payments.index') }}" class="btn btn-sm">All payments</a>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table card-table table-vcenter">
                        <thead>
                            <tr>
                                <th>Workspace</th><th>Reference</th>
                                <th class="text-end">Amount</th><th>Status</th><th>When</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($recentPayments as $payment)
                                <tr>
                                    <td>
                                        @if ($payment->organisation)
                                            <a href="{{ route('admin.organisations.show', $payment->organisation) }}">
                                                {{ $payment->organisation->name }}
                                            </a>
                                        @else
                                            <span class="text-secondary">—</span>
                                        @endif
                                    </td>
                                    <td><code class="small">{{ $payment->reference }}</code></td>
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
                                    <td class="text-secondary">{{ $payment->created_at->diffForHumans() }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-secondary">No payments yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header">
                    <h3 class="card-title">Recent back-office activity</h3>
                    <div class="card-actions">
                        <a href="{{ route('admin.audit.index') }}" class="btn btn-sm">Full audit log</a>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table card-table table-vcenter">
                        <thead>
                            <tr><th>Action</th><th>By</th><th>When</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($recentAudit as $entry)
                                <tr>
                                    <td>{{ $entry->label() }}</td>
                                    <td class="text-secondary">{{ $entry->admin_email ?? '—' }}</td>
                                    <td class="text-secondary">{{ $entry->created_at->diffForHumans() }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-secondary">Nothing recorded yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
