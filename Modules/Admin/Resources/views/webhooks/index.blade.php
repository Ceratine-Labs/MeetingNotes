@extends('admin::layouts.app')

@section('title', 'Payment webhooks — back office')
@section('page_pretitle', 'Money')
@section('page_title', 'Payment webhooks')

@section('content')
    <div class="alert alert-info" role="alert">
        <div class="d-flex">
            <i class="ti ti-info-circle me-2 mt-1"></i>
            <div>
                Webhooks are how subscriptions stay correct over time — renewals have no
                browser involved at all. An event that arrived but never applied can leave a
                customer on the wrong plan with nothing visibly broken, so those are worth
                checking.
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <ul class="nav nav-pills">
                @foreach (['all' => 'All', 'unprocessed' => 'Unprocessed', 'failed' => 'Failed'] as $value => $label)
                    <li class="nav-item">
                        <a class="nav-link {{ $filter === $value ? 'active' : '' }}"
                           href="{{ route('admin.webhooks.index', ['filter' => $value]) }}">{{ $label }}</a>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="table-responsive">
            <table class="table card-table table-vcenter">
                <thead>
                    <tr>
                        <th>Received</th><th>Event</th><th>Handled</th>
                        <th>Attempts</th><th>Error</th><th class="w-1"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($events as $event)
                        <tr>
                            <td class="text-nowrap text-secondary">
                                {{ $event->created_at->toFormattedDayDateString() }}
                                <div class="small">{{ $event->created_at->format('H:i') }}</div>
                            </td>
                            <td>
                                <code class="small">{{ $event->event_type }}</code>
                                @unless (in_array($event->event_type, $handledTypes, true))
                                    {{-- Recorded for the audit trail but carries no
                                         entitlement change — not a problem. --}}
                                    <div class="small text-secondary">Not acted on</div>
                                @endunless
                            </td>
                            <td>
                                @if ($event->isProcessed())
                                    <span class="badge bg-green-lt">
                                        {{ $event->processed_at->diffForHumans() }}
                                    </span>
                                @elseif ($event->attempts > 0)
                                    <span class="badge bg-red-lt">Failed</span>
                                @else
                                    <span class="badge bg-yellow-lt">Pending</span>
                                @endif
                            </td>
                            <td>{{ $event->attempts }}</td>
                            <td class="text-secondary small">{{ Str::limit($event->error, 60) }}</td>
                            <td>
                                <a href="{{ route('admin.webhooks.show', $event) }}" class="btn btn-sm">Inspect</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-secondary">No events.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($events->hasPages())
            <div class="card-footer">{{ $events->links() }}</div>
        @endif
    </div>
@endsection
