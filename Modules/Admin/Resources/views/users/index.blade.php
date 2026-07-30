@extends('admin::layouts.app')

@section('title', 'Users — back office')
@section('page_pretitle', 'Customers')
@section('page_title', 'Users')

@section('content')
    <div class="card">
        <div class="card-header">
            <form method="GET" action="{{ route('admin.users.index') }}" class="d-flex gap-2 w-100">
                <input type="search" name="q" value="{{ $search }}" class="form-control"
                       placeholder="Search by name or email…">
                <button type="submit" class="btn btn-primary">Search</button>
                @if ($search !== '')
                    <a href="{{ route('admin.users.index') }}" class="btn">Clear</a>
                @endif
            </form>
        </div>

        <div class="table-responsive">
            <table class="table card-table table-vcenter">
                <thead>
                    <tr>
                        <th>Name</th><th>Email</th><th>Workspaces</th>
                        <th>Confirmed</th><th>Last sign-in</th><th class="w-1"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr>
                            <td><a href="{{ route('admin.users.show', $user) }}">{{ $user->name }}</a></td>
                            <td class="text-secondary">{{ $user->email }}</td>
                            <td>
                                @foreach ($user->memberships as $membership)
                                    @if ($membership->organisation)
                                        <a href="{{ route('admin.organisations.show', $membership->organisation) }}"
                                           class="badge bg-secondary-lt text-reset">
                                            {{ $membership->organisation->name }}
                                        </a>
                                    @endif
                                @endforeach
                            </td>
                            <td>
                                @if ($user->hasVerifiedEmail())
                                    <i class="ti ti-check text-green" title="Email confirmed"></i>
                                @else
                                    <i class="ti ti-x text-secondary" title="Email not confirmed"></i>
                                @endif
                            </td>
                            <td class="text-secondary">
                                {{ $user->last_login_at?->diffForHumans() ?? 'Never' }}
                            </td>
                            <td>
                                <a href="{{ route('admin.users.show', $user) }}" class="btn btn-sm">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-secondary">
                                {{ $search !== '' ? 'No users match that search.' : 'No users yet.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($users->hasPages())
            <div class="card-footer">{{ $users->links() }}</div>
        @endif
    </div>
@endsection
