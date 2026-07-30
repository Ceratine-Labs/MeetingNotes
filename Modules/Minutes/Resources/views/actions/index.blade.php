@extends('core::layouts.app')

@section('title', 'Action items: ' . config('app.name'))

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-0">Action items</h1>
            <div class="text-secondary small">
                Everything owed from every meeting in this workspace.
                {{ $openCount }} {{ Str::plural('item', $openCount) }} open.
            </div>
        </div>
        <a href="{{ route('minutes.index') }}" class="btn">
            <i class="ti ti-files me-1"></i>Minutes library
        </a>
    </div>

    <form method="GET" class="row g-2 mb-3">
        <div class="col-12 col-sm">
            <input type="search" name="q" value="{{ request('q') }}" class="form-control"
                   placeholder="Search actions and owners…">
        </div>
        <div class="col-6 col-sm-auto">
            <select name="status" class="form-select" onchange="this.form.submit()">
                <option value="" @selected($status === '')>Open</option>
                <option value="done" @selected($status === 'done')>Done</option>
                <option value="all" @selected($status === 'all')>All</option>
            </select>
        </div>
        <div class="col-6 col-sm-auto">
            {{-- Owners are free-text names from the minutes themselves, so the
                 workspace's real values are the only sensible options. --}}
            <select name="owner" class="form-select" data-tom-select data-placeholder="Any owner"
                    onchange="this.form.submit()">
                <option value="">Any owner</option>
                @foreach ($owners as $owner)
                    <option value="{{ $owner }}" @selected(request('owner') === $owner)>{{ $owner }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <button class="btn btn-outline-secondary">Filter</button>
        </div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead>
                    <tr>
                        <th class="w-1"></th>
                        <th class="w-1">Ref</th>
                        <th>Action</th>
                        <th>Owner</th>
                        <th>Due</th>
                        <th>Priority</th>
                        <th>Meeting</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $item)
                        <tr class="{{ $item->isDone() ? 'text-secondary' : '' }}">
                            <td>
                                {{-- Plain form POST: works without JS, and back()
                                     preserves the current filters and page. --}}
                                <form method="POST" action="{{ route('minutes.actions.update', $item) }}">
                                    @csrf @method('PUT')
                                    <input type="hidden" name="status"
                                           value="{{ $item->isDone() ? 'open' : 'done' }}">
                                    <button class="btn btn-sm {{ $item->isDone() ? 'btn-success' : 'btn-outline-secondary' }}"
                                            title="{{ $item->isDone() ? 'Reopen' : 'Mark done' }}">
                                        <i class="ti ti-check"></i>
                                    </button>
                                </form>
                            </td>
                            <td class="fw-semibold">{{ $item->ref }}</td>
                            <td>
                                <span class="{{ $item->isDone() ? 'text-decoration-line-through' : '' }}">
                                    {{ $item->description }}
                                </span>
                                @if ($item->success_criteria)
                                    <div class="small text-secondary">Done when: {{ $item->success_criteria }}</div>
                                @endif
                                @if ($item->isDone() && $item->completed_at)
                                    <div class="small text-secondary">
                                        Done {{ $item->completed_at->format('Y-m-d') }}{{ $item->completedBy ? ' by ' . $item->completedBy->name : '' }}
                                    </div>
                                @endif
                            </td>
                            <td>{{ $item->owner }}</td>
                            <td class="text-secondary">{{ $item->due_date ?: 'Not specified' }}</td>
                            <td>
                                @switch($item->priority)
                                    @case('high') <span class="badge bg-red-lt">high</span> @break
                                    @case('low') <span class="badge bg-secondary-lt">low</span> @break
                                    @default <span class="badge bg-yellow-lt">medium</span>
                                @endswitch
                            </td>
                            <td>
                                <a href="{{ route('minutes.show', $item->meeting_id) }}" class="text-decoration-none">
                                    {{ $item->meeting->title ?? 'Untitled meeting' }}
                                </a>
                                @if ($item->meeting?->meeting_date)
                                    <div class="small text-secondary">{{ $item->meeting->meeting_date->format('Y-m-d') }}</div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-secondary py-5">
                                <i class="ti ti-list-check fs-1 d-block mb-2"></i>
                                @if ($status === '' && ! request()->filled('owner') && ! request()->filled('q'))
                                    Nothing open. Either you are admirably on top of things,
                                    or it is time to <a href="{{ route('minutes.create') }}">minute a meeting</a>.
                                @else
                                    No action items match these filters.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $items->links() }}</div>
@endsection
