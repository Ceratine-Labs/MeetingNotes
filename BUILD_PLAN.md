# MeetingNotes — Build Plan

Meeting Minutes Generator: upload/paste a transcript, get professional
structured minutes (per [docs/PRODUCT_SPEC.md](docs/PRODUCT_SPEC.md)),
edit, export. Admin controls the LLM, the prompt, and backups.

Drafted 2026-07-19. Assumptions and open questions at the bottom —
the plan proceeds on the stated defaults unless Ryan overrides.

---

## 1. Stack decision — RESOLVED at scaffold (2026-07-19)

**Laravel 13 + Bootstrap 5 (dark) + vanilla JS. Bespoke `Modules/` HMVC
ported from `SAAS/ceratine` — no nwidart, no Livewire.**

Checked at scaffold time: Ceratine is Laravel 13 with its own module
system (`module.json` per module, auto-discovery at boot, per-module
ServiceProvider), Bootstrap 5 dark baseline, SweetAlert2/Tom Select,
UUID PKs everywhere, no DB-level foreign keys, DB-driven sidebar via
MenuSeeder/MenuService. MeetingNotes matches all of it — consistency
across the repos beats package purity. The tailwind entry in Ceratine's
package.json is scaffold leftover, not the house style.

Why Laravel over a TypeScript stack (NestJS was the credible alternative):

- The module conventions, seed-master pattern, and admin UI idioms
  exist in-house and were ported, not reinvented.
- Queued jobs are exactly right for long-running LLM generation.
- Every hard requirement has a mature package: spatie/laravel-backup
  (backups), PHPWord (DOCX), mpdf (PDF, house standard), Crypt
  encrypted settings (API keys).
- Solo-dev velocity: one deployable, no separate API/frontend split.

Generation progress in the UI uses polling (status endpoint) instead of
Livewire streaming — simpler, matches the vanilla-JS house style.

## 2. UI experience

Three-screen core flow plus an admin area. Optimized for "paste →
minutes in under a minute → export."

1. **Library** (`/minutes`) — table of all meetings: title, meeting date,
   status chip (draft / processing / ready / failed), model used, word
   count, actions. Search + date filter. Empty state points at "New".
2. **New minutes** (`/minutes/new`) — one card, two tabs:
   - **Paste** — big textarea for transcript/notes.
   - **Upload** — drag-drop for `.txt` `.md` `.docx` `.pdf`
     (audio deferred to phase 6).
   Optional metadata fields (title, meeting date, attendee hints — all
   passed as context to the LLM), advanced disclosure for model override.
   Submit queues the job and redirects to the workspace.
3. **Minutes workspace** (`/minutes/{id}`) — the main screen:
   - While processing: per-stage progress (extracting → chunking →
     generating → assembling) streamed via `wire:stream` / polling.
   - When ready: right pane = minutes rendered section-by-section
     (the 9 spec sections). Each section has **Edit** (inline, saved as
     override) and **Regenerate** (re-runs just that section against the
     transcript; shows old→new diff before accepting).
   - Left pane (collapsible) = source transcript, so claims can be
     checked against the source.
   - Toolbar: export **DOCX / PDF / Markdown**, copy to clipboard,
     re-run full generation.
4. **Admin** (`/admin`) — gated by role:
   - **LLM settings** — provider, models per task, keys, params (§5).
   - **Prompt templates** — versioned editor (§5).
   - **Backups** — list / run / download / schedule (§7).
   - **Users** — invite, role toggle.
   - **System** — seed-registry status, generation-run log with token
     usage and cost estimates.

## 3. Module map (HMVC)

Each module owns its config, migrations, seeders, models, Livewire
components, views, routes, services, and tests.

| Module | Owns |
|---|---|
| **Core** | Base layout/nav, settings service (encrypted key-value), seed master, shared Blade/Livewire UI components, activity log |
| **Auth** | Users, roles (`admin`, `user`), login, invites |
| **Minutes** | Meetings, transcripts, extractors, generation pipeline, workspace UI, section edit/regen, exports |
| **Llm** | Provider drivers, model/param config, prompt templates, generation-run + usage log, admin LLM UI |
| **Backup** | spatie/laravel-backup wrapper, admin backup UI, schedule config |

Dependency direction: Minutes → Llm → Core; Backup → Core; Auth → Core.
No module reaches into another's internals — services/contracts only.

## 4. Seed master

Migrations-style registry so seeds never silently re-run:

