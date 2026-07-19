<?php

namespace Modules\Minutes\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Minutes\Jobs\RegenerateSectionJob;
use Modules\Minutes\Models\Meeting;
use Modules\Minutes\Services\MinutesGenerator;
use Modules\Minutes\Support\MinutesSchema;

/**
 * Per-section operations on ready minutes: manual edit (JSON override),
 * regenerate → proposal, accept/discard proposal. Every mutation goes
 * through MinutesGenerator::persist() so child rows and rendered_html
 * stay derived from the sections struct — never edited directly.
 */
class SectionController extends Controller
{
    public function update(Request $request, Meeting $meeting, string $section, MinutesGenerator $generator)
    {
        $this->ensureReady($meeting);
        $this->ensureKnownSection($section);

        $validated = $request->validate(['value' => ['required', 'json']]);
        $value = json_decode($validated['value'], true);

        $sections = $meeting->sections;
        $sections[$section] = $value;

        $problems = MinutesSchema::validate($sections);

        if ($problems !== []) {
            return response()->json(['ok' => false, 'problems' => $problems], 422);
        }

        $generator->persist($meeting, $sections);

        return response()->json(['ok' => true]);
    }

    public function regenerate(Meeting $meeting, string $section)
    {
        $this->ensureReady($meeting);
        $this->ensureKnownSection($section);

        abort_if($meeting->regen_section !== null, 422, 'A section regeneration is already running.');

        $meeting->update(['regen_section' => $section, 'section_proposal' => null]);
        RegenerateSectionJob::dispatch($meeting->id, $section);

        return response()->json(['ok' => true]);
    }

    public function accept(Meeting $meeting, MinutesGenerator $generator)
    {
        $this->ensureReady($meeting);

        $proposal = $meeting->section_proposal;
        abort_if($proposal === null || ! isset($proposal['value']), 422, 'No proposal to accept.');

        $sections = $meeting->sections;
        $sections[$proposal['section']] = $proposal['value'];

        $problems = MinutesSchema::validate($sections);

        if ($problems !== []) {
            return response()->json(['ok' => false, 'problems' => $problems], 422);
        }

        $generator->persist($meeting, $sections);
        $meeting->update(['section_proposal' => null]);

        return response()->json(['ok' => true]);
    }

    public function discard(Meeting $meeting)
    {
        $meeting->update(['section_proposal' => null, 'regen_section' => null]);

        return response()->json(['ok' => true]);
    }

    /**
     * Renders a proposal for the diff view: current vs proposed HTML
     * for just the affected section.
     */
    public function proposal(Meeting $meeting, MinutesGenerator $generator)
    {
        $proposal = $meeting->section_proposal;
        abort_if($proposal === null, 404);

        if (isset($proposal['error'])) {
            return response()->json(['error' => $proposal['error'], 'section' => $proposal['section']]);
        }

        $proposed = $meeting->sections;
        $proposed[$proposal['section']] = $proposal['value'];

        return response()->json([
            'section' => $proposal['section'],
            'current_html' => app(\Modules\Minutes\Services\MinutesRenderer::class)->render($meeting->sections),
            'proposed_html' => app(\Modules\Minutes\Services\MinutesRenderer::class)->render($proposed),
        ]);
    }

    protected function ensureReady(Meeting $meeting): void
    {
        abort_unless($meeting->isReady(), 422, 'Minutes are not ready.');
    }

    protected function ensureKnownSection(string $section): void
    {
        abort_unless(in_array($section, MinutesSchema::SECTIONS, true), 404, 'Unknown section.');
    }
}
