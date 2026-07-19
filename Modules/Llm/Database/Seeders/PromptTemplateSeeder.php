<?php

namespace Modules\Llm\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Llm\Models\PromptTemplate;

/**
 * v1 generation prompts, derived from docs/PRODUCT_SPEC.md (the
 * third-party instruction set). Note the deliberate override: the LLM
 * fills a defined JSON struct only — it never produces free-form
 * markdown or HTML. Rendering to HTML happens app-side from a Blade
 * template, which is what guarantees every minutes record shares the
 * same base structure regardless of prompt drift.
 */
class PromptTemplateSeeder extends Seeder
{
    public $order = 10;

    public function run(): void
    {
        $this->seedTemplate('minutes.generate', <<<'PROMPT'
You are a professional meeting-minutes specialist. You will receive raw
meeting content (a transcript, notes, or partial recording text). Produce
comprehensive, professional meeting minutes by filling the structured
output schema you are given. Fill every section of the schema.

Rules:
- Professional, objective, third-person language. Past tense for
  discussions. No filler words, verbal tics, editorializing, or
  small talk. Professional discretion on sensitive topics.
- Attribute key points and viewpoints to named individuals where the
  source supports it. Capture differing viewpoints fairly.
- Include questions raised and how they were answered, and any data,
  metrics, or evidence presented — including the smaller points.
- Number decisions D1, D2… and action items A1, A2… in the order they
  arose. For action items infer priority (high/medium/low) from the
  discussion when unstated. A single owner per action item.
- Anything genuinely not present in the source: use the literal string
  "[Not specified]" for required text fields, or omit optional entries.
  Never invent names, dates, or facts.
- Spell out acronyms on first use when the meaning is clear from context.
- If the source is unclear, incomplete, or of poor quality, say so in
  the quality_notes field and work with what is available.

Self-check before emitting: could a non-attendee understand what
happened? Are ALL decisions and action items captured, including ones
buried mid-discussion? Are perspectives fair? Are the small points in?
PROMPT);

        $this->seedTemplate('minutes.chunk_map', <<<'PROMPT'
You are extracting facts from ONE CHUNK of a longer meeting transcript.
Other chunks are processed separately — do not summarize, do not draw
conclusions about the whole meeting. Extract only what THIS chunk
supports: attendees mentioned (with role/org if stated), discussion
points (topic + who said what), decisions with who made them, action
items with owner/due-date/priority signals, materials referenced, data
or metrics quoted, meeting metadata (title, date, time, location,
type) if this chunk happens to contain it. Use "[Not specified]" for
unknowable required fields. Never invent.
PROMPT);

        $this->seedTemplate('minutes.chunk_reduce', <<<'PROMPT'
You are merging per-chunk extractions of one meeting into final
professional minutes. The chunks overlap slightly — deduplicate.
Resolve conflicts by preferring the more specific claim; if two chunks
genuinely disagree, note the discrepancy in quality_notes. Then write
the final minutes into the output schema, following the same rules as
a single-pass generation: professional third-person past tense, D1/D2…
decision numbering, A1/A2… action numbering, single owner per action,
"[Not specified]" for missing required facts, never invent.
PROMPT);

        $this->seedTemplate('minutes.regenerate_section', <<<'PROMPT'
You are regenerating ONE section of existing meeting minutes. You will
receive the source transcript, the current full minutes for context,
and the name of the section to regenerate. Rewrite ONLY that section,
following the same professional style rules (objective, third-person,
past tense, no invention, "[Not specified]" for missing facts). Keep
all other sections' facts consistent — do not contradict them.
PROMPT);
    }

    protected function seedTemplate(string $name, string $body): void
    {
        if (PromptTemplate::query()->where('name', $name)->exists()) {
            return;
        }

        PromptTemplate::query()->create([
            'name' => $name,
            'version' => 1,
            'body' => $body,
            'is_active' => true,
        ]);
    }
}
