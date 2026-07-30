<?php

namespace Modules\Minutes\Support;

use Modules\Minutes\Models\Meeting;

/**
 * Canned meetings for `php artisan demo:seed`. Content lives here, out of
 * the command, so the command reads as procedure and this file reads as
 * data. Every `sections` payload passes MinutesSchema::validate() and is
 * persisted through MinutesGenerator::persist(), so demo records exercise
 * exactly the code paths real generations do.
 */
class DemoMinutes
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function meetings(): array
    {
        return [
            [
                'title' => 'Q3 Budget Review',
                'date' => '2026-07-21',
                'status' => Meeting::STATUS_READY,
                'transcript' => self::budgetTranscript(),
                'sections' => self::budgetSections(),
                // A1 ticked so the register demos both states out of the box.
                'done_refs' => ['A1'],
            ],
            [
                'title' => 'Launch Planning',
                'date' => '2026-07-28',
                'status' => Meeting::STATUS_READY,
                'transcript' => self::launchTranscript(),
                'sections' => self::launchSections(),
            ],
            [
                'title' => 'Board Catch-up (in progress)',
                'date' => '2026-07-30',
                'status' => Meeting::STATUS_PROCESSING,
                'progress_stage' => 'extracting chunk 2/3',
                'transcript' => "Chair: Welcome all, let's get started with the quarterly review…",
            ],
            [
                'title' => 'Ops Standup (failed example)',
                'date' => '2026-07-29',
                'status' => Meeting::STATUS_FAILED,
                'error' => 'Provider timeout after 300s. Retry when the provider recovers.',
                'transcript' => 'Speaker 1: Quick one today. Two blockers on the pipeline…',
            ],
        ];
    }

    protected static function budgetTranscript(): string
    {
        return <<<'TXT'
[00:02] Nadia: Right, budgets. The Q4 marketing number is sitting at 520.
[00:41] Thabo: That was before the launch moved. Half of that spend lands too early now.
[01:15] Nadia: Agreed. Take it down to 480 and shift the rest into Q1.
[01:38] Sarah: I still think the launch date itself is soft. Product hasn't confirmed.
[02:04] Nadia: Fair. Sarah, chase that this week. Thabo, recirculate the sheet.
[02:19] Thabo: Will do, by Tuesday.
TXT;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function budgetSections(): array
    {
        return [
            'meeting_info' => [
                'title' => 'Q3 Budget Review',
                'date' => '2026-07-21',
                'start_time' => '10:00',
                'end_time' => '10:45',
                'duration' => '45 minutes',
                'location' => 'Boardroom / video call',
                'meeting_type' => 'regular',
                'objective' => 'Settle the Q4 marketing budget in light of the moved launch date.',
                'chair' => 'Nadia Petersen',
            ],
            'attendance' => [
                'present' => [
                    ['name' => 'Nadia Petersen', 'title' => 'Operations Lead', 'organization' => 'Demo Workspace'],
                    ['name' => 'Thabo Mahlangu', 'title' => 'Finance Manager', 'organization' => 'Demo Workspace'],
                    ['name' => 'Sarah Naidoo', 'title' => 'Product Manager', 'organization' => 'Demo Workspace'],
                ],
                'absent' => [],
                'guests' => [],
            ],
            'discussion' => [
                [
                    'heading' => 'Q4 marketing budget',
                    'summary' => "The meeting opened with the Q4 marketing budget, tabled at R520 000. Thabo noted that the launch date movement pushes a large share of that spend too early to be effective.\n\nAfter discussion the group agreed to reduce the figure to R480 000 and move the balance into Q1, aligning spend with the launch window.",
                    'key_points' => [
                        ['point' => 'Half the original spend would land before launch', 'raised_by' => 'Thabo Mahlangu'],
                        ['point' => 'Launch date is not yet confirmed by product', 'raised_by' => 'Sarah Naidoo'],
                    ],
                    'questions' => [
                        ['question' => 'Is the launch date fixed?', 'answer' => 'Not yet; Sarah to confirm with the product team this week.'],
                    ],
                    'data_points' => ['Original Q4 budget R520 000', 'Revised Q4 budget R480 000'],
                    'unresolved' => ['Launch date confirmation'],
                ],
            ],
            'decisions' => [
                [
                    'ref' => 'D1',
                    'decision' => 'Approved the revised Q4 marketing budget of R480 000, reduced from R520 000.',
                    'made_by' => 'Nadia Petersen',
                    'rationale' => 'Shifting spend to Q1 to align with the product launch.',
                    'conditions' => '[Not specified]',
                    'impact' => 'Q1 marketing budget increases by R40 000.',
                ],
            ],
            'action_items' => [
                [
                    'ref' => 'A1',
                    'description' => 'Circulate the revised budget spreadsheet',
                    'owner' => 'Thabo Mahlangu',
                    'due_date' => '12 Aug',
                    'success_criteria' => 'Updated sheet in the shared drive',
                    'dependencies' => '[Not specified]',
                    'priority' => 'high',
                    'collaborators' => [],
                ],
                [
                    'ref' => 'A2',
                    'description' => 'Confirm the launch date with the product team',
                    'owner' => 'Sarah Naidoo',
                    'due_date' => '15 Aug',
                    'success_criteria' => 'Written confirmation shared with the group',
                    'dependencies' => '[Not specified]',
                    'priority' => 'medium',
                    'collaborators' => [],
                ],
            ],
            'parking_lot' => [
                ['item' => 'Agency retainer renewal', 'type' => 'tabled', 'reason' => 'Contract only expires in November.'],
            ],
            'supporting_materials' => [
                ['title' => 'Q4 budget spreadsheet', 'type' => 'spreadsheet', 'reference' => 'Shared drive / Finance'],
            ],
            'general_discussion' => [
                ['topic' => 'Reporting cadence', 'note' => 'Monthly budget reviews continue unchanged.'],
            ],
            'next_steps' => [
                'next_meeting' => 'Q4 Budget Review, late October',
                'checkpoints' => ['Launch date confirmed by 15 Aug'],
                'communication_plan' => 'Revised sheet circulated to all leads.',
                'monitor' => ['Q1 spend commitments'],
            ],
            'quality_notes' => '',
        ];
    }

    protected static function launchTranscript(): string
    {
        return <<<'TXT'
[00:05] Sarah: Launch planning. The venue is the big open item.
[00:32] Nadia: And the press release. Neal, can you draft it this week?
[00:58] Neal: Can do, once the date is locked.
[01:20] Sarah: I'll book the venue for the last week of August provisionally.
TXT;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function launchSections(): array
    {
        return [
            'meeting_info' => [
                'title' => 'Launch Planning',
                'date' => '2026-07-28',
                'start_time' => '14:00',
                'end_time' => '14:30',
                'duration' => '30 minutes',
                'location' => 'Video call',
                'meeting_type' => 'ad-hoc',
                'objective' => 'Assign the remaining launch-event workstreams.',
                'chair' => 'Sarah Naidoo',
            ],
            'attendance' => [
                'present' => [
                    ['name' => 'Sarah Naidoo', 'title' => 'Product Manager', 'organization' => 'Demo Workspace'],
                    ['name' => 'Nadia Petersen', 'title' => 'Operations Lead', 'organization' => 'Demo Workspace'],
                    ['name' => 'Neal Cruickshank', 'title' => 'Marketing', 'organization' => 'Demo Workspace'],
                ],
                'absent' => [],
                'guests' => [],
            ],
            'discussion' => [
                [
                    'heading' => 'Launch event logistics',
                    'summary' => "The group walked the launch checklist. The venue and the press release are the two open workstreams; both are assigned below.\n\nThe press release drafting waits on the confirmed date, so its due date is deliberately unspecified.",
                    'key_points' => [
                        ['point' => 'Venue to be booked provisionally for late August', 'raised_by' => 'Sarah Naidoo'],
                    ],
                    'questions' => [],
                    'data_points' => [],
                    'unresolved' => [],
                ],
            ],
            'decisions' => [
                [
                    'ref' => 'D1',
                    'decision' => 'The launch event targets the last week of August, pending date confirmation.',
                    'made_by' => 'Sarah Naidoo',
                    'rationale' => 'Aligns with the platform confirmation window.',
                    'conditions' => 'Provisional until the product date is locked.',
                    'impact' => '[Not specified]',
                ],
            ],
            'action_items' => [
                [
                    'ref' => 'A1',
                    'description' => 'Book the venue for the launch event',
                    'owner' => 'Sarah Naidoo',
                    'due_date' => 'end of August',
                    'success_criteria' => 'Provisional booking confirmed in writing',
                    'dependencies' => '[Not specified]',
                    'priority' => 'high',
                    'collaborators' => [],
                ],
                [
                    'ref' => 'A2',
                    'description' => 'Draft the press release',
                    'owner' => 'Neal Cruickshank',
                    'due_date' => '[Not specified]',
                    'success_criteria' => '[Not specified]',
                    'dependencies' => 'Confirmed launch date',
                    'priority' => 'low',
                    'collaborators' => [],
                ],
                [
                    'ref' => 'A3',
                    'description' => 'Update the partner contact list',
                    'owner' => 'Thabo Mahlangu',
                    'due_date' => '20 Aug',
                    'success_criteria' => '[Not specified]',
                    'dependencies' => '[Not specified]',
                    'priority' => 'medium',
                    'collaborators' => [],
                ],
            ],
            'parking_lot' => [],
            'supporting_materials' => [],
            'general_discussion' => [],
            'next_steps' => [
                'next_meeting' => 'Weekly until launch',
                'checkpoints' => [],
                'communication_plan' => '[Not specified]',
                'monitor' => ['Venue booking'],
            ],
            'quality_notes' => 'Short transcript; several fields could not be determined from the source.',
        ];
    }
}
