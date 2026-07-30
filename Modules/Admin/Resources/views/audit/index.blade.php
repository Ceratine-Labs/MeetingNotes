@extends('admin::layouts.app')

@section('title', 'Audit log — back office')
@section('page_pretitle', 'System')
@section('page_title', 'Audit log')

@section('content')
    <div class="alert alert-info" role="alert">
        <div class="d-flex">
            <i class="ti ti-info-circle me-2 mt-1"></i>
            <div>
                Append-only. Every consequential back-office action is recorded here and
                nothing in the application can edit or delete an entry — that is what makes
                it worth having.
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <form method="GET" action="{{ route('admin.audit.index') }}" class="d-flex gap-2">
                <select name="action" class="form-select w-auto">
                    <option value="">All actions</option>
                    @foreach ($actions as $value => $label)
                        <option value="{{ $value }}" @selected($action === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-primary">Filter</button>
                @if ($action)
                    <a href="{{ route('admin.audit.index') }}" class="btn">Clear</a>
                @endif
            </form>
        </div>

        <div class="table-responsive">
            <table class="table card-table table-vcenter">
                <thead>
                    <tr><th>When</th><th>Action</th><th>By</th><th>Target</th><th>Detail</th><th>IP</th></tr>
                </thead>
                <tbody>
                    @forelse ($entries as $entry)
                        <tr>
                            <td class="text-nowrap text-secondary">
                                {{ $entry->created_at->toFormattedDayDateString() }}
                                <div class="small">{{ $entry->created_at->format('H:i:s') }}</div>
                            </td>
                            <td>
                                @php
                                    // Failed sign-ins are the one action that should catch
                                    // the eye when scanning — a run of them is a signal.
                                    $isFailure = $entry->action === \Modules\Admin\Services\AuditLogger::LOGIN_FAILED;
                                @endphp
                                <span class="badge {{ $isFailure ? 'bg-red-lt' : 'bg-secondary-lt' }}">
                                    {{ $entry->label() }}
                                </span>
                            </td>
                            <td class="text-secondary">{{ $entry->admin_email ?? '—' }}</td>
                            <td class="text-secondary small">
                                @if ($entry->target_type)
                                    {{ class_basename($entry->target_type) }}
                                    <div><code class="small">{{ Str::limit($entry->target_id, 12, '…') }}</code></div>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="text-secondary small">
                                @if (data_get($entry->context, 'reason'))
                                    {{ data_get($entry->context, 'reason') }}
                                @elseif ($entry->context)
                                    {{ Str::limit(json_encode($entry->context), 60) }}
                                @endif
                            </td>
                            <td class="text-secondary small">{{ $entry->ip_address }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-secondary">Nothing recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($entries->hasPages())
            <div class="card-footer">{{ $entries->links() }}</div>
        @endif
    </div>
@endsection
