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

| Phase | Scope | Status |
|---|---|---|
| **0 — Scaffold** | Laravel 13, Ceratine-pattern module system, Auth module, Core settings service, **seed master**, base layout, git init | **done** 2026-07-19 |
| **1 — Llm module** | Manager + 3 drivers, admin LLM settings UI with test-connection, prompt templates (seeded from spec), generation_runs log | **done** 2026-07-19 |
| **2 — Minutes core** | Meetings/transcripts models, extractors (txt/md/docx/pdf), GenerateMinutesJob incl. chunking, structured-output schema, Library + New + Workspace UIs with progress polling | **done** 2026-07-19 |
| **3 — Edit & regen** | Per-section edit (validated JSON), section regenerate with diff-accept | **done** 2026-07-19 |
| **4 — Exports** | DOCX, PDF (mpdf), Markdown/clipboard | **done** 2026-07-19 |
| **5 — Backup module** | spatie/laravel-backup + admin UI + schedule + failure mail | **done** 2026-07-19 |
| **6 — Polish/QA** | Live smoke, cache-poisoning fix, README + deploy samples, 47 feature tests, QA log in PM | **done** 2026-07-19 (live-LLM smoke pending an API key) |

### Phase 7 — SaaS conversion (2026-07-30)

Converted from a single-tenant internal tool into a multi-tenant SaaS. Decisions taken
with Ryan at the start of the phase:

| Question | Decision |
|---|---|
| What owns a subscription? | **Organisation workspaces** — a user may belong to several; the subscription and quota sit on the workspace. Retro-fitting tenancy later is painful, and it is what B2B minutes buyers expect. |
| What do tiers meter on? | **Generations per month**, plus a seat limit and feature flags per tier. Maps directly to LLM cost and is easy for a customer to predict. |
| How do people get in? | **Permanent free tier, no card** (3 generations/month). Registration therefore works with Paystack unconfigured. |

| Epic | Delivered |
|---|---|
| **A — UI + assets** | Tabler 1.4.0 vendored into `public/vendor` with SweetAlert2, Tom Select, ApexCharts, IntroJS and self-hosted Inter. **Every CDN link removed; Vite and Tailwind deleted entirely** — no bundler, no Node on the deploy target. Light-default / deep-dark theme pair, resolved server-side so there is no flash of the wrong theme. New app / guest / marketing / admin shells. |
| **B — Public face + auth** | `Site` module: landing, how-it-works, pricing (rendered from the plans table), terms, privacy. Registration, login, password reset, email verification, profile. Honeypot + per-endpoint rate limiting. |
| **C — Tenancy** | `Tenancy` module: organisations, membership roles (owner/admin/member), invitations with hashed tokens, org switcher, seat limits. `OrganisationScope` throws rather than falling back to unfiltered results. `BindOrganisation` job middleware so a long-lived worker cannot inherit the previous job's tenant. |
| **D — Billing** | `Billing` module: plans, Paystack gateway behind a `PaymentGateway` interface, checkout with server-side verification, HMAC-verified idempotent webhooks, subscription lifecycle with grace period, generation metering enforced in the service layer. |
| **E — Back office** | `Admin` module: separate `admins` table, `admin` guard, `/admin/login`, own password broker. Workspaces, users, plans, payments, subscriptions, webhook replay, append-only audit log, audited impersonation. The LLM and Backup screens moved here off the legacy `users.role` gate, which was then dropped. |
| **G — Workspace search** | New `Search` module: a maintained `search_documents` index over transcripts, minutes sections, decisions and action items, with PostgreSQL full-text search (generated `tsvector`, GIN + trigram indexes) and a LIKE fallback for SQLite. Navbar search box with a 1.5 s debounce that resets on each keystroke, results grouped by meeting, plus a filterable full-results page and `php artisan search:reindex`. Navbar re-laid out: search left, identity pinned right. |
| **F — Sweep + docs** | Every method given parameter and return types; decorative banner comments replaced with docblocks that explain intent; dead legacy admin code removed. New docs: `SAAS_ARCHITECTURE.md`, `BILLING.md`, `ADMIN.md`, `THEMING.md`, `VENDOR_ASSETS.md`. |

