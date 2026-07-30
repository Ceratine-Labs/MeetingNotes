@extends('core::layouts.app')

@section('title', 'Minutes Library — ' . config('app.name'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Minutes Library</h1>
        <a href="{{ route('minutes.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i> New minutes
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
        <table class="table mb-0 align-middle">
            <thead>
                <tr>
                    <th>Title</th><th>Meeting date</th><th>Status</th>
                    <th class="text-end">Decisions</th><th class="text-end">Actions</th>
                    <th>Model</th><th>Created</th><th></th>
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
                        <td class="text-secondary">{{ $meeting->meeting_date?->format('Y-m-d') ?? '—' }}</td>
                        <td>
                            @switch($meeting->status)
                                @case('ready') <span class="badge text-bg-success">ready</span> @break
                                @case('processing') <span class="badge text-bg-info">processing</span> @break
                                @case('failed') <span class="badge text-bg-danger">failed</span> @break
                                @default <span class="badge text-bg-secondary">{{ $meeting->status }}</span>
                            @endswitch
                        </td>
                        <td class="text-end">{{ $meeting->decisions_count }}</td>
                        <td class="text-end">{{ $meeting->action_items_count }}</td>
                        <td class="small text-secondary">{{ $meeting->model_used ?? '—' }}</td>
                        <td class="small text-secondary">{{ $meeting->created_at->format('Y-m-d H:i') }}</td>
                        <td class="text-end">
                            <form method="POST" action="{{ route('minutes.destroy', $meeting) }}"
                                  data-confirm="Delete these minutes and their transcript?" class="d-inline">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-secondary py-5">
                            <i class="bi bi-journal-text fs-1 d-block mb-2"></i>
                            No minutes yet — <a href="{{ route('minutes.create') }}">create your first</a> by pasting a
                            transcript or uploading a file.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $meetings->links() }}</div>
@endsection
