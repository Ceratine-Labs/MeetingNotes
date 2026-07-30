@extends('core::layouts.app')

@section('title', 'Minutes Library: ' . config('app.name'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Minutes Library</h1>
        <a href="{{ route('minutes.create') }}" class="btn btn-primary">
            <i class="ti ti-circle-plus me-1"></i> New minutes
        </a>
    </div>

    <form method="GET" class="row g-2 mb-3" style="max-width: 640px;">
        <div class="col">
            <input type="search" name="q" value="{{ request('q') }}" class="form-control" placeholder="Search titles…">
        </div>
        <div class="col-auto">
            <select name="status" class="form-select" onchange="this.form.submit()">
                <option value="">All statuses</option>
                @foreach (['processing', 'ready', 'failed'] as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto"><button class="btn btn-outline-secondary">Filter</button></div>
    </form>

    <div class="card">
        <div class="table-responsive">
            {{--
                Mobile shows the pertinent columns only (title, status); the
                dates, counts, model and the delete action fold into a
                chevron-toggled .mn-row-detail row. Desktop shows every column
                and never sees the detail rows (d-md-none).
            --}}
            <table class="table mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th class="d-none d-md-table-cell">Meeting date</th>
                        <th>Status</th>
                        <th class="text-end d-none d-md-table-cell">Decisions</th>
                        <th class="text-end d-none d-md-table-cell">Actions</th>
                        <th class="d-none d-md-table-cell">Model</th>
                        <th class="d-none d-md-table-cell">Created</th>
                        <th class="d-none d-md-table-cell"></th>
                        <th class="w-1 d-md-none"><span class="visually-hidden">Details</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($meetings as $meeting)
                        <tr>
                            <td>
                                <a href="{{ route('minutes.show', $meeting) }}" class="text-decoration-none">
                                    {{ $meeting->title ?? 'Untitled meeting' }}
                                </a>
                            </td>
                            <td class="text-secondary d-none d-md-table-cell">{{ $meeting->meeting_date?->format('Y-m-d') ?? '—' }}</td>
                            <td>
                                @switch($meeting->status)
                                    @case('ready') <span class="badge text-bg-success">ready</span> @break
                                    @case('processing') <span class="badge text-bg-info">processing</span> @break
                                    @case('failed') <span class="badge text-bg-danger">failed</span> @break
                                    @default <span class="badge text-bg-secondary">{{ $meeting->status }}</span>
                                @endswitch
                            </td>
                            <td class="text-end d-none d-md-table-cell">{{ $meeting->decisions_count }}</td>
                            <td class="text-end d-none d-md-table-cell">{{ $meeting->action_items_count }}</td>
                            <td class="small text-secondary d-none d-md-table-cell">{{ $meeting->model_used ?? '—' }}</td>
                            <td class="small text-secondary d-none d-md-table-cell">{{ $meeting->created_at->format('Y-m-d H:i') }}</td>
                            <td class="text-end d-none d-md-table-cell">
                                <form method="POST" action="{{ route('minutes.destroy', $meeting) }}"
                                      data-confirm="Delete these minutes and their transcript?" class="d-inline">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="ti ti-trash"></i></button>
                                </form>
                            </td>
                            <td class="d-md-none text-end">
                                <button type="button" class="btn btn-sm btn-ghost-secondary mn-row-toggle"
                                        data-mn-row-toggle aria-expanded="false" aria-label="Show details">
                                    <i class="ti ti-chevron-down"></i>
                                </button>
                            </td>
                        </tr>
                        <tr class="mn-row-detail d-md-none" hidden>
                            <td colspan="3">
                                <dl class="mn-row-detail-list">
                                    <dt>Meeting date</dt>
                                    <dd>{{ $meeting->meeting_date?->format('Y-m-d') ?? 'Not set' }}</dd>
                                    <dt>Decisions</dt>
                                    <dd>{{ $meeting->decisions_count }}</dd>
                                    <dt>Actions</dt>
                                    <dd>{{ $meeting->action_items_count }}</dd>
                                    <dt>Model</dt>
                                    <dd>{{ $meeting->model_used ?? 'Not recorded' }}</dd>
                                    <dt>Created</dt>
                                    <dd>{{ $meeting->created_at->format('Y-m-d H:i') }}</dd>
                                </dl>
                                <form method="POST" action="{{ route('minutes.destroy', $meeting) }}"
                                      data-confirm="Delete these minutes and their transcript?" class="mt-2">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger">
                                        <i class="ti ti-trash me-1"></i>Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center text-secondary py-5">
                                <i class="ti ti-file-text fs-1 d-block mb-2"></i>
                                No minutes yet. <a href="{{ route('minutes.create') }}">Create your first</a> by pasting a
                                transcript or uploading a file.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $meetings->links() }}</div>
@endsection
