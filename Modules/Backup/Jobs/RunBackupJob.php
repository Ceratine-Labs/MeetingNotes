<?php

namespace Modules\Backup\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

/**
 * Queued wrapper around backup:run so the admin button returns
 * immediately. Spatie's own notifications handle failure mail.
 */
class RunBackupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public bool $onlyDb = false)
    {
    }

    public function handle(): void
    {
        Artisan::call('backup:run', array_filter([
            '--only-db' => $this->onlyDb,
        ]));
    }
}
