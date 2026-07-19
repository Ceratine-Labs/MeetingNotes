# Project: MeetingNotes (MINUTES)
Customer: Internal | Type: application
Description: Meeting Minutes Generator — paste/upload a transcript, LLM generates structured professional minutes per the third-party spec (9 canonical sections). Laravel HMVC (Ceratine bespoke module pattern), seed master with execution registry, admin-configurable LLM providers, admin backup interface. Minutes stored as defined SQL struct + canonical rendered HTML.

## Shell variables — set these once, then all examples below work as-is
```bash
# Token file is PER SURFACE (v1.9.44) so parallel apps never strand each
# other on rotation: VSCode/Claude Code reads ~/dispatch-token.vscode.md,
# Cowork reads ~/dispatch-token.cowork.md, and anything without a surface
# file falls back to the shared ~/dispatch-token.md. Fail loud if neither
# exists instead of masquerading as a rotation/auth error on the first curl.
SURFACE="${DISPATCH_SURFACE:-vscode}"
TOKEN_FILE="$HOME/dispatch-token.$SURFACE.md"
[ -f "$TOKEN_FILE" ] || TOKEN_FILE="$HOME/dispatch-token.md"
TOKEN="$(grep -v '^#' "$TOKEN_FILE" 2>/dev/null | grep -v '^[[:space:]]*$' | tail -n 1 | tr -d '[:space:]')"
[ -z "$TOKEN" ] && echo "ERROR: $TOKEN_FILE missing or empty — mint a surface token in PM Settings or run \`php artisan dispatch:refresh\` on prod"
BASE="https://projects.ceratine-labs.co.za/api/v1"
PROJECT="019f78eb-6671-713a-b3aa-4648daf81ba4"
```
On token rotation: call `POST $BASE/me/refresh-token`, save `data.token` as the
last line of YOUR surface's token file (`$TOKEN_FILE` above). Rotation
deactivates only the calling token — tokens for other surfaces keep working,
so a VSCode rotation can no longer strand a Cowork session or vice versa.

## ⚠️ Rules (non-negotiable)

- **Every minute tracked.** Start timer before ANY work — reading, grepping, investigation. Cannot mark done without a time entry (422).
- **QA before done.** Every `feature`/`bug`/`improvement`/`task` needs a QA entry before `status=done` (422 otherwise; `investigation`/`subtask` exempt). Evidence, not vibes.
- **Model routing.** Tasks carry `llm_model` (`fable` = default core developer, `opus`, `sonnet`, `haiku`). Only work tasks tagged for YOUR model — pass `llm_model` to claim-next so you never claim across the line; if a directly-assigned task is tagged for another model, say so instead of working it.
- **Verify timer at every meaningful step.** Ghost sessions (timer dropped silently) are the #1 billing failure. `GET $BASE/time/running?project_id=$PROJECT` before/after each major step.
- **Investigation-first.** Ad-hoc request from Ryan → create `type=investigation` parent + start its timer FIRST, then investigate, then decompose.
- **Outcomes are mandatory.** Every done task needs `outcome`: what changed (file paths), how verified, caveats, commit shas.
- **Audit before marking done.** Check the task hasn't already been done by a previous session or commit. Grep + git log + test it.
- **Max 5 running timers per project.** 6th attempt returns 422. Stop one first.
- **No bulk-complete.** `POST /tasks/bulk-update` rejects `status:done`. Use single `PUT /tasks/{id}` with outcome + QA + stopped timer.
- **No text TODOs.** Every TODO becomes a task in the project manager, not a code comment.
- **No deploy without Ryan's explicit go-ahead.** State what/where/why, wait for yes.
- **Finish what you start.** Pick one task. Work it to done. Then pick the next. Half jobs are worse than not starting.
- **No API access in this environment? See "When the API is unreachable" below** — repo-tracked fallback, then reconcile. Don't fake compliance and don't stall.

## 🔌 When the API is unreachable (no token file in this environment)

Some sessions run in sandboxes without the token file, network access to the PM, or push credentials. The rules above assume a fully-wired environment; when yours isn't:

