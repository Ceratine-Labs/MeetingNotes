@extends('admin::layouts.app')

@section('title', 'Payments — back office')
@section('page_pretitle', 'Money')
@section('page_title', 'Payments')

@section('content')
    <div class="card">
        <div class="card-header">
            <form method="GET" action="{{ route('admin.payments.index') }}" class="d-flex gap-2 w-100 flex-wrap">
                <input type="search" name="q" value="{{ $search }}" class="form-control" style="max-width: 20rem;"
                       placeholder="Our reference or Paystack reference…">
                <select name="status" class="form-select w-auto">
                    <option value="">All statuses</option>
                    @foreach (['success' => 'Successful', 'failed' => 'Failed', 'pending' => 'Pending', 'abandoned' => 'Abandoned'] as $value => $label)
                        <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-primary">Filter</button>
                @if ($search !== '' || $status)
                    <a href="{{ route('admin.payments.index') }}" class="btn">Clear</a>
                @endif
            </form>
        </div>

        <div class="table-responsive">
            <table class="table card-table table-vcenter">
                <thead>
                    <tr>
                        <th>Date</th><th>Workspace</th><th>Reference</th>
                        <th>Method</th><th class="text-end">Amount</th><th>Status</th><th class="w-1"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($payments as $payment)
                        <tr>
                            <td class="text-nowrap text-secondary">
                                {{ ($payment->paid_at ?? $payment->created_at)->toFormattedDayDateString() }}
                            </td>
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
                            <td>
                                @if ($payment->card_brand)
                                    {{ ucfirst($payment->card_brand) }}
                                    @if ($payment->card_last4)
                                        &middot;&middot;&middot;&middot;{{ $payment->card_last4 }}
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
                            <td>
                                <a href="{{ route('admin.payments.show', $payment) }}" class="btn btn-sm">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-secondary">No payments match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($payments->hasPages())
            <div class="card-footer">{{ $payments->links() }}</div>
        @endif
    </div>
@endsection
