<?php

namespace Modules\Minutes\Support;

/**
 * THE canonical minutes structure — Ryan's consistency override on the
 * third-party spec. Every minutes record, whatever the source or the
 * prompt version, is this struct: the LLM fills it (enforced via
 * structured output), the app validates it, typed child rows are
 * derived from it, and the HTML render is produced from it by a Blade
 * template the LLM never touches.
 *
 * Section order and keys mirror docs/PRODUCT_SPEC.md §1–9.
 */
class MinutesSchema
{
    public const SECTIONS = [
        'meeting_info',
        'attendance',
        'discussion',
        'decisions',
        'action_items',
        'parking_lot',
        'supporting_materials',
        'general_discussion',
        'next_steps',
    ];

    /**
     * Full JSON Schema for single-pass generation and chunk-reduce.
     */
    public static function full(): array
    {
        $str = ['type' => 'string'];
        $strArr = ['type' => 'array', 'items' => $str];

        return [
            'type' => 'object',
            'required' => [...self::SECTIONS, 'quality_notes'],
            'properties' => [
                'meeting_info' => [
                    'type' => 'object',
                    'required' => ['title', 'date', 'meeting_type', 'objective'],
                    'properties' => [
                        'title' => $str,
                        'date' => $str,
                        'start_time' => $str,
                        'end_time' => $str,
                        'duration' => $str,
                        'location' => $str,
                        'meeting_type' => ['type' => 'string', 'enum' => ['regular', 'ad-hoc', 'strategic', 'emergency', '[Not specified]']],
                        'objective' => $str,
                        'chair' => $str,
                    ],
                ],
                'attendance' => [
                    'type' => 'object',
                    'required' => ['present'],
                    'properties' => [
                        'present' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'required' => ['name'],
                            'properties' => ['name' => $str, 'title' => $str, 'organization' => $str],
                        ]],
                        'absent' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'required' => ['name'],
                            'properties' => ['name' => $str, 'reason' => $str],
                        ]],
                        'guests' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'required' => ['name'],
                            'properties' => ['name' => $str, 'affiliation' => $str],
                        ]],
                    ],
                ],
                'discussion' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'required' => ['heading', 'summary'],
                    'properties' => [
                        'heading' => $str,
                        'summary' => ['type' => 'string', 'description' => '2-4 paragraph narrative, paragraphs separated by blank lines'],
                        'key_points' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'required' => ['point'],
                            'properties' => ['point' => $str, 'raised_by' => $str],
                        ]],
                        'questions' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'required' => ['question'],
                            'properties' => ['question' => $str, 'answer' => $str],
                        ]],
                        'data_points' => $strArr,
                        'unresolved' => $strArr,
                    ],
                ]],
                'decisions' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'required' => ['ref', 'decision'],
                    'properties' => [
                        'ref' => ['type' => 'string', 'description' => 'D1, D2, …'],
                        'decision' => $str,
                        'made_by' => $str,
                        'rationale' => $str,
                        'conditions' => $str,
                        'impact' => $str,
                    ],
                ]],
                'action_items' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'required' => ['ref', 'description', 'owner', 'priority'],
                    'properties' => [
                        'ref' => ['type' => 'string', 'description' => 'A1, A2, …'],
                        'description' => $str,
                        'owner' => $str,
                        'due_date' => $str,
                        'success_criteria' => $str,
                        'dependencies' => $str,
                        'priority' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                        'collaborators' => $strArr,
                    ],
                ]],
                'parking_lot' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'required' => ['item', 'type'],
                    'properties' => [
                        'item' => $str,
                        'type' => ['type' => 'string', 'enum' => ['tabled', 'research', 'off_topic']],
                        'reason' => $str,
                    ],
                ]],
                'supporting_materials' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'required' => ['title'],
                    'properties' => ['title' => $str, 'type' => $str, 'reference' => $str],
                ]],
                'general_discussion' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'required' => ['topic', 'note'],
                    'properties' => ['topic' => $str, 'note' => $str],
                ]],
                'next_steps' => [
                    'type' => 'object',
                    'properties' => [
                        'next_meeting' => $str,
                        'checkpoints' => $strArr,
                        'communication_plan' => $str,
                        'monitor' => $strArr,
                    ],
                ],
                'quality_notes' => ['type' => 'string', 'description' => 'Source quality issues, ambiguities, discrepancies. Empty string if none.'],
            ],
        ];
    }

    /**
     * Lighter per-chunk extraction schema for map-reduce.
     */
    public static function chunkMap(): array
    {
        $str = ['type' => 'string'];

        return [
            'type' => 'object',
            'required' => ['facts'],
            'properties' => [
                'meeting_info_hints' => ['type' => 'object', 'properties' => [
                    'title' => $str, 'date' => $str, 'start_time' => $str, 'end_time' => $str,
                    'location' => $str, 'meeting_type' => $str, 'chair' => $str,
                ]],
                'attendees_mentioned' => ['type' => 'array', 'items' => ['type' => 'object',
                    'required' => ['name'],
                    'properties' => ['name' => $str, 'title' => $str, 'organization' => $str, 'status' => $str],
                ]],
                'facts' => ['type' => 'array', 'items' => ['type' => 'object',
                    'required' => ['topic', 'detail'],
                    'properties' => ['topic' => $str, 'detail' => $str, 'said_by' => $str],
                ]],
                'decisions' => ['type' => 'array', 'items' => ['type' => 'object',
                    'required' => ['decision'],
                    'properties' => ['decision' => $str, 'made_by' => $str, 'rationale' => $str],
                ]],
                'action_items' => ['type' => 'array', 'items' => ['type' => 'object',
                    'required' => ['description'],
                    'properties' => ['description' => $str, 'owner' => $str, 'due_date' => $str, 'priority_signal' => $str],
                ]],
                'materials' => ['type' => 'array', 'items' => $str],
                'data_points' => ['type' => 'array', 'items' => $str],
            ],
        ];
    }

    /**
     * Minimal structural validation of a full-schema response. Returns
     * a list of problems (empty = valid). Deliberately lenient beyond
     * structure — content quality is the prompt's job; this catches
     * shape breakage that would crash rendering or child-row building.
     */
    public static function validate(array $data): array
    {
        $problems = [];

        foreach ([...self::SECTIONS, 'quality_notes'] as $key) {
            if (! array_key_exists($key, $data)) {
                $problems[] = "missing section '{$key}'";
            }
        }

        foreach (['discussion', 'decisions', 'action_items', 'parking_lot', 'supporting_materials', 'general_discussion'] as $key) {
            if (isset($data[$key]) && ! is_array($data[$key])) {
                $problems[] = "'{$key}' must be an array";
            }
        }

        foreach (['meeting_info', 'attendance', 'next_steps'] as $key) {
            if (isset($data[$key]) && ! is_array($data[$key])) {
                $problems[] = "'{$key}' must be an object";
            }
        }

        if (isset($data['meeting_info']) && is_array($data['meeting_info'])) {
            foreach (['title', 'date', 'objective'] as $key) {
                if (! isset($data['meeting_info'][$key]) || ! is_string($data['meeting_info'][$key])) {
                    $problems[] = "meeting_info.{$key} must be a string";
                }
            }
        }

        if (is_array($data['decisions'] ?? null)) {
            foreach ($data['decisions'] as $i => $decision) {
                if (! is_array($decision) || ! isset($decision['ref'], $decision['decision'])) {
                    $problems[] = "decisions[{$i}] missing ref or decision";
                }
            }
        }

        if (is_array($data['action_items'] ?? null)) {
            foreach ($data['action_items'] as $i => $item) {
                if (! is_array($item) || ! isset($item['ref'], $item['description'], $item['owner'])) {
                    $problems[] = "action_items[{$i}] missing ref, description or owner";
                }
            }
        }

        return $problems;
    }
}
