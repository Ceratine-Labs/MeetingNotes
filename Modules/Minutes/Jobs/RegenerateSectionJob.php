<?php

namespace Modules\Minutes\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Minutes\Models\Meeting;
use Modules\Minutes\Services\MinutesGenerator;
use Modules\Tenancy\Contracts\TenantAwareJob;
use Modules\Tenancy\Jobs\Middleware\BindOrganisation;

/**
 * Regenerates a single section of existing minutes and parks the result as a
 * proposal for the user to accept or discard.
 *
 * Tenant-aware for the same reason as GenerateMinutesJob: a long-lived worker with
 * a singleton OrganisationContext would otherwise inherit the previous job's
 * tenant. See that class for the full explanation.
 *
 * No quota check here, deliberately. Redoing one section of a document the customer
 * has already spent a credit on is part of finishing that document, not a new
 * generation — charging for it would push people towards accepting a weak section
 * rather than fixing it, which makes the product worse.
 */
class RegenerateSectionJob implements ShouldQueue, TenantAwareJob
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $backoff = 15;

    public int $timeout = 600;

    /**
     * @param  string  $meetingId  The meeting whose section is being redone.
     * @param  string  $section  Canonical section key (see MinutesSchema).
     * @param  string  $organisationId  Captured at dispatch, so the tenant is bound
     *         before the meeting is even loaded.
     */
    public function __construct(
        public string $meetingId,
        public string $section,
        public string $organisationId,
    ) {}

    public function organisationId(): string
    {
        return $this->organisationId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new BindOrganisation];
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

    /**
     * Clear the in-flight marker and surface the error where the UI polls for it.
     *
     * The existing minutes are left completely intact — a failed regeneration must
     * never damage the version the customer already had.
     *
     * withoutOrganisationScope because failed() runs after the middleware has
     * unbound the tenant; a scoped query here would throw and leave the section
     * stuck showing a spinner.
     */
    public function failed(?\Throwable $exception): void
    {
        Meeting::withoutOrganisationScope()->whereKey($this->meetingId)->update([
            'regen_section' => null,
            'section_proposal' => json_encode([
                'section' => $this->section,
                'error' => mb_substr($exception?->getMessage() ?? 'Regeneration failed', 0, 500),
            ]),
        ]);
    }
}
