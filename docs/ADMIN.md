# The back office

The SaaS admin area for Ryan and his partner. `/admin`, separate guard, separate table.

## Getting in

```bash
php artisan admin:create --name="Ryan Cruickshank" --email="ryan@ceratine-labs.co.za"
# omit --password to be prompted, or to have a 20-char one generated and shown once
```

Then sign in at `/admin/login`.

There is deliberately **no self-registration and no "invite an admin" UI**. Adding staff
is a shell command run by someone who already has server access, which is a smaller
attack surface than any web flow. It is also the recovery path: with server access, a
new account can always be created.

`AdminUserSeeder` creates the first account through the seed master, using
`ADMIN_SEED_EMAIL` / `ADMIN_SEED_PASSWORD`. With no `ADMIN_SEED_PASSWORD` set it
generates one and prints it **once**. There is no hardcoded default password and there
must never be one.

## Why a separate table

See `docs/SAAS_ARCHITECTURE.md` → "Two separate identity systems". The short version: a
privilege-escalation bug in the customer auth path gets an attacker a customer account
and stops there. There is no `is_admin` column for a mass-assignment mistake to flip,
because the column does not exist.

Staff who also want to use the product as a customer need two accounts. For a two-person
company that is the right trade.

## Screens

| Path | What it does |
|---|---|
| `/admin` | Revenue, counts, plan mix, recent payments, **webhooks that failed to apply**, recent staff activity |
| `/admin/organisations` | Customer workspaces; search, subscription state, usage |
| `/admin/organisations/{id}` | One workspace in detail + manual plan change |
| `/admin/users` | Customer accounts; search, verification state, impersonation |
| `/admin/plans` | Price / quota / seat / feature editor; push a plan to Paystack |
| `/admin/subscriptions` | Defaults to **payment-failed**, because those have a grace period ticking down |
| `/admin/payments` | Every attempt, filterable; raw provider payload per payment |
| `/admin/webhooks` | Inspect and **replay** payment webhooks |
| `/admin/audit` | Append-only log of what staff did |
| `/admin/llm`, `/admin/prompts`, `/admin/runs` | LLM providers, prompt templates, generation log |
| `/admin/backups` | Backup management |

The LLM and Backup screens moved here from `/app/admin/*` during the SaaS conversion —
they were gated on the old `users.role` flag, and they are staff tools, not customer
features.

## What staff can and cannot see

**Cannot: transcript or minutes content.** The back office shows that a workspace
generated 40 sets of minutes; it does not let anyone read them. Support does not require
reading a customer's board minutes, and the privacy policy promises workspace isolation.

Staff who genuinely need to see a document **impersonate**, which leaves an audit record
with a stated reason. The friction is the feature.

## Audit log

Every consequential action is recorded in `admin_audit_log`: sign-ins (including
**failures** — a run of them on this endpoint is a signal, not noise), plan edits,
manual plan changes, impersonation, webhook replays.

Append-only by design:

- extends `Model` directly, not `BaseModel` — no soft deletes, because a deletable audit
  log defeats the purpose;
- `UPDATED_AT = null` — a row is a fact about a moment and is never modified;
- read-only in the UI. No delete, no edit, no bulk action.

The actor's email is stored on the row *alongside* the id. That duplication is
deliberate: the log has to stay readable after an account is deactivated or renamed. It
is a snapshot of who acted, not a pointer that can change under the record.

Audit writes never block the action they describe — a failed log insert is reported and
swallowed. Losing a plan change because the log insert failed would be worse.

## Impersonation

The sharpest tool here. Order of operations matters and is not incidental:

1. **audit** (with a required reason) — so the record exists even if something fails
   midway
2. log the admin **out** of the back office
3. log in as the customer, regenerate the session

There is no "impersonate and keep your admin session" path, on purpose. Holding both
sessions invites acting on the wrong one, and forcing a fresh sign-in afterwards keeps
impersonation feeling like the deliberate act it is.

## Manual plan changes

`/admin/organisations/{id}` can move a workspace onto any plan, with a required reason
that goes into the audit log. For a comped account, a bespoke deal, or a compensating
fix.

**It does not touch Paystack.** If the customer has a live Paystack subscription it
keeps billing them until cancelled there separately. The UI says so, because silently
cancelling someone's recurring payment as a side effect of a support action is a much
worse surprise than an explicit second step.

## Plan editing

Editing a plan **never applies retroactively** — existing subscriptions carry their own
entitlement snapshot. See `docs/BILLING.md`.

Two things the editor will tell you about, both easy to get wrong:

- A **paid plan with no Paystack plan code** cannot be checked out (the charge would be
  taken once and never renew). The customer-facing button is disabled with the reason.
- **Changing a price does not change it at Paystack.** Push the plan again; existing
  Paystack subscriptions keep their original amount until the customer resubscribes.

Blank quota / seat fields mean **unlimited**, not zero. The form says so, because they
are opposites.

## Renaming the product

`APP_NAME` is the product name and appears throughout the UI, page titles and outgoing
email. Views read `config('app.name')` rather than hardcoding it, so changing it is a
one-line change.

**`BACKUP_ARCHIVE_NAME` is deliberately separate.** spatie/laravel-backup keys its
archive folder off that value, and it defaults to `MeetingNotes`. If it followed
`APP_NAME`, renaming the product would silently start writing backups to a new folder
and the admin backup list would stop showing every archive taken before the rename.
Only change it if you are also moving the existing archives.

## Security notes

- Login is throttled to 3/min per email+IP and 10/min per IP — tighter than the customer
  login. Two legitimate users, so a real person hitting the limit is a rare
  inconvenience while anyone else is being slowed down.
- Password minimum is 12 characters with a symbol, versus 10 for customers. These
  accounts can read every customer's billing data.
- Reset links expire in 30 minutes, versus Laravel's default 60.
- The `admin.auth` middleware re-checks `is_active` on **every** request, so
  deactivating an admin takes effect immediately rather than whenever their session
  expires.
- Deactivate rather than delete — the audit log keeps a name to point at.
- Guard separation is verified: a customer session redirected to `/admin` lands on
  `/admin/login`, and an admin session hitting `/app/dashboard` lands on `/login`.
