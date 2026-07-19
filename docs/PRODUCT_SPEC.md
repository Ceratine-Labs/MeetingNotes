# Product Spec — Meeting Minutes Generator (as received)

> Received 2026-07-19 from a third party via Ryan. This is the source
> requirements document AND the seed content for the v1 generation prompt
> template. It is written as an LLM instruction set; we treat the output
> structure it defines as the product's canonical minutes schema.

## Output structure the generator must produce

1. **Meeting Information** — title/subject, date + time (start–end,
   duration), location (physical/virtual), meeting type
   (regular/ad-hoc/strategic/emergency), primary objective,
   chair/facilitator.
2. **Attendance** — Present (name, title, org/department),
   Absent/Apologies (with reason), Guests/External (with affiliation).
3. **Discussion Summary** — per agenda item: topic heading, 2–4 paragraph
   narrative, key points attributed to individuals, differing viewpoints,
   questions raised + answers, data/metrics presented, unresolved issues
   flagged for follow-up.
4. **Decisions & Resolutions** — numbered D1, D2…: the decision, who
   made/approved it, rationale, conditions/caveats, expected impact.
5. **Action Items** — numbered A1, A2…: description, single owner,
   due date, success criteria, dependencies, priority (High/Medium/Low,
   inferred if unstated), collaborators if mentioned.
6. **Parking Lot & Deferred Items** — tabled topics with reason, ideas
   needing research, captured off-topic items.
7. **Supporting Materials** — documents/reports/presentations referenced,
   key data points, links, distributed materials.
8. **General Discussion** — on-purpose topics that don't warrant a main
   section but matter for a comprehensive record.
9. **Next Steps** — next meeting (date/time/purpose), interim
   checkpoints, communication plan, items to monitor.

## Style rules from the spec

- Professional, objective, third-person; past tense for discussions.
- Bullet lists; tables for action items; bold key terms/names.
- No filler words, verbal tics, editorializing, or small talk.
- Note unclear/incomplete transcripts; mark missing info "[Not specified]".
- Spell out acronyms on first use where meaning is clear.
- Professional discretion on sensitive topics.

## Self-check before finalizing (bake into the prompt)

- Could a non-attendee understand what happened?
- Are all decisions and action items documented?
- Well-organized, easy to scan?
- Perspectives captured fairly? Smaller points captured?

## Inputs mentioned by the spec

Transcripts, recordings/audio, and notes. (Audio transcription is a
phase-2 concern — see BUILD_PLAN.md.)
