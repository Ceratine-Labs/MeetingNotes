<?php

namespace Modules\Minutes\Services;

use Modules\Billing\Services\QuotaService;
use Modules\Llm\Models\PromptTemplate;
use Modules\Llm\Services\LlmManager;
use Modules\Minutes\Models\ActionItem;
use Modules\Minutes\Models\Meeting;
use Modules\Minutes\Support\MinutesSchema;
use Modules\Search\Services\SearchIndexer;
use Modules\Tenancy\Models\Organisation;

/**
 * The generation pipeline: transcript → (single pass | map-reduce) →
 * validated canonical sections JSON → typed child rows → HTML render.
 *
 * Called from GenerateMinutesJob; every LLM call is logged by
 * LlmManager into generation_runs.
 *
 * Quota enforcement lives here, in the service, and not in the controller. Both
 * the initial generation and the retry path run through a queued job, so a
 * controller-only check would leave the expensive work unmetered — and this method
 * is the last point before real money is spent on an LLM call. The credit is
 * recorded only after the minutes are persisted, so a failed generation is never
 * charged.
 */
class MinutesGenerator
{
    /**
     * Transcripts above this many estimated tokens go through
     * map-reduce chunking (~4 chars/token heuristic).
     */
    public const SINGLE_PASS_TOKEN_BUDGET = 30000;

    public const CHUNK_CHARS = 80000;

    public const CHUNK_OVERLAP_CHARS = 2000;

    public function __construct(
        protected LlmManager $llm,
        protected MinutesRenderer $renderer,
        protected QuotaService $quota,
        protected SearchIndexer $searchIndexer,
    ) {
    }

    /**
     * Generate minutes for a meeting, metering the organisation's allowance.
     *
     * @throws \Modules\Billing\Exceptions\QuotaExceededException Before any LLM
     *         call is made, when the organisation has no allowance left. The
     *         exception carries the QuotaStatus so the caller can tell the customer
     *         what their limit is and offer an upgrade.
     */
    public function generate(Meeting $meeting): void
    {
        $organisation = $meeting->organisation;

        // Checked BEFORE the first LLM call, so an over-quota organisation costs us
        // nothing. Throws rather than returning false: silently producing no minutes
        // would leave the meeting stuck in "processing" with no explanation.
        if ($organisation !== null) {
            $this->quota->assertCanGenerate($organisation);
        }

        $transcript = $meeting->transcript;
        $text = $transcript->raw_text;

        $userContext = $this->userContext($meeting);

        if ($transcript->token_estimate <= self::SINGLE_PASS_TOKEN_BUDGET) {
            $sections = $this->singlePass($meeting, $text, $userContext);
        } else {
            $sections = $this->mapReduce($meeting, $text, $userContext);
        }

        $this->persist($meeting, $sections);

        // Recorded only now — after the minutes exist. Anything that threw above
        // never reaches this line, which is what guarantees a customer is not
        // charged a credit for our failure or a provider timeout.
        if ($organisation !== null) {
            $this->recordConsumption($meeting, $organisation);
        }
    }