- `seed_registry` table: `seeder_class`, `batch`, `checksum`, `executed_at`.
- Each module ships seeders registering into a `SeedMaster` service.
- `php artisan seed:master` — runs only unexecuted seeders (by class
  name), records each with a batch number and file checksum.
- `seed:master --status` — table of executed vs pending.
- `seed:master --force=FQCN` — explicit single re-run (records a new
  batch row; never implicit).
- Checksum mismatch on an already-executed seeder ⇒ warning, no re-run
  (a changed seeder is a new seeder — make a v2 class).
- `DatabaseSeeder` delegates to SeedMaster so `migrate --seed` and
  deploy scripts stay safe/idempotent.

v1 seeded content: default admin user, default LLM settings (Anthropic,
key blank), prompt template v1 (from PRODUCT_SPEC.md), minutes-section
definitions.

## 5. LLM abstraction + admin configurability

**`LlmManager`** (Laravel Manager pattern) behind an `LlmDriver`
contract: `complete()`, `stream()`, `structured(schema)`.

Drivers for v1:
- **Anthropic** (default — Claude, structured output via tool use)
- **OpenAI**
- **OpenAI-compatible** (base URL configurable ⇒ covers Ollama, LM
  Studio, vLLM, OpenRouter, etc. for free)

Build the drivers on `prism-php/prism` if it checks out at scaffold
time (it wraps all three targets), but keep it behind our own contract
so it's swappable.

Admin-configurable (stored in Core settings, keys via encrypted casts —
never in `.env`, never rendered back to the browser once saved):
- Active provider + base URL + API key (per provider)
- Model per task type: `generate_full`, `regenerate_section`,
  `chunk_map` (cheap/fast model), `chunk_reduce`
- Temperature, max output tokens, request timeout
- **Test connection** button — live round-trip with a 1-token ping,
  result shown inline

**Prompt templates** live in DB, versioned (`prompt_templates`:
name, version, body, is_active). The pasted spec seeds v1. Admin edits
create a new version; every generation run records which version it
used. This matters because the spec came from a third party and *will*
change.

**Usage log** (`generation_runs`): meeting id, task type, provider,
model, prompt version, tokens in/out, est. cost, latency, status,
error. Surfaced in admin System page.

## 6. Generation pipeline

```
upload/paste ─ extract text ─ queue job ─ [chunk? map→reduce] ─ structured output ─ persist ─ render
```

1. **Extract** — `.txt`/`.md` direct; `.docx` via PhpOffice/PhpWord
   reader; `.pdf` via `smalot/pdfparser`. Scanned/image PDFs detected
   (near-zero extracted text) ⇒ clear error telling the user OCR isn't
   supported yet (phase 6). Store raw text on `transcripts`.
2. **Job** (`GenerateMinutesJob`, queued, retries=2 with backoff):
   - Token-estimate the transcript. Under budget ⇒ single pass.
   - Over budget ⇒ **map-reduce**: split on speaker-turn/paragraph
     boundaries with overlap; each chunk gets a cheap-model structured
     extraction (facts, decisions, actions, attendees, materials);
     reduce pass merges chunk outputs + generates final minutes.
3. **Structured output** — the LLM fills a JSON schema mirroring the 9
   spec sections (tool-use / JSON mode, validated server-side; one
   repair retry on schema mismatch). Decisions and action items land as
   **real rows**, not just prose — queryable, and exportable to the PM
   later.
4. **Persist** — meeting status → ready; sections JSON + `decisions` and
   `action_items` child rows (D1…/A1… refs preserved).
5. **Section regenerate** — re-runs one section with the transcript +
   current full minutes as context; result offered as a diff, applied
   only on accept.
6. Failures land on the meeting record with a human-readable error and
   a retry button; full detail in `generation_runs`.

## 7. Backups (admin requirement)

`spatie/laravel-backup` — DB dump + `storage/app` (uploaded source
files) to configurable disks (local always; S3-compatible optional,
credentials via admin settings).

Admin UI:
- Backup list: date, size, disk, health check status
- **Run backup now** (queued, progress surfaced)
- Download / delete (delete = confirm modal)
- Schedule config (daily default, time picker) — writes to settings,
  scheduler reads it
- Failure notifications by email (configurable recipient)

Restore is deliberately **not** a button — one-click DB restore over a
live app is a footgun. Instead: download artifact + documented
`docs/RESTORE.md` procedure. Revisit if a real need shows up.