Two real bugs were found and fixed by the test pass, both of which would have hit
production:

1. **Route-model binding ran before the tenant was bound.** Laravel prioritises
   `SubstituteBindings` after `auth`, but middleware not in that priority list keeps its
   declared position — which put `organisation` *after* binding. Every
   `/app/minutes/{meeting}` request would have resolved a tenant-owned model with no
   organisation bound, and the scope would have thrown. Fixed by inserting
   `EnsureOrganisation`, `EnsureOrganisationRole` and `AuthenticateAdmin` into the
   priority list before `SubstituteBindings` (`bootstrap/app.php`).
2. **The dashboard and profile routes were behind `auth` only, not `organisation`.** Both
   render the full app shell, so the sidebar, workspace switcher and usage meter all
   rendered blank on the app's own landing page.

A third, smaller one: `AdminUser::is_active` relied on the database default, so a
freshly created in-memory instance had it as `null` and `canAuthenticate()` read that as
"not active". Now defaulted on the model — a security-relevant boolean must never be
null anywhere.

Verified end to end against a running app: 30 pages returning 200 across public,
customer and back-office surfaces; registration creating a workspace, owner membership
and a free subscription; the verification gate blocking a first generation; seat limits
biting on the free plan; guard separation in both directions; and cross-workspace reads
blocked by primary key.

Still open at the end of the phase:

1. **Live Paystack smoke test** — the whole flow is built and unit-verified, but no
   real transaction has been put through. Needs test keys.
2. **Legal review** — `terms.blade.php` and `privacy.blade.php` are accurate about what
   the code does, but have not been reviewed by a lawyer, and POPIA requires a named
   Information Officer (`config('site.contact_email')` is a placeholder).
3. **Scheduled erasure of deleted accounts** — the privacy policy commits to erasure
   after `SITE_DATA_RETENTION_DAYS`; there is no job doing it yet. The wording says
   "will be retained… then erased" rather than implying an automated process.
4. **Feature test coverage is partial.** 69 tests pass, including a dedicated
   `TenancyIsolationTest` (cross-workspace reads, stale pointers, role gates) and
   `SearchTest`. Billing has no phpunit coverage yet — the Paystack gateway, webhook
   idempotency and quota rollover are verified by reading and by live probes, not by
   tests. That is the largest remaining gap.
5. **Search results are keyboard-navigable only one level deep** — Down-arrow enters the
   list, but arrow-cycling between results is not implemented.

Deferred (post-v1, in rough priority order): audio transcription
(Whisper API or local), OCR for scanned PDFs, email-in ingestion,
action-item push to the Ceratine PM, multi-tenancy/teams, 2FA.

## 11. Assumptions (proceeding on these unless overridden)

1. ~~**Multi-user, single-tenant** — one org's users share a library;
   roles are just admin/user. Multi-tenant is a v2 concern.~~
   **Superseded 2026-07-30 (Phase 7):** now multi-tenant with organisation
   workspaces, per-workspace roles (owner/admin/member), and a separate back-office
   guard. See `docs/SAAS_ARCHITECTURE.md`.
2. **Audio is out of scope for v1** — the spec mentions recordings, but
   transcription is a separate pipeline; text inputs cover the core.
3. **Deploy target** matches Ryan's other apps (VPS, nginx + php-fpm +
   Postgres, Horizon under supervisor). Postgres assumed.
4. Registered in the PM under **Internal** until the actual client/
   customer is named.

## 12. Open questions for Ryan

1. Who is the "someone" asking for this — billable client work? Affects
   PM customer and priority. **Multi-tenancy is no longer waiting on this** — it
   shipped in Phase 7.
2. Is audio-in genuinely needed for v1, or is text-only acceptable to
   ship first?
3. ~~nwidart modules or a port of the Ceratine bespoke module structure?~~
   **Resolved 2026-07-19:** Ceratine's bespoke structure, ported (see §1).
