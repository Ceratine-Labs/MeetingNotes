<?php

namespace Modules\Minutes\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Minutes\Models\Meeting;
use Modules\Minutes\Services\MinutesGenerator;

class RegenerateSectionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $backoff = 15;

    public int $timeout = 600;

    public function __construct(public string $meetingId, public string $section)
    {
    }

    public function handle(MinutesGenerator $generator): void
    {
        $meeting = Meeting::query()->find($this->meetingId);

        if ($meeting === null || ! $meeting->isReady()) {
            return;
        }

        $value = $generator->regenerateSection($meeting, $this->section);

        $meeting->update([
            'regen_section' => null,
            'section_proposal' => ['section' => $this->section, 'value' => $value],
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        // Leave the minutes intact — just clear the in-flight marker and
        // surface the message where the workspace polls it.
        Meeting::query()->whereKey($this->meetingId)->update([
            'regen_section' => null,
            'section_proposal' => json_encode([
                'section' => $this->section,
                'error' => mb_substr($exception?->getMessage() ?? 'Regeneration failed', 0, 500),
            ]),
        ]);
    }
}