## 8. Data model

All tables: UUID PKs, no DB-level foreign keys, soft deletes (house
hard rules — enforced by Core's BaseModel).

```
users                 id, name, email, password, role, 2fa cols (phase 6)
meetings              id, user_id, title, meeting_date, source_type(paste|file),
                      status(draft|processing|ready|failed), error,
                      sections(json), rendered_html, model_used,
                      prompt_version, timestamps
transcripts           id, meeting_id, raw_text, original_filename,
                      file_path, mime, word_count, token_estimate
decisions             id, meeting_id, ref(D1…), title, decision, made_by,
                      rationale, conditions, impact, sort
action_items          id, meeting_id, ref(A1…), description, owner,
                      due_date, success_criteria, dependencies,
                      priority(high|medium|low), collaborators, sort
prompt_templates      id, name, version, body, is_active
generation_runs       id, meeting_id, task_type, provider, model,
                      prompt_template_id, tokens_in, tokens_out,
                      cost_estimate, latency_ms, status, error
settings              id, key, value(encrypted at rest), group
seed_registry         id, seeder_class, batch, checksum, executed_at
menus                 id, section, label, route_name, icon, required_role,
                      sort, is_active
```

Every minutes record has the same defined base (Ryan's consistency
override on the third-party spec): the structured `sections` JSON is
validated against the canonical 9-section schema, decisions and action
items land as typed child rows (query/export-oriented), and
`meetings.rendered_html` holds the canonical HTML render rebuilt on
every (re)generation or edit — one defined storage point, dynamic
content. The JSON is the source of truth; HTML and child rows are
derived artifacts.

## 9. Exports

- **DOCX** — PhpOffice/PHPWord against a styled template (headings,
  action-item table, bold names — matches spec formatting rules).
- **PDF** — Browsershot (headless Chrome) rendering a print Blade view;
  fallback to dompdf if the deploy target can't run Chrome.
- **Markdown** — straight render of the sections; also powers
  copy-to-clipboard.

## 10. Build phases

| Phase | Scope | Est. |
|---|---|---|
| **0 — Scaffold** | Laravel 12, module system (port Ceratine pattern or nwidart), Tailwind/Livewire, Auth module, Core settings service, **seed master**, base layout, git init | 1 day |
| **1 — Llm module** | Manager + 3 drivers, admin LLM settings UI with test-connection, prompt templates (seeded from spec), generation_runs log | 1–1.5 days |
| **2 — Minutes core** | Meetings/transcripts models, extractors (txt/md/docx/pdf), GenerateMinutesJob incl. chunking, structured-output schema, Library + New + Workspace UIs with streaming progress | 2–3 days |
| **3 — Edit & regen** | Per-section inline edit, section regenerate with diff-accept, full re-run | 0.5–1 day |
| **4 — Exports** | DOCX, PDF, Markdown/clipboard | 0.5–1 day |
| **5 — Backup module** | spatie/laravel-backup + admin UI + schedule + failure mail | 0.5 day |
| **6 — Polish/QA** | Error/empty states, long-transcript + garbage-input tests, feature tests on seed master + pipeline, QA log in PM, deploy | 1 day |

**Total ≈ 6.5–9 working days.**

Deferred (post-v1, in rough priority order): audio transcription
(Whisper API or local), OCR for scanned PDFs, email-in ingestion,
action-item push to the Ceratine PM, multi-tenancy/teams, 2FA.

## 11. Assumptions (proceeding on these unless overridden)

1. **Multi-user, single-tenant** — one org's users share a library;
   roles are just admin/user. Multi-tenant is a v2 concern.
2. **Audio is out of scope for v1** — the spec mentions recordings, but
   transcription is a separate pipeline; text inputs cover the core.
3. **Deploy target** matches Ryan's other apps (VPS, nginx + php-fpm +
   Postgres, Horizon under supervisor). Postgres assumed.
4. Registered in the PM under **Internal** until the actual client/
   customer is named.

## 12. Open questions for Ryan

1. Who is the "someone" asking for this — billable client work? Affects
   PM customer, priority, and whether multi-tenancy moves up.
2. Is audio-in genuinely needed for v1, or is text-only acceptable to
   ship first?
3. ~~nwidart modules or a port of the Ceratine bespoke module structure?~~
   **Resolved 2026-07-19:** Ceratine's bespoke structure, ported (see §1).