    /**
     * Write the metering ledger entry for a completed generation.
     *
     * A failure to record must not fail the request: the customer's minutes are
     * already saved, and throwing here would show them an error for work that
     * succeeded. Reported instead, so the gap is visible without being destructive.
     */
    protected function recordConsumption(Meeting $meeting, Organisation $organisation): void
    {
        try {
            $this->quota->recordUsage(
                organisation: $organisation,
                meetingId: $meeting->getKey(),
                userId: $meeting->user_id,
                modelUsed: $meeting->model_used,
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function singlePass(Meeting $meeting, string $text, string $userContext): array
    {
        $meeting->update(['progress_stage' => 'generating']);

        $template = $this->activeTemplate('minutes.generate');

        return $this->structuredWithRepair(
            'generate_full',
            $template,
            $userContext . "MEETING CONTENT:\n\n" . $text,
            MinutesSchema::full(),
            $meeting,
        );
    }

    protected function mapReduce(Meeting $meeting, string $text, string $userContext): array
    {
        $chunks = $this->chunk($text);
        $mapTemplate = $this->activeTemplate('minutes.chunk_map');
        $extractions = [];

        foreach ($chunks as $i => $chunk) {
            $meeting->update(['progress_stage' => 'extracting chunk ' . ($i + 1) . '/' . count($chunks)]);

            $extractions[] = $this->structuredWithRepair(
                'chunk_map',
                $mapTemplate,
                sprintf("CHUNK %d of %d:\n\n%s", $i + 1, count($chunks), $chunk),
                MinutesSchema::chunkMap(),
                $meeting,
            );
        }

        $meeting->update(['progress_stage' => 'merging']);

        $reduceTemplate = $this->activeTemplate('minutes.chunk_reduce');

        return $this->structuredWithRepair(
            'chunk_reduce',
            $reduceTemplate,
            $userContext
                . "PER-CHUNK EXTRACTIONS (JSON, in transcript order):\n\n"
                . json_encode($extractions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            MinutesSchema::full(),
            $meeting,
        );
    }

    /**
     * One structured call with a single repair retry when the response
     * fails structural validation.
     */
    protected function structuredWithRepair(string $taskType, PromptTemplate $template, string $user, array $schema, Meeting $meeting): array
    {
        $response = $this->llm->structured($taskType, $template->body, $user, $schema, $meeting->id, $template->id);
        $data = $response->content;
        $meeting->update(['model_used' => $response->model, 'prompt_version' => $template->version]);

        $problems = $taskType === 'chunk_map' ? [] : MinutesSchema::validate((array) $data);

        if ($problems === []) {
            return (array) $data;
        }

        $repair = $this->llm->structured(
            $taskType,
            $template->body,
            $user . "\n\nYour previous response had these structural problems — fix them and emit the complete corrected result:\n- "
                . implode("\n- ", $problems),
            $schema,
            $meeting->id,
            $template->id,
        );

        $problems = MinutesSchema::validate((array) $repair->content);

        if ($problems !== []) {
            throw new GenerationException('Model output failed validation after repair retry: ' . implode('; ', $problems));
        }

        return (array) $repair->content;
    }

    /**
     * Persist the validated struct: sections JSON, rebuilt child rows,
     * canonical HTML render, title/date backfill, status ready.
     */
    public function persist(Meeting $meeting, array $sections): void
    {
        $meeting->update(['progress_stage' => 'assembling']);

        /*
         * Completion state (status/completed_at/completed_by) is human
         * workflow, not generator output, so it must survive the rebuild
         * below. Refs are the identity users see (A1, A2), and they are
         * stable whenever this persist was triggered by anything other than
         * a regeneration of the action items section itself. When that
         * section IS regenerated the items may genuinely change, and
         * carrying the tick only where the ref still exists is the honest
         * best effort — a renamed or renumbered item comes back open.
         */
        $completedByRef = $meeting->actionItems()
            ->done()
            ->get(['ref', 'completed_at', 'completed_by'])
            ->keyBy('ref');

        // Rebuild typed projections from scratch — they are derived data.
        $meeting->decisions()->forceDelete();
        $meeting->actionItems()->forceDelete();

        foreach (array_values($sections['decisions'] ?? []) as $i => $decision) {
            $meeting->decisions()->create([
                'ref' => $decision['ref'] ?? ('D' . ($i + 1)),
                'decision' => $decision['decision'] ?? '',
                'made_by' => $decision['made_by'] ?? null,
                'rationale' => $decision['rationale'] ?? null,
                'conditions' => $decision['conditions'] ?? null,
                'impact' => $decision['impact'] ?? null,
                'sort' => $i,
            ]);
        }

        foreach (array_values($sections['action_items'] ?? []) as $i => $item) {
            $ref = $item['ref'] ?? ('A' . ($i + 1));
            $carried = $completedByRef->get($ref);

            $meeting->actionItems()->create([
                'ref' => $ref,
                'status' => $carried ? ActionItem::STATUS_DONE : ActionItem::STATUS_OPEN,
                'completed_at' => $carried?->completed_at,
                'completed_by' => $carried?->completed_by,
                'description' => $item['description'] ?? '',
                'owner' => $item['owner'] ?? '[Not specified]',
                'due_date' => $item['due_date'] ?? null,
                'success_criteria' => $item['success_criteria'] ?? null,
                'dependencies' => $item['dependencies'] ?? null,
                'priority' => in_array($item['priority'] ?? null, ['high', 'medium', 'low'], true)
                    ? $item['priority']
                    : 'medium',
                'collaborators' => $item['collaborators'] ?? null,
                'sort' => $i,
            ]);
        }

        $info = $sections['meeting_info'] ?? [];
        $date = $this->parseDate($info['date'] ?? null);

        $meeting->update([
            'sections' => $sections,
            'rendered_html' => $this->renderer->render($sections),
            'title' => $meeting->title ?: ($info['title'] ?? null),
            'meeting_date' => $meeting->meeting_date ?: $date,
            'status' => Meeting::STATUS_READY,
            'progress_stage' => null,
            'error' => null,
        ]);

        $this->reindexForSearch($meeting);
    }

    /**
     * Refresh this meeting's search index rows.
     *
     * Called from persist() so it covers every path that changes the document: initial
     * generation, a hand edit, and an accepted section regeneration. Hooking it here
     * rather than at each call site is what stops one of those three quietly going
     * unindexed.
     *
     * Failures are reported and swallowed. The minutes are saved by this point, and a
     * search index is derived data — losing a row means one document is briefly missing
     * from search (recoverable with `php artisan search:reindex`), whereas throwing here
     * would fail a request whose real work already succeeded.
     */
    protected function reindexForSearch(Meeting $meeting): void
    {
        try {
            // Reloaded because the indexer reads the typed decision and action rows that
            // persist() just rebuilt; a stale relation would index the previous set.
            $this->searchIndexer->index($meeting->fresh(['transcript', 'decisions', 'actionItems']));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Regenerate one section against the transcript + current minutes.
     * Returns the proposed section value — caller stores it as a
     * proposal; nothing is applied until the user accepts.
     */
    public function regenerateSection(Meeting $meeting, string $section): mixed
    {
        if (! in_array($section, MinutesSchema::SECTIONS, true)) {
            throw new GenerationException("Unknown section '{$section}'.");
        }

        $template = $this->activeTemplate('minutes.regenerate_section');
        $sectionSchema = MinutesSchema::full()['properties'][$section];

        $response = $this->llm->structured(
            'regenerate_section',
            $template->body,
            "SECTION TO REGENERATE: {$section}\n\n"
                . "CURRENT FULL MINUTES (JSON):\n"
                . json_encode($meeting->sections, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                . "\n\nSOURCE TRANSCRIPT:\n\n" . $meeting->transcript->raw_text,
            [
                'type' => 'object',
                'required' => [$section],
                'properties' => [$section => $sectionSchema],
            ],
            $meeting->id,
            $template->id,
        );

        $value = ((array) $response->content)[$section] ?? null;

        if ($value === null) {
            throw new GenerationException("Model response did not contain '{$section}'.");
        }

        return $value;
    }

    protected function userContext(Meeting $meeting): string
    {
        $hints = array_filter([
            'title' => $meeting->title,
            'meeting date' => $meeting->meeting_date?->toDateString(),
        ]);

        if ($hints === []) {
            return '';
        }

        return "USER-PROVIDED METADATA (authoritative where the transcript is silent):\n"
            . collect($hints)->map(fn ($v, $k) => "- {$k}: {$v}")->implode("\n")
            . "\n\n";
    }

    /**
     * Split on line boundaries with overlap so no fact straddles a cut
     * invisibly.
     *
     * @return string[]
     */
    public function chunk(string $text): array
    {
        if (mb_strlen($text) <= self::CHUNK_CHARS) {
            return [$text];
        }

        $chunks = [];
        $offset = 0;
        $length = mb_strlen($text);

        while ($offset < $length) {
            $slice = mb_substr($text, $offset, self::CHUNK_CHARS);

            if ($offset + self::CHUNK_CHARS < $length) {
                $lastBreak = mb_strrpos($slice, "\n");

                if ($lastBreak !== false && $lastBreak > self::CHUNK_CHARS * 0.5) {
                    $slice = mb_substr($slice, 0, $lastBreak);
                }
            }

            $chunks[] = $slice;
            $offset += max(mb_strlen($slice) - self::CHUNK_OVERLAP_CHARS, 1);

            if ($offset >= $length - self::CHUNK_OVERLAP_CHARS && $chunks !== [] && $offset < $length) {
                // Tail smaller than the overlap — it is already covered.
                break;
            }
        }

        return $chunks;
    }

    protected function activeTemplate(string $name): PromptTemplate
    {
        return PromptTemplate::active($name)
            ?? throw new GenerationException("No active prompt template '{$name}' — check Admin → Prompt Templates.");
    }

    protected function parseDate(?string $raw): ?string
    {
        if (! $raw || str_contains($raw, 'Not specified')) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
