@extends('admin::layouts.app')

@section('title', 'Backups — ' . config('app.name'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Backups</h1>
        <form method="POST" action="{{ route('backup.admin.run') }}" class="d-flex gap-2">
            @csrf
            <button name="only_db" value="1" class="btn btn-outline-primary">
                <i class="bi bi-database me-1"></i> DB only
            </button>
            <button class="btn btn-primary">
                <i class="bi bi-hdd-stack me-1"></i> Run full backup now
            </button>
        </form>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between">
                    <span>Backup archive</span>
                    <span class="text-secondary small">{{ $files->count() }} file(s), {{ number_format($totalSize / 1048576, 1) }} MB total</span>
                </div>
                <table class="table table-sm mb-0 align-middle">
                    <thead><tr><th>File</th><th>Date</th><th class="text-end">Size</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($files as $file)
                            <tr>
                                <td class="small font-monospace">{{ $file['name'] }}</td>
                                <td class="small text-secondary">{{ $file['date']->format('Y-m-d H:i') }}
                                    <span class="text-secondary">({{ $file['date']->diffForHumans() }})</span></td>
                                <td class="text-end small">{{ number_format($file['size'] / 1048576, 1) }} MB</td>
                                <td class="text-end" style="width: 110px;">
                                    <a href="{{ route('backup.admin.download', ['path' => $file['path']]) }}"
                                       class="btn btn-sm btn-outline-secondary" title="Download"><i class="bi bi-download"></i></a>
                                    <form method="POST" action="{{ route('backup.admin.destroy') }}" class="d-inline"
                                          data-confirm="Delete backup {{ $file['name'] }}? This cannot be undone.">
                                        @csrf @method('DELETE')
                                        <input type="hidden" name="path" value="{{ $file['path'] }}">
                                        <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-secondary py-4">
                                No backups yet — run one now, or enable the daily schedule.
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="text-secondary small mt-2 mb-0">
                Restoring is a deliberate manual procedure — see <code>docs/RESTORE.md</code> in the repo.
                There is intentionally no one-click restore.
            </p>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">Schedule &amp; notifications</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('backup.admin.settings') }}">
                        @csrf @method('PUT')
                        <div class="form-check form-switch mb-3">
                            <input type="checkbox" name="schedule_enabled" value="1" id="schedule_enabled"
                                   class="form-check-input" @checked($settings['schedule_enabled'])>
                            <label for="schedule_enabled" class="form-check-label">Daily backup</label>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="daily_time">Run at</label>
                            <input type="time" name="daily_time" id="daily_time"
                                   class="form-control @error('daily_time') is-invalid @enderror"
                                   value="{{ old('daily_time', $settings['daily_time']) }}">
                            @error('daily_time')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Cleanup runs at this time; the backup 15 minutes later.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="notify_email">Failure notifications to</label>
                            <input type="email" name="notify_email" id="notify_email"
                                   class="form-control @error('notify_email') is-invalid @enderror"
                                   value="{{ old('notify_email', $settings['notify_email']) }}"
                                   placeholder="ops@example.com">
                            @error('notify_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <button class="btn btn-primary w-100">Save settings</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