- **Do the work anyway.** Never stall or skip a task because the PM can't be reached.
- **Keep the audit trail in the repo instead:** commit messages carry the outcome detail a task `outcome` would; PM-bound task/QA records go into a tracked file (the `pm_response.md` pattern).
- **Say "PM sync pending" in your summary** so Ryan or a token-bearing session can reconcile tasks, timers, and KB docs afterwards.
- **Commit locally even if push fails** (see the project CLAUDE.md → source-control rules) — a credentialed session pushes next and history replays cleanly.

## 🧭 Working principles (how to think when the rules run out)

- **"Runs and passes tests" is the floor, not the ceiling.** Code that compiles, reads well, and passes review can still be structurally wrong — hallucinated methods, dormant load-time fatals, logic that solves a nearby-but-different problem. Where the repo has `composer check`, running it is part of the work, not a favour to the next session.
- **Fix causes, not symptoms.** The gate flags your new code → fix the code (or the model's `@property` docs). A doc is stale → fix the doc, then ask what let it drift. Never silence a check to reach done.
- **Graduation rule.** Every incident leaves the system stronger: mechanical lessons become checks (grep / phpstan rules), judgment lessons become CLAUDE.md prose, decisions-with-rationale become KB docs. A lesson that lives only in the chat transcript is a lesson lost.
- **Pointers over copies.** Never create a second copy of a source of truth — copies drift, always. Reference the canonical location instead. (A stale CLAUDE.md snapshot in the PM once cost a full review to unwind.)
- **Prove, don't presume.** Prefer the cheapest deterministic probe over confidence: a `ReflectionClass` load to prove a fatal is gone, a response header to prove a deploy landed, a grep to prove a rename left nothing behind.
- **Dormant ≠ dead.** A class nothing loads yet can hide a fatal that detonates the day a customer first touches the feature. That's what static analysis is for — finding it first.
- **Unknowns go to Ryan as a short list — never silently guessed.** If a fact or property can't be verified, table it and ask. An honest "unsure" costs a minute; an invented value costs an incident.

## 🪪 Your identity

You authenticate as the **"Claude Code" agent user** — not Ryan. Comments, QA, decisions, deployments, push logs are attributed to "Claude Code" in the UI. `GET $BASE/me` returns both `data.agent` (you) and `data.owner` (Ryan). Default task assignee is Ryan's UUID (auto-filled by server). Personal endpoints (`/notes`, `/bookmarks`, `/reminders`, `/meetings`, `/time/running`, `/today`) read/write against Ryan's row — intentional so his dashboard reflects agent work. When Ryan replies to your comment, find it via `GET $BASE/tasks/awaiting-reply`.


## ⏱️ Session lifecycle

### 1. Orient
```bash
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/me"
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/projects/$PROJECT/install-checks"
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/projects/$PROJECT/tasks/today"   # running timer + overdue + due today + in-progress + next workable (cap 10, ?limit=N max 50)
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/time/running?project_id=$PROJECT"  # data=null → safe; data non-null → another session timing, pick different task
```

### 2. Pick or create a task

#### Preferred: atomic claim (race-safe)
```bash
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"note":"Picked up — investigating","skip_ids":[],"priority_floor":"medium","llm_model":"fable"}' \
  "$BASE/projects/$PROJECT/tasks/claim-next"
```
Returns `data.task` (null if nothing available) + `data.time_entry` (timer already started).

#### Investigation-first (ad-hoc request from Ryan — not already a tracked task)
```bash
# 1. Create investigation parent and start its timer IMMEDIATELY:
INV=$(curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d "{\"title\":\"Investigate X\",\"description\":\"Ryan asked: ...\",\"project_id\":\"$PROJECT\",\"priority\":\"high\",\"type\":\"investigation\",\"status\":\"in_progress\"}" \
  "$BASE/tasks" | jq -r '.data.id')
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"note":"Investigating"}' "$BASE/tasks/$INV/time/start"

# 2. Investigate (read code, check KB, fetch reference_url, download attachments).

# 3. Decompose into subtasks:
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d "{\"title\":\"Subtask title\",\"project_id\":\"$PROJECT\",\"parent_id\":\"$INV\",\"type\":\"subtask\",\"priority\":\"high\",\"status\":\"todo\"}" \
  "$BASE/tasks"

# 4. Stop parent timer, start first subtask timer, work it to done (5a).
curl -s -X POST -H "Authorization: Bearer $TOKEN" "$BASE/tasks/$INV/time/stop"

# 5. When all subtasks done: synthesise parent outcome + mark parent done.
curl -s -X PUT -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"status":"done","outcome":"Root cause: ... Subtask 1 fixed X (commit abc1234). Subtask 2 added tests. No follow-ups."}' \
  "$BASE/tasks/$INV"
```

#### Specific task by ID
```bash
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"note":"Picked up — investigating"}' "$BASE/tasks/TASK_ID/time/start"
```

#### Create a new task
Fill all 4 context fields — a task without them wastes the next session's warmup:
```bash
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d "{\"title\":\"Title\",\"description\":\"What and why\",\"reference_url\":\"...\",\"instructions\":\"Handover note for next session\",\"project_id\":\"$PROJECT\",\"priority\":\"medium\",\"type\":\"task\",\"status\":\"in_progress\"}" \
  "$BASE/tasks"
```
With image attachment: use `-F` fields instead of JSON; add `-F "reference_image=@/tmp/file.png"`.

#### Task pickup ritual (under running timer)
```bash
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/tasks/TASK_ID"
```
In order: read `instructions` → open `reference_url` → download each `attachments[]` (auth-protected: `curl -s -H "Authorization: Bearer $TOKEN" -o /tmp/NAME "$download_url"`) → read `description`.

**Already-done audit:** grep for symbols/paths from task description, check `git log`, test the behaviour. If already done: outcome = "Verified implemented in commit X — tested via Y. No code changes." then mark done.

#### 🔴 Timer verification — every meaningful step
```bash
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/time/running?project_id=$PROJECT"
```
`data.task.id` must be YOUR task. If null or different task: log comment "Timer dropped — restarting", restart, verify again.

#### Subtask decomposition
```bash
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d "{\"title\":\"Step 1\",\"project_id\":\"$PROJECT\",\"parent_id\":\"PARENT_ID\",\"type\":\"subtask\",\"priority\":\"medium\",\"status\":\"todo\"}" \
  "$BASE/tasks"
```
Stop parent timer before starting subtask timer. Parent `actual_hours` rolls up child time.

#### Triage queue — push stragglers/unknowns to Ryan (organic finds only, not scans)
```bash
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d "{\"title\":\"What you noticed\",\"note\":\"file:line — why it stood out. Not blocking.\",\"project_id\":\"$PROJECT\"}" \
  "$BASE/inbox"
```

#### Stuck loop — max 5 attempts at same approach, then pause (5b)
```bash
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"commentable_type":"task","commentable_id":"TASK_ID","body":"STUCK after 5 attempts.\nApproach: ...\nLast error: ...\nHypothesis: ...\nRecommendation: ...","is_internal":true}' \
  "$BASE/comments"
```

### 3. Timer already running from step 2.

### 4. During work — leave a trail
```bash
# Checkpoint comment (is_internal keeps it off customer portal)
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"commentable_type":"task","commentable_id":"TASK_ID","body":"Checkpoint: ...","is_internal":true}' "$BASE/comments"

# Architectural decision
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"title":"...","decision":"...","rationale":"..."}' "$BASE/projects/$PROJECT/decisions"

# Push log
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"branch":"main","commit_sha":"abc1234","commit_message":"..."}' "$BASE/tasks/TASK_ID/push-logs"

# Follow-up / triage to inbox
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d "{\"title\":\"...\",\"note\":\"...\",\"project_id\":\"$PROJECT\"}" "$BASE/inbox"

# Personal note
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"title":"...","body":"..."}' "$BASE/notes"
```

### 5a. Completion (happy path)
```bash
# 1. QA entry (media[] optional, images/video ≤50 MB)
curl -s -X POST -H "Authorization: Bearer $TOKEN" \
  -F "title=Manual smoke test" -F "status=passed" -F "report=..." -F "media[]=@/tmp/after.png" \
  "$BASE/tasks/TASK_ID/qa"

# 2. Stop timer
curl -s -X POST -H "Authorization: Bearer $TOKEN" "$BASE/tasks/TASK_ID/time/stop"

# 3. Outcome + done (specific: file paths, how verified, caveats, commit shas)
curl -s -X PUT -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"status":"done","outcome":"Refactored auth middleware in app/Http/Middleware/Authenticate.php — pulled duplicate role check into guard(). 4 feature tests added (tests/Feature/AuthMiddlewareTest.php), all passing. Pushed abc1234. PaymentProcessor.php has same pattern — captured to inbox."}' \
  "$BASE/tasks/TASK_ID"

# 4. Notify Ryan
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"commentable_type":"task","commentable_id":"TASK_ID","body":"Task complete. Outcome logged.","is_internal":true}' "$BASE/comments"
```

Done means: code committed, tests pass, QA logged (status=passed), timer stopped, outcome filled, status=done. Missing any → not done. The server enforces outcome + time entry + QA with 422s (`investigation`/`subtask` exempt from QA) — the rest is on you.

### 5b. Blocked / paused
```bash
curl -s -X POST -H "Authorization: Bearer $TOKEN" "$BASE/tasks/TASK_ID/time/stop"
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"commentable_type":"task","commentable_id":"TASK_ID","body":"BLOCKED. What blocks. What tried. Next step.","is_internal":true}' "$BASE/comments"
# Status stays in_progress.
```

### 5c. Read-only session
```bash
curl -s -X POST -H "Authorization: Bearer $TOKEN" "$BASE/tasks/TASK_ID/time/stop"
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"commentable_type":"task","commentable_id":"TASK_ID","body":"Read-only: walked X and Y. No code changes.","is_internal":true}' "$BASE/comments"
```

### 5d. Loop back to step 2 if more work; else proceed to 6.

### 5e. Deploy — ALWAYS confirm with Ryan first (state commit/env/what, wait for yes)
```bash
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d "{\"title\":\"Push vX.Y to staging\",\"environment\":\"staging\",\"status\":\"success\",\"note\":\"...\"}" \
  "$BASE/projects/$PROJECT/deployments"
```
Environments: `staging` `live`. Statuses: `in_progress` `success` `failed` `rolled_back`.

### 6. Session end
```bash
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/time/running?project_id=$PROJECT"  # must be null
# ONLY if X-Token-Refresh-Suggested was true this session (threshold 500 — rare):
curl -s -X POST -H "Authorization: Bearer $TOKEN" "$BASE/me/refresh-token"
# → data.token = new token; write it as the last line of $TOKEN_FILE (your surface's
#   file). Only the calling token is deactivated — other surfaces are untouched.
#   The counter is advisory: skipping rotation never kills auth mid-session.
```

**Stale timer rule:** only stop a timer on this project if: (1) `data` non-null, (2) `data.task.project_id == $PROJECT`, (3) `data.task.status` is `done|cancelled|backlog`, AND (4) `data.minutes_ago > 120`. If task is still in_progress, post a comment asking Ryan to confirm — never stop a timer you didn't start.

---

## API Reference

- **Base**: `$BASE` | **Token**: `$TOKEN` | **Project**: `$PROJECT`
- **Locations**: local=/home/ryan/Development/MeetingNotes (ceratine) | live=not configured | staging=not configured

### Tasks
```bash
# Today's workload (preferred for picking up work)
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/projects/$PROJECT/tasks/today"
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/projects/$PROJECT/tasks/today?limit=25"

# Backlog (planning only — unfiltered, don't use for pickup)
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/projects/$PROJECT/tasks/backlog"
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/projects/$PROJECT/tasks/backlog?include_closed=1"

# Filter: ?status= ?priority= ?due_today=1 ?due_tomorrow=1 ?due_on=YYYY-MM-DD
#         ?due_after= ?due_before= ?overdue=1 ?open=1 ?count_only=1 ?limit=N
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/tasks?project_id=$PROJECT&status=todo"
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/tasks?project_id=$PROJECT&overdue=1"

# Awaiting Ryan's reply (most-recent comment from human, not agent)
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/tasks/awaiting-reply?project_id=$PROJECT"

# Create / update
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d "{\"title\":\"Title\",\"description\":\"...\",\"reference_url\":\"...\",\"instructions\":\"...\",\"project_id\":\"$PROJECT\",\"priority\":\"medium\",\"type\":\"task\",\"status\":\"todo\"}" \
  "$BASE/tasks"
curl -s -X PUT -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"status":"in_progress"}' "$BASE/tasks/TASK_ID"

# Bulk update (status/priority/due_date — cannot set status=done)
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"tasks":[{"id":"UUID_A","status":"in_progress"},{"id":"UUID_B","priority":"high","due_date":"2026-04-20"},{"id":"UUID_C","due_date":null}]}' \
  "$BASE/tasks/bulk-update"

# Claim next (atomic — use instead of GET+PUT+start)
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"note":"Picked up","skip_ids":[],"priority_floor":"medium"}' \
  "$BASE/projects/$PROJECT/tasks/claim-next"
```
Statuses: `backlog → todo → in_progress → review → done` | Priorities: `low medium high critical`
Types: `investigation feature bug improvement task subtask` | `sort_order`: integer, lower = earlier in today queue
Models (`llm_model`, v1.9.45): `fable` (default — core developer) `opus` `sonnet` `haiku`. Claim-next accepts `llm_model` and only returns matching tasks (NULL counts as fable).

### Time tracking
```bash
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"note":"Working on X"}' "$BASE/tasks/TASK_ID/time/start"   # idempotent; multiple timers can run in parallel
curl -s -X POST -H "Authorization: Bearer $TOKEN" "$BASE/tasks/TASK_ID/time/stop"
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"minutes":30,"note":"Offline work"}' "$BASE/tasks/TASK_ID/time"  # manual entry, min 5 min
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/time/running?project_id=$PROJECT"
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/tasks/TASK_ID/time"   # totals incl. subtask rollup
```

### Comments
```bash
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"commentable_type":"task","commentable_id":"TASK_ID","body":"...","is_internal":true}' "$BASE/comments"
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/comments?commentable_type=task&commentable_id=TASK_ID"
```

### Decisions
```bash
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"title":"...","decision":"...","rationale":"..."}' "$BASE/projects/$PROJECT/decisions"
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/projects/$PROJECT/decisions"
curl -s -X DELETE -H "Authorization: Bearer $TOKEN" "$BASE/projects/$PROJECT/decisions/DECISION_ID"
```

### QA / Test reports
```bash
# status: pending passed failed blocked | media[]: images+video ≤50 MB each
curl -s -X POST -H "Authorization: Bearer $TOKEN" \
  -F "title=Smoke test" -F "status=passed" -F "report=..." -F "media[]=@/tmp/after.png" \
  "$BASE/tasks/TASK_ID/qa"
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/tasks/TASK_ID/qa"
curl -s -X DELETE -H "Authorization: Bearer $TOKEN" "$BASE/tasks/TASK_ID/qa/QA_ID"
```

### Files
```bash
# attachable_type: project task customer partner project_completion
curl -s -X POST -H "Authorization: Bearer $TOKEN" \
  -F "file=@/path/to/file" -F "attachable_type=task" -F "attachable_id=TASK_UUID" "$BASE/files"
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/files?attachable_type=project&attachable_id=$PROJECT"
curl -L -O -H "Authorization: Bearer $TOKEN" "$BASE/files/FILE_ID/download"
```

### Install checks
```bash
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/projects/$PROJECT/install-checks"
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"checks":{"server_reachable":{"ok":true,"note":"ssh ok"}}}' "$BASE/projects/$PROJECT/install-checks"
```
Valid keys: `url_set` `server_reachable` `env_matches` `repository_cloned` `api_token_present` `claude_md_present`

### JIRA (bidirectional, explicit — nothing syncs automatically)
```bash
curl -s -X POST -H "Authorization: Bearer $TOKEN" "$BASE/projects/$PROJECT/jira/tasks/TASK_ID/push"
curl -s -X POST -H "Authorization: Bearer $TOKEN" "$BASE/projects/$PROJECT/jira/tasks/TASK_ID/pull"
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"body":"Comment"}' "$BASE/projects/$PROJECT/jira/tasks/TASK_ID/comments"
curl -s -X POST -H "Authorization: Bearer $TOKEN" "$BASE/projects/$PROJECT/jira/sync"
```

### Personal capture
```bash
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"title":"...","body":"..."}' "$BASE/notes"
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"url":"...","title":"...","tag":"client","username":"...","password":"..."}' "$BASE/bookmarks"
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"title":"...","starts_at":"2026-04-09 10:00:00","ends_at":"2026-04-09 11:00:00","event_type":"meeting","project_id":"$PROJECT"}' "$BASE/meetings"
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"title":"...","remind_at":"2026-04-08 14:00:00","project_id":"$PROJECT"}' "$BASE/reminders"
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/notes"
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/bookmarks"
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/meetings"
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/reminders"
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/inbox?project_id=$PROJECT"
```

### CLAUDE.md backup (encrypted at rest server-side)
```bash
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/projects/$PROJECT/claude-md"
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d "{\"content\": $(jq -Rs . < CLAUDE.md)}" "$BASE/projects/$PROJECT/claude-md"
```

## Test Journeys

Project-level, immutable run records. Types: `unit` `feature` `browser` (agent-created); `user` (Ryan only, via UI).
Rules: (1) each run is immutable — don't update a failed journey, create a new one; (2) failures spawn fix tasks; (3) re-runs link via `parent_journey_id`; (4) repeat until `status=passed`.

**ONE test at a time.** `php artisan test --filter='TestClass::method'` — never run a whole suite.
**DB cleanup:** tests must delete their own rows in `tearDown()`. No `RefreshDatabase` — it wipes the seeded demo state. Use `TEST-` prefix on test-created records for easy tinker cleanup.

```bash
# Create a journey with all results in ONE POST:
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{
    "type":"feature","title":"Invoice CRUD tests",
    "command":"php artisan test --filter=InvoiceTest (one at a time)",
    "results":[
      {"name":"InvoiceTest::test_can_create","description":"POST /invoices creates a row","status":"passed","duration_ms":45},
      {"name":"InvoiceTest::test_returns_404","description":"GET /invoices/missing returns 404","status":"failed","duration_ms":22,
       "error_message":"Expected 404, got 500","file":"tests/Feature/InvoiceTest.php","line":68}
    ]
  }' "$BASE/projects/$PROJECT/test-journeys"

# Spawn a fix task for each failed result:
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"title":"Fix 404 handling in InvoiceController","description":"...","priority":"high","type":"bug","result_id":"RESULT_UUID"}' \
  "$BASE/test-journeys/JOURNEY_ID/spawn-task"

# Rerun after fixes (auto-copies type/title/command from parent):
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"results":[...]}' "$BASE/test-journeys/PREVIOUS_JOURNEY_ID/rerun"

# List / show / delete
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/projects/$PROJECT/test-journeys"
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/test-journeys/JOURNEY_ID"
curl -s -X DELETE -H "Authorization: Bearer $TOKEN" "$BASE/test-journeys/JOURNEY_ID"
```

Result fields: `name`✅ `description` `status`✅ (passed/failed/skipped) `duration_ms` `error_message` `file` `line`.

## Knowledge Base

Scan index first (no doc bodies, cheap), then fetch only relevant entries. Read before non-trivial code; write after touching documented behaviour.

- **Read rule:** `GET $BASE/projects/$PROJECT/docs/index` → scan descriptions/tags → `GET .../docs/DOC_ID` for each relevant entry.
- **Write rule:** changed documented behaviour → PUT the doc; built something new worth explaining → POST new doc; trivial change → skip. Once a doc is set it's set — there is no staleness check or periodic verify.
- **Never create `auth_v2.md`** — update `auth.md`. Versioning is in git.
- **Description must be "when to read" sentence** (10–500 chars). Lazy descriptions ("Notes about auth.") are rejected by the API.

```bash
# Index (no content field — cheap)
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/projects/$PROJECT/docs/index"
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/projects/$PROJECT/docs/index?tags=auth,session"
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/projects/$PROJECT/docs/tags"

# Full doc
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/projects/$PROJECT/docs/DOC_ID"

# Create/upsert (upserts by title — POST is preferred over PUT)
# Required: title (≤255, upsert key), description (10–500 "when to read"), content (≤200k), tags (1–10, lowercase+hyphens)
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"title":"Auth flow","description":"Read before touching login or middleware — explains why lookups iterate-and-decrypt.","tags":["auth","session"],"content":"# Auth flow\n...","git_sha":"abc1234","related_task_id":"TASK_ID"}' \
  "$BASE/projects/$PROJECT/docs"

# Update by id (use POST/upsert instead when you have the title)
curl -s -X PUT -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"content":"...updated...","git_sha":"def5678"}' "$BASE/projects/$PROJECT/docs/DOC_ID"

# Archive (soft delete — stops appearing in index)
curl -s -X DELETE -H "Authorization: Bearer $TOKEN" "$BASE/projects/$PROJECT/docs/DOC_ID"
```

## Journey — Milestones + Epics (v1.9.34)

The hierarchy above raw tasks is **Project → Milestone → Epic → Task → Subtask**. Each layer is optional. Use them when the work calls for it:

- **Milestone** — a multi-deliverable arc (e.g. "MVP Launch", "v2 cutover"). Use when the ask spans more than one distinct epic. Most asks do NOT need a milestone.
- **Epic** — a single multi-task arc that shares a goal (e.g. "Auth rewrite", "Build webmin clone"). Use when a single ask decomposes into 3+ parent tasks. Optional `milestone_id` to nest it under a milestone.
- **Task / Subtask** — the existing layer. Tasks can have `epic_id` (and/or `milestone_id`) set to attach them to the journey.

### When to mint one

- Ryan asks for a big thing that obviously breaks down (e.g. "build our own webmin") → **create one Epic** at the start, then add tasks with `epic_id` set. Set Epic status to `in_progress` when the first task starts.
- Ryan flags a multi-month initiative that contains several epics (e.g. "v2 rewrite — auth, billing, dashboards, mobile") → **create one Milestone**, then mint child Epics under it as each chunk gets scoped.
- One-off task with no obvious siblings → **don't create either**. Just create the task.
- Mid-stream realisation that an in-flight task belongs to a larger arc → mint the Epic now and PATCH `epic_id` onto the task.

### Lifecycle

- New Milestone/Epic ships with `status=open`. Move to `in_progress` when first child task starts. Move to `completed` when every child task is `done` (or `cancelled` if abandoned).
- `completed_at` is auto-stamped by the API when status transitions to `completed`/`cancelled` — don't set it manually.
- Deleting a Milestone detaches its child Epics (sets `milestone_id=NULL`); the epics survive. Same for deleting an Epic — its child tasks survive with `epic_id=NULL`.

### API

```bash
# Full Journey tree — Milestones → Epics → Parent tasks → Subtasks,
# ordered by completion timestamp so the response reads as the project's
# narrative arc. One call returns everything.
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/projects/$PROJECT/journey"

# Milestones — list / show / create / update / delete
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/projects/$PROJECT/milestones"
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"title":"MVP Launch","description":"All blocking work for the public beta.","due_date":"2026-09-30","color":"#6aa5e0"}' \
  "$BASE/projects/$PROJECT/milestones"
curl -s -X PUT -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"status":"in_progress"}' "$BASE/projects/$PROJECT/milestones/MILESTONE_ID"
curl -s -X DELETE -H "Authorization: Bearer $TOKEN" "$BASE/projects/$PROJECT/milestones/MILESTONE_ID"

# Epics — same shape, optional milestone_id nests under a milestone
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/projects/$PROJECT/epics"
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/projects/$PROJECT/epics?milestone_id=UUID&status=in_progress"
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"title":"Auth rewrite","milestone_id":"MILESTONE_ID","description":"Replace the v1 login chain.","due_date":"2026-07-15"}' \
  "$BASE/projects/$PROJECT/epics"
curl -s -X PUT -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"status":"completed"}' "$BASE/projects/$PROJECT/epics/EPIC_ID"
curl -s -X DELETE -H "Authorization: Bearer $TOKEN" "$BASE/projects/$PROJECT/epics/EPIC_ID"

# Attach a task to an epic at create time
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"title":"Build login form","project_id":"'$PROJECT'","epic_id":"EPIC_ID","priority":"high"}' \
  "$BASE/tasks"

# Move a task into / out of an epic later
curl -s -X PUT -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"epic_id":"EPIC_ID"}' "$BASE/tasks/TASK_ID"
curl -s -X PUT -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"epic_id":null}' "$BASE/tasks/TASK_ID"
```

Statuses (milestones + epics): `open → in_progress → completed | cancelled`.

## Integrations
### GitHub Repos
None configured

### JIRA
None configured

## Project Resources / SQL Queries / Commands
None