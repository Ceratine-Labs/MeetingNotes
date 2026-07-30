<?php

namespace Modules\Llm\Drivers;

use Modules\Llm\Contracts\LlmDriver;
use Modules\Llm\Exceptions\LlmException;
use Modules\Llm\Support\LlmResponse;

/**
 * Deterministic in-process driver for development, demos and the E2E
 * suite: instant responses, zero cost, zero network, and output that
 * always passes MinutesSchema::validate(). This is what lets every
 * resource and feature be exercised end to end before an LLM key is
 * ever configured.
 *
 * Selected like any provider (settings key `llm.provider` = 'fake'),
 * but deliberately absent from the admin provider dropdown and
 * hard-refused in production: a customer must never pay for, or
 * circulate, canned minutes.
 *
 * The payload shape is chosen from the requested schema rather than a
 * task type (drivers do not receive task types): the full minutes
 * schema is recognised by its `quality_notes` property and the
 * chunk-map schema by its `facts` property. Anything else gets a
 * minimal object built from the schema's required keys.
 */
class FakeDriver implements LlmDriver
{
    public function __construct()
    {
        if (app()->isProduction()) {
            throw new LlmException('The fake LLM driver is not available in production.');
        }
    }

    public function complete(string $model, string $system, string $user, array $options = []): LlmResponse
    {
        return $this->respond('ok', $user);
    }

    public function structured(string $model, string $system, string $user, array $schema, array $options = []): LlmResponse
    {
        $properties = $schema['properties'] ?? [];

        if (isset($properties['quality_notes'])) {
            return $this->respond($this->fullMinutes(), $user);
        }

        if (isset($properties['facts'])) {
            return $this->respond($this->chunkExtraction(), $user);
        }

        return $this->respond($this->minimalFor($schema), $user);
    }

    /**
     * Token counts are rough character-based estimates so the admin
     * generation-run log shows plausible non-zero numbers.
     */
    protected function respond(string|array $content, string $user): LlmResponse
    {
        $out = is_string($content) ? $content : json_encode($content);

        return new LlmResponse(
            content: $content,
            tokensIn: max(1, (int) (mb_strlen($user) / 4)),
            tokensOut: max(1, (int) (mb_strlen($out) / 4)),
            model: 'fake',
        );
    }

    /**
     * A complete, realistic nine-section document. Content is static on
     * purpose: deterministic output is what makes E2E assertions stable.
     */
    protected function fullMinutes(): array
    {
        return [
            'meeting_info' => [
                'title' => 'Product Launch Steering',
                'date' => '2026-07-28',
                'start_time' => '09:00',
                'end_time' => '09:50',
                'duration' => '50 minutes',
                'location' => 'Video call',
                'meeting_type' => 'regular',
                'objective' => 'Confirm launch readiness and close out open budget questions.',
                'chair' => 'Nadia Petersen',
            ],
            'attendance' => [
                'present' => [
                    ['name' => 'Nadia Petersen', 'title' => 'Operations Lead', 'organization' => 'NoteFiend'],
                    ['name' => 'Thabo Mahlangu', 'title' => 'Finance Manager', 'organization' => 'NoteFiend'],
                    ['name' => 'Sarah Naidoo', 'title' => 'Product Manager', 'organization' => 'NoteFiend'],
                ],
                'absent' => [
                    ['name' => 'Daniel Krige', 'reason' => 'Annual leave'],
                ],
                'guests' => [],
            ],
            'discussion' => [
                [
                    'heading' => 'Launch readiness',
                    'summary' => "The team reviewed the launch checklist and confirmed engineering sign-off. Two marketing deliverables remain outstanding.\n\nSarah flagged that the product date depends on confirmation from the platform team, expected this week.",
                    'key_points' => [
                        ['point' => 'Engineering checklist complete', 'raised_by' => 'Sarah Naidoo'],
                        ['point' => 'Marketing assets two days behind', 'raised_by' => 'Nadia Petersen'],
                    ],
                    'questions' => [
                        ['question' => 'Is the platform date confirmed?', 'answer' => 'Not yet; expected by Friday.'],
                    ],
                    'data_points' => ['Checklist 18 of 20 items complete'],
                    'unresolved' => ['Final platform confirmation'],
                ],
                [
                    'heading' => 'Q4 marketing budget',
                    'summary' => "Thabo presented the revised budget. The original figure was agreed to be too high given the launch date movement, and the team settled on a reduced number with the balance moved to Q1.",
                    'key_points' => [
                        ['point' => 'Budget reduced to R480 000', 'raised_by' => 'Thabo Mahlangu'],
                    ],
                    'questions' => [],
                    'data_points' => ['Original budget R520 000', 'Revised budget R480 000'],
                    'unresolved' => [],
                ],
            ],
            'decisions' => [
                [
                    'ref' => 'D1',
                    'decision' => 'Approved the revised Q4 marketing budget of R480 000, reduced from R520 000.',
                    'made_by' => 'Nadia Petersen',
                    'rationale' => 'Aligns spend with the moved launch date; balance shifts to Q1.',
                    'conditions' => '[Not specified]',
                    'impact' => 'Q1 budget increases by R40 000.',
                ],
                [
                    'ref' => 'D2',
                    'decision' => 'Launch communications will not go out before the platform date is confirmed.',
                    'made_by' => 'Nadia Petersen',
                    'rationale' => 'Avoid announcing a date that may move.',
                    'conditions' => 'Platform confirmation expected by Friday.',
                    'impact' => '[Not specified]',
                ],
            ],
            'action_items' => [
                [
                    'ref' => 'A1',
                    'description' => 'Circulate the revised budget spreadsheet to all leads.',
                    'owner' => 'Thabo Mahlangu',
                    'due_date' => '12 Aug',
                    'success_criteria' => 'Updated sheet in the shared drive and linked in the channel.',
                    'dependencies' => '[Not specified]',
                    'priority' => 'high',
                    'collaborators' => [],
                ],
                [
                    'ref' => 'A2',
                    'description' => 'Confirm the launch date with the platform team.',
                    'owner' => 'Sarah Naidoo',
                    'due_date' => '15 Aug',
                    'success_criteria' => 'Written confirmation shared with the steering group.',
                    'dependencies' => 'Platform team availability',
                    'priority' => 'high',
                    'collaborators' => ['Nadia Petersen'],
                ],
                [
                    'ref' => 'A3',
                    'description' => 'Brief the support team on launch-week expectations.',
                    'owner' => 'Nadia Petersen',
                    'due_date' => '[Not specified]',
                    'success_criteria' => '[Not specified]',
                    'dependencies' => '[Not specified]',
                    'priority' => 'medium',
                    'collaborators' => [],
                ],
            ],
            'parking_lot' => [
                ['item' => 'Annual pricing review', 'type' => 'tabled', 'reason' => 'Out of scope for launch planning.'],
            ],
            'supporting_materials' => [
                ['title' => 'Revised Q4 budget spreadsheet', 'type' => 'spreadsheet', 'reference' => 'Shared drive / Finance / Q4'],
            ],
            'general_discussion' => [
                ['topic' => 'Office move', 'note' => 'New lease starts 1 September; no action needed from this group.'],
            ],
            'next_steps' => [
                'next_meeting' => 'Same time next week',
                'checkpoints' => ['Platform confirmation by Friday'],
                'communication_plan' => 'Summary to leads after platform confirmation.',
                'monitor' => ['Marketing asset progress'],
            ],
            'quality_notes' => '',
        ];
    }

    /**
     * A plausible chunk-map extraction, for exercising the long-transcript
     * path without a provider.
     */
    protected function chunkExtraction(): array
    {
        return [
            'meeting_info_hints' => [
                'title' => 'Product Launch Steering',
                'date' => '2026-07-28',
                'chair' => 'Nadia Petersen',
            ],
            'attendees_mentioned' => [
                ['name' => 'Nadia Petersen', 'status' => 'present'],
                ['name' => 'Thabo Mahlangu', 'status' => 'present'],
            ],
            'facts' => [
                ['topic' => 'Budget', 'detail' => 'Q4 marketing reduced to R480 000.', 'said_by' => 'Thabo Mahlangu'],
                ['topic' => 'Launch', 'detail' => 'Platform date confirmation expected Friday.', 'said_by' => 'Sarah Naidoo'],
            ],
            'decisions' => [
                ['decision' => 'Approved the revised Q4 marketing budget of R480 000.', 'made_by' => 'Nadia Petersen'],
            ],
            'action_items' => [
                ['description' => 'Circulate the revised budget spreadsheet.', 'owner' => 'Thabo Mahlangu', 'due_date' => '12 Aug'],
            ],
            'materials' => ['Revised Q4 budget spreadsheet'],
            'data_points' => ['Original budget R520 000'],
        ];
    }

    /**
     * Fallback for schemas this driver does not recognise: an object with
     * every required key present, typed from the schema so validation of
     * shape passes even for future task types.
     */
    protected function minimalFor(array $schema): array
    {
        $result = [];

        foreach ((array) ($schema['required'] ?? []) as $key) {
            $type = $schema['properties'][$key]['type'] ?? 'string';

            $result[$key] = match ($type) {
                'array' => [],
                'object' => [],
                'number', 'integer' => 0,
                'boolean' => false,
                default => '[Not specified]',
            };
        }

        return $result;
    }
}
