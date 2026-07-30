<?php

namespace Modules\Minutes\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Billing\Exceptions\QuotaExceededException;
use Modules\Minutes\Models\Meeting;
use Modules\Minutes\Services\MinutesGenerator;
use Modules\Tenancy\Contracts\TenantAwareJob;
use Modules\Tenancy\Jobs\Middleware\BindOrganisation;

/**
 * Generates minutes for one meeting, off the request cycle.
 *
 * Tenant-aware, and that is not optional. A queue worker is a long-lived process
 * handling jobs for many customers in sequence, and OrganisationContext is a
 * singleton — so without the BindOrganisation middleware this job would inherit
 * whatever organisation the *previous* job left bound. That is a cross-tenant data
 * leak with no HTTP request anywhere near it, and one that every test running a
 * single job in isolation would pass.
 *
 * The organisation id is captured at dispatch time from the meeting row, so it
 * cannot be influenced by anything a user submits later.
 */
class GenerateMinutesJob implements ShouldQueue, TenantAwareJob
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

    /**
     * @param  string  $meetingId  The meeting to generate for.
     * @param  string  $organisationId  Captured at dispatch. Passed explicitly
     *         rather than read from the meeting inside handle(), because the
     *         organisation must be bound BEFORE any scoped query runs — including
     *         the query that loads the meeting.
     */
    public function __construct(
        public string $meetingId,
        public string $organisationId,
    ) {}

    public function organisationId(): string
    {
        return $this->organisationId;
    }

    /**
     * Bind the tenant for the duration of this job, and unbind afterwards.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new BindOrganisation];
    }

    public function handle(MinutesGenerator $generator): void
    {
        // Scoped by the organisation the middleware bound, so a mismatched
        // meeting/organisation pair finds nothing rather than generating across
        // tenants.
        $meeting = Meeting::query()->find($this->meetingId);

        if ($meeting === null || $meeting->transcript === null) {
            return;
        }

        $meeting->update([
            'status' => Meeting::STATUS_PROCESSING,
            'progress_stage' => 'starting',
            'error' => null,
        ]);

        try {
            $generator->generate($meeting);
        } catch (QuotaExceededException $e) {
            // Expected: the free tier working as designed, not a fault. Marked
            // failed with a message the customer can act on, and deliberately NOT
            // re-thrown — a retry would fail identically, and this must not page
            // anyone.
            $meeting->update([
                'status' => Meeting::STATUS_FAILED,
                'progress_stage' => null,
                'error' => sprintf(
                    'This workspace has used all %s generations included in the %s plan for '
                    .'the current period. Upgrade the plan, or wait until the allowance '
                    .'resets on %s.',
                    $e->status->limit,
                    $e->status->planName,
                    $e->status->periodEnd->toFormattedDayDateString()
                ),
            ]);
        }
    }

    /**
     * Record the failure on the meeting so the UI can explain itself.
     *
     * Uses withoutOrganisationScope: failed() runs after the job's middleware has
     * already unbound the tenant, so a scoped query here would throw and the
     * meeting would be left stuck in "processing" forever with no error shown.
     */
    public function failed(?\Throwable $exception): void
    {
        Meeting::withoutOrganisationScope()->whereKey($this->meetingId)->update([
            'status' => Meeting::STATUS_FAILED,
            'progress_stage' => null,
            'error' => mb_substr($exception?->getMessage() ?? 'Unknown failure', 0, 2000),
        ]);
    }
}
