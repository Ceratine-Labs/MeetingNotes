<?php

namespace Modules\Backup\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\Backup\Jobs\RunBackupJob;
use Modules\Core\Services\SettingsService;

class BackupController extends Controller
{
    public function __construct(protected SettingsService $settings)
    {
    }

    public function index()
    {
        $disk = Storage::disk('backups');
        $appName = config('backup.backup.name');

        $files = collect($disk->allFiles())
            ->filter(fn ($f) => str_ends_with($f, '.zip'))
            ->map(fn ($f) => [
                'path' => $f,
                'name' => basename($f),
                'size' => $disk->size($f),
                'date' => \Illuminate\Support\Carbon::createFromTimestamp($disk->lastModified($f)),
            ])
            ->sortByDesc('date')
            ->values();

        return view('backup::admin.index', [
            'files' => $files,
            'totalSize' => $files->sum('size'),
            'settings' => [
                'schedule_enabled' => $this->settings->get('backup.schedule_enabled', '0') === '1',
                'daily_time' => $this->settings->get('backup.daily_time', '02:00'),
                'notify_email' => $this->settings->get('backup.notify_email'),
            ],
        ]);
    }

    public function run(Request $request)
    {
        RunBackupJob::dispatch($request->boolean('only_db'));

        return redirect()->route('backup.admin.index')
            ->with('status', 'Backup queued — refresh in a minute to see it in the list.');
    }

    public function download(Request $request)
    {
        $path = $request->string('path')->toString();
        $disk = Storage::disk('backups');

        abort_unless($this->isBackupFile($path) && $disk->exists($path), 404);

        return $disk->download($path);
    }

    public function destroy(Request $request)
    {
        $path = $request->string('path')->toString();
        $disk = Storage::disk('backups');

        abort_unless($this->isBackupFile($path) && $disk->exists($path), 404);

        $disk->delete($path);

        return redirect()->route('backup.admin.index')->with('status', 'Backup deleted.');
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'schedule_enabled' => ['nullable', 'boolean'],
            'daily_time' => ['required', 'date_format:H:i'],
            'notify_email' => ['nullable', 'email'],
        ]);

        $this->settings->set('backup.schedule_enabled', $request->boolean('schedule_enabled') ? '1' : '0', 'backup');
        $this->settings->set('backup.daily_time', $validated['daily_time'], 'backup');
        $this->settings->set('backup.notify_email', $validated['notify_email'] ?? null, 'backup');

        return redirect()->route('backup.admin.index')->with('status', 'Backup settings saved.');
    }

    /**
     * Only zip files, no traversal — the path comes from user input.
     */
    protected function isBackupFile(string $path): bool
    {
        return str_ends_with($path, '.zip')
            && ! str_contains($path, '..')
            && ! str_starts_with($path, '/');
    }
}
