@extends('admin::layouts.app')

@section('title', 'Subscriptions — back office')
@section('page_pretitle', 'Money')
@section('page_title', 'Subscriptions')

@section('content')
    {{-- Defaults to payment-failed rather than all: an unfiltered list is
         overwhelmingly free-plan rows, whereas these are the ones with a grace period
         ticking down. --}}
    <div class="card">
        <div class="card-header">
            <ul class="nav nav-pills">
                @foreach ($statuses as $value => $label)
                    <li class="nav-item">
                        <a class="nav-link {{ $status === $value ? 'active' : '' }}"
                           href="{{ route('admin.subscriptions.index', ['status' => $value]) }}">
                            {{ $label }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="table-responsive">
            <table class="table card-table table-vcenter">
                <thead>
                    <tr>
                        <th>Workspace</th><th>Plan</th><th class="text-end">Price</th>
                        <th>Status</th><th>Period ends</th><th class="w-1"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($subscriptions as $subscription)
                        <tr>
                            <td>
                                @if ($subscription->organisation)
                                    <a href="{{ route('admin.organisations.show', $subscription->organisation) }}">
                                        {{ $subscription->organisation->name }}
                                    </a>
                                @else
                                    <span class="text-secondary">—</span>
                                @endif
                            </td>
                            <td>{{ $subscription->plan_name }}</td>
                            <td class="text-end">
                                R{{ number_format($subscription->price_cents / 100, 2) }}
                            </td>
                            <td>
                                @php
                                    $badge = match ($subscription->status) {
                                        'active' => 'bg-green-lt',
                                        'past_due' => 'bg-red-lt',
                                        'cancelled' => 'bg-yellow-lt',
                                        default => 'bg-secondary-lt',
                                    };
                                @endphp
                                <span class="badge {{ $badge }}">{{ $subscription->statusLabel() }}</span>

                                @if ($subscription->status === 'past_due' && $subscription->past_due_since)
                                    {{-- The number that actually matters on this screen:
                                         how long before they are downgraded. --}}
                                    <div class="small text-secondary">
                                        Downgrades
                                        {{ $subscription->past_due_since->copy()->addDays(config('billing.grace_period_days'))->diffForHumans() }}
                                    </div>
                                @endif
                            </td>
                            <td class="text-secondary">
                                {{ $subscription->current_period_end->toFormattedDayDateString() }}
                            </td>
                            <td>
                                @if ($subscription->organisation)
                                    <a href="{{ route('admin.organisations.show', $subscription->organisation) }}"
                                       class="btn btn-sm">View</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-secondary">Nothing in this state.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($subscriptions->hasPages())
            <div class="card-footer">{{ $subscriptions->links() }}</div>
        @endif
    </div>
@endsection
