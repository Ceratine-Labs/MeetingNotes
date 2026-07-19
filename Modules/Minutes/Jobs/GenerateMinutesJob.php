<?php

namespace Modules\Minutes\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Minutes\Models\Meeting;
use Modules\Minutes\Services\MinutesGenerator;

class GenerateMinutesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Retry once on transient failure, then surface the error. */
    public int $tries = 2;

    public int $backoff = 30;

    /** Generation of a chunked transcript can legitimately take a while. */
    public int $timeout = 1800;

    public function __construct(public string $meetingId)
    {
    }

    public function handle(MinutesGenerator $generator): void
    {
        $meeting = Meeting::query()->find($this->meetingId);

        if ($meeting === null || $meeting->transcript === null) {
            return;
        }

        $meeting->update([
            'status' => Meeting::STATUS_PROCESSING,
            'progress_stage' => 'starting',
            'error' => null,
        ]);

        $generator->generate($meeting);
    }

    public function failed(?\Throwable $exception): void
    {
        Meeting::query()->whereKey($this->meetingId)->update([
            'status' => Meeting::STATUS_FAILED,
            'progress_stage' => null,
            'error' => mb_substr($exception?->getMessage() ?? 'Unknown failure', 0, 2000),
        ]);
    }
}
