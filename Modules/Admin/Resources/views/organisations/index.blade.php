@extends('admin::layouts.app')

@section('title', 'Workspaces — back office')
@section('page_pretitle', 'Customers')
@section('page_title', 'Workspaces')

@section('content')
    <div class="card">
        <div class="card-header">
            <form method="GET" action="{{ route('admin.organisations.index') }}" class="d-flex gap-2 w-100">
                <input type="search" name="q" value="{{ $search }}" class="form-control"
                       placeholder="Search by name or identifier…">
                <button type="submit" class="btn btn-primary">Search</button>
                @if ($search !== '')
                    <a href="{{ route('admin.organisations.index') }}" class="btn">Clear</a>
                @endif
            </form>
        </div>

        <div class="table-responsive">
            <table class="table card-table table-vcenter">
                <thead>
                    <tr>
                        <th>Workspace</th>
                        <th>Plan</th>
                        <th>Members</th>
                        <th>Created</th>
                        <th class="w-1"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($organisations as $organisation)
                        @php
                            // Pre-fetched and keyed in the controller, so this is a
                            // lookup rather than a query per row.
                            $subscription = $subscriptions->get($organisation->id);
                        @endphp
                        <tr>
                            <td>
                                <a href="{{ route('admin.organisations.show', $organisation) }}">
                                    {{ $organisation->name }}
                                </a>
                                <div class="text-secondary small"><code>{{ $organisation->slug }}</code></div>
                            </td>
                            <td>
                                @if ($subscription)
                                    <span class="badge {{ $subscription->price_cents > 0 ? 'bg-green-lt' : 'bg-secondary-lt' }}">
                                        {{ $subscription->plan_name }}
                                    </span>
                                    @if ($subscription->status !== 'active')
                                        <div class="small text-warning">{{ $subscription->statusLabel() }}</div>
                                    @endif
                                @else
                                    <span class="text-secondary">—</span>
                                @endif
                            </td>
                            <td>{{ $organisation->memberships_count }}</td>
                            <td class="text-secondary">{{ $organisation->created_at->toFormattedDayDateString() }}</td>
                            <td>
                                <a href="{{ route('admin.organisations.show', $organisation) }}" class="btn btn-sm">
                                    View
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-secondary">
                                {{ $search !== '' ? 'No workspaces match that search.' : 'No workspaces yet.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($organisations->hasPages())
            <div class="card-footer">{{ $organisations->links() }}</div>
        @endif
    </div>
@endsection
