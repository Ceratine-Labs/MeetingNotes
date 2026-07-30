<?php

namespace Modules\Backup\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Modules\Backup\Jobs\RunBackupJob;
use Modules\Core\Services\SettingsService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    public function __construct(protected SettingsService $settings)
    {
    }

    /**
     * Existing backups and the schedule configuration.
     */
    public function index(): View
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

    /**
     * Take a backup now.
     */
    public function run(Request $request): RedirectResponse
    {
        RunBackupJob::dispatch($request->boolean('only_db'));

        return redirect()->route('backup.admin.index')
            ->with('status', 'Backup queued — refresh in a minute to see it in the list.');
    }

    /**
     * Stream a backup archive to the browser.
     *
     * StreamedResponse rather than a plain Response: backup archives are large,
     * and reading one into memory to return it would exhaust PHP's memory limit
     * on exactly the deployments where backups matter most.
     */
    public function download(Request $request): StreamedResponse
    {
        $path = $request->string('path')->toString();
        $disk = Storage::disk('backups');

        abort_unless($this->isBackupFile($path) && $disk->exists($path), 404);

        return $disk->download($path);
    }

    /**
     * Delete a backup file.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $path = $request->string('path')->toString();
        $disk = Storage::disk('backups');

        abort_unless($this->isBackupFile($path) && $disk->exists($path), 404);

        $disk->delete($path);

        return redirect()->route('backup.admin.index')->with('status', 'Backup deleted.');
    }

    /**
     * Save the backup schedule and retention settings.
     */
    public function updateSettings(Request $request): RedirectResponse
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
