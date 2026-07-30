# Workspace search

Search across everything in a workspace — transcripts, minutes sections, decisions and
action items — from one box in the navbar. Typing a person's name finds every meeting
they appear in, including where they are only named in the raw transcript.

## Why an index table

A useful search has to cover four differently-shaped things at once: transcripts (long
text), minutes sections (nested JSON), and decisions and action items (separate tables).
Doing that live means a UNION plus JSON extraction on every keystroke-triggered request.

So everything searchable is denormalised into `search_documents`, one row per searchable
part of a meeting. That turns search into a single indexed query.

The index is **derived data**. It is rebuilt from the meeting itself, never edited
directly, and losing the whole table costs nothing but a reindex:

```bash
php artisan search:reindex          # rebuild from every meeting
php artisan search:reindex --fresh  # clear first (also removes orphans)
php artisan search:reindex --organisation=<uuid>
```

## Document types

One row per searchable part, each with a weight that breaks relevance ties. Decisions
and action items outrank prose, because someone searching a meeting archive usually
wants "what did we decide about X", not "where was X mentioned".

| Type | Weight | Body indexed |
|---|---|---|
| `meeting` | 5 | Title, date (in several formats — people search by date), meeting type, chair, location, objective |
| `decision` | 10 | The decision, who made it, rationale, conditions, impact |
| `action_item` | 20 | Description, **owner**, due date, success criteria, dependencies |
| `minutes_section` | 40 | One row per populated section (attendance, discussion, next steps…) |
| `transcript` | 60 | The raw meeting text |

Indexing the owner and the attendance list is what makes a person-name search work.
The `meeting` row is weighted highest so typing a meeting's name ranks the meeting
itself above a discussion paragraph that merely mentions it.

## Two engines

**PostgreSQL** (production) — a generated `tsvector` column with a GIN index, queried
with `websearch_to_tsquery` and ranked by `ts_rank`. That gives stemming, so "budgets"
matches "budget". `websearch_to_tsquery` rather than `to_tsquery` because it understands
quoted phrases and `-exclusions`, and never throws on odd punctuation — which matters
for input we do not control.

Full-text search only matches whole lexemes, so a **second ILIKE arm** catches partial
words: without it, "Mar" would return nothing until the user finished typing "Maria",
which is precisely wrong for a search-as-you-type box. `pg_trgm` indexes make that arm
fast; if the extension cannot be installed the migration logs it and carries on, with
prefix search degrading to a sequential scan rather than breaking.

The `tsvector` is a **generated** column rather than trigger-maintained, so it cannot
drift from `body`.

**SQLite** (tests) — LIKE matching ordered by weight. Genuinely worse (no stemming, no
ranking); a deliberate trade so the wiring is testable without requiring PostgreSQL.
`SearchTest` therefore only asserts behaviour both engines share.

## Tenant isolation

`SearchDocument` uses `BelongsToOrganisation`, so every query is confined to the current
workspace by the global scope. There is no `where organisation_id` in `SearchService` or
`SearchController` — and no way to forget one. `SearchTest` asserts both directions of
the boundary.

## The navbar box

- **Debounce: 1.5 s, resetting on every keystroke.** A continuous typist triggers
  exactly one request, when they stop. The interval comes from
  `config('search.debounce_ms')` (`SEARCH_DEBOUNCE_MS`) and is served to the browser as
  a data attribute, so tuning it needs no JS edit.
- **In-flight requests are aborted** when a newer one starts, so a slow earlier response
  cannot land after a newer one and show stale results.
- **Results are grouped by meeting.** A meeting often matches several ways at once, and
  eight dropdown rows pointing at the same meeting is a useless dropdown. One entry per
  meeting, with the best-matching hit as the preview and a "6 matches" badge.
- **Snippets are extracted at read time**, centred on the match, so the excerpt reflects
  what the user actually typed rather than a pre-computed opening.
- **Everything is rendered with `textContent`, never `innerHTML`.** Snippets come from
  meeting transcripts — user-supplied content that must never be interpreted as markup.
- Enter goes straight to the full results page without waiting out the debounce; Escape
  closes and returns focus to the input; Down-arrow moves into the results and then
  cycles through them (wrapping at the bottom), and Up from the first result returns to
  the input — where someone pressing Up is almost always trying to edit their search.
- Minimum 2 characters, enforced in both the UI and `SearchService`.

## Keeping the index fresh

`MinutesGenerator::persist()` calls the indexer, which covers every path that changes a
document: initial generation, a hand edit, and an accepted section regeneration. Hooking
it there rather than at each call site is what stops one of those three quietly going
unindexed.

Indexer failures are reported and swallowed — the minutes are already saved by that
point, and a missing index row is recoverable with a reindex, whereas throwing would
fail a request whose real work succeeded.

## Extending it

To index something new, add a `TYPE_*` constant and a `…Documents()` method to
`SearchIndexer`, give it a weight in `SearchDocument::WEIGHTS`, then
`php artisan search:reindex`. The indexer rebuilds per meeting (delete-then-insert), so
there is no incremental-update logic to get wrong.
