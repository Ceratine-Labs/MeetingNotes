# SaaS architecture

How MeetingNotes is put together as a multi-tenant SaaS. Read this before touching
tenancy, auth or the module graph.

## The shape

```
Public (no auth)                Customer app (/app)            Back office (/admin)
─────────────────               ───────────────────            ────────────────────
Site module                     Core   — shell, theme          Admin module
  /            landing          Auth   — login, register        separate guard
  /pricing     from DB plans    Tenancy— workspaces             separate table
  /how-it-works                 Billing— plans, quota           separate broker
  /terms /privacy               Minutes— the product itself
```

## Module graph

Modules are discovered from `Modules/*/module.json` and registered by
`CoreServiceProvider` in **priority order** (lower boots first). Priority is not
decoration — it encodes real dependencies.

| Priority | Module | Depends on | Why that position |
|---|---|---|---|
| 0 | **Core** | — | Base model, module registry, seed master, settings, layouts, theme. Must boot first. |
| 5 | **Tenancy** | Core | `OrganisationContext` must be a registered singleton before any model using `BelongsToOrganisation` boots, or the global scope resolves a fresh empty context per query. |
| 10 | **Auth** | Core | Users, registration, login. Creates organisations, so it needs Tenancy's services available at call time (not boot time). |
| 25 | **Billing** | Core, Auth, Tenancy | Overrides one of Tenancy's container bindings and listens to its events, so Tenancy must be registered first. |
| 30 | **Minutes** | Core, Auth, Llm, Tenancy, Billing | Asks Billing for permission before generating. |
| 20 | **Llm** | Core | Providers, prompts, run log. |
| 40 | **Backup** | Core | spatie/laravel-backup wrapper. |
| 90 | **Site** | Core, Billing | Renders pricing from Billing's plans, so Billing must be booted. |
| 95 | **Admin** | all | Reports on and manages every other module's data. |

`Site` is not called `Public` because `public` is a PHP reserved word —
`Modules\Public\...` is not a legal namespace.

## Tenancy: the organisation boundary

An **organisation** (called a "workspace" in the UI) is the unit that owns data and
holds a subscription. A user belongs to one or more through `organisation_user` and
acts within exactly one at a time.

### How isolation is enforced

```
Request → auth → organisation middleware → OrganisationResolver
                                              ↓ binds
                                        OrganisationContext (singleton)
                                              ↑ read on every query
       Meeting::query()  ←  OrganisationScope adds WHERE organisation_id = ?
```

Three pieces, and each has a job:

1. **`BelongsToOrganisation`** (trait) — applied to a tenant-owned model. Adds the
   global scope, and stamps `organisation_id` on insert from the bound context.
2. **`OrganisationScope`** — adds the `WHERE` clause. **Throws
   `MissingOrganisationContextException` if a web request reaches tenant data with
   nothing bound**, rather than returning unfiltered rows. A route registered outside
   the `organisation` middleware therefore fails loudly in development instead of
   leaking one customer's minutes to another in production. Console, queue and test
   code legitimately span tenants and the scope stands down there.
3. **`OrganisationContext`** — a singleton holding the current organisation. The most
   security-relevant object in the codebase.

Escaping the scope deliberately is `Model::withoutOrganisationScope()`. The name is
verbose on purpose: a reader of the calling code should see immediately that isolation
was switched off intentionally. It is for the back office and console commands.

### Only the aggregate root is scoped

`meetings` carries `organisation_id`. `transcripts`, `decisions` and `action_items`
do **not** — they are reached only through a meeting. Duplicating the column down the
tree would create rows that can disagree with their parent, which is a worse failure
than the join it saves.

### Queued jobs are the sharp edge

A queue worker is a long-lived process handling jobs for many customers in sequence,
and `OrganisationContext` is a singleton. A job that does not bind its own tenant
**inherits whatever the previous job left behind** — a cross-tenant leak with no HTTP
request anywhere near it, and one that every test running a single job in isolation
would pass.

So any job touching tenant data must:

```php
class SomeJob implements ShouldQueue, TenantAwareJob
{
    public function __construct(public string $meetingId, public string $organisationId) {}

    public function organisationId(): string { return $this->organisationId; }

    public function middleware(): array { return [new BindOrganisation]; }
}
```

`BindOrganisation` binds before `handle()` and unbinds in a `finally`, so a throwing
job cannot leave a tenant bound for the next one.

Note `failed()` runs *after* the middleware has unbound, so error-recording queries
there must use `withoutOrganisationScope()`.

### Roles

`organisation_user.role` is `owner | admin | member`, a strict hierarchy
(`Membership::atLeast()`).

| | member | admin | owner |
|---|---|---|---|
| Create/edit minutes | ✓ | ✓ | ✓ |
| Manage members, workspace settings | | ✓ | ✓ |
| Billing, plan changes, delete workspace | | | ✓ |

"Runs the workspace" and "controls the money" are deliberately different jobs — an
office manager can administer members without seeing card details.

Route protection: `organisation.role:admin` / `organisation.role:owner`.

## Two separate identity systems

| | Customers | Back office |
|---|---|---|
| Table | `users` | `admins` |
| Guard | `web` | `admin` |
| Login | `/login` | `/admin/login` |
| Reset broker | `users` | `admins` |
| Reset token table | `password_reset_tokens` | `admin_password_reset_tokens` |
| Self-registration | yes | **never** — `php artisan admin:create` |
| Password minimum | 10 chars, letters + numbers | 12 chars, + symbols |
| Middleware | `auth` | `admin.auth` |

Nothing is shared, and that is the point: **a privilege-escalation bug in the
customer auth path gets an attacker a customer account and stops there.** There is no
`is_admin` column for a mass-assignment mistake to flip, because the column does not
exist. The legacy `users.role` flag was dropped in
`Modules/Auth/Database/Migrations/v1__02_drop_users_role.php` for exactly that reason
— a dormant privilege column nothing checks is a standing hazard.

The cost is that staff who also want to use the product need two accounts. For a
two-person company that is the right trade.

## Registration flow

```
POST /register
  ↓ RegisterRequest       validate, honeypot, password strength
  ↓ RegistrationService   ── transaction ──┐
  │    create User                          │
  │    create Organisation (owner member)    │  or: accept invitation,
  └──────────────────────────────────────────┘      join existing workspace
  ↓ OrganisationCreated event  (after commit)
  ↓ Billing: ProvisionFreeSubscription
  ↓ Registered event → verification email
  ↓ log in, bind workspace, redirect to /app/dashboard
```

Two things worth knowing:

- **Registration works with Paystack unconfigured.** The free plan needs no payment
  call, so the product can be deployed and used before billing credentials exist.
- **Invited users get no workspace of their own.** Someone joining their employer's
  account should not also end up owning a stray empty workspace that follows them
  around in the switcher forever.

## Email verification

Verification does **not** gate signing in — a new customer can look around
immediately. It gates the **first generation**, which is the expensive, abusable
action. The `verified` middleware sits on `POST /app/minutes` and
`POST /app/minutes/{id}/retry` and nowhere else.

## Where the rules live

| Concern | Enforced in | Not in |
|---|---|---|
| Tenant isolation | `OrganisationScope` (global scope) | controllers |
| Generation quota | `MinutesGenerator` (service) | controllers — queued and retried work must be metered too |
| Seat limit | `SeatGuard` + `StoreInvitationRequest` | — |
| Feature flags | `FeatureGate` | hardcoded plan checks |
| Workspace roles | route middleware + `FormRequest::authorize()` | views (views only *hide* things) |

The pattern: **enforce in the service layer, hide in the view.** A view that hides a
button is a courtesy; the service refusing the action is the security boundary.

## Adding a tenant-owned model

1. `organisation_id` (uuid, nullable, indexed) on the table. No DB foreign key
   (house hard rule #1).
2. `use BelongsToOrganisation;` on the model.
3. Add `organisation_id` to `$fillable` — the trait stamps it, but the backfill
   command and back office assign it explicitly.
4. Register the route inside a group carrying the `organisation` middleware.
5. If a job touches it, implement `TenantAwareJob` and add the `BindOrganisation`
   middleware.

## Migrating pre-SaaS data

The application was single-tenant before this. `php artisan saas:backfill` gives every
legacy user a workspace and attaches their meetings to it. Idempotent, so it is safe
in a deploy script, and `--dry-run` reports without writing.

Until it runs, legacy meetings are **invisible** rather than exposed — the scope
matches an exact id and `NULL` matches no workspace.

## Related documents

- `docs/BILLING.md` — plans, Paystack, quota, webhooks
- `docs/ADMIN.md` — the back office
- `docs/THEMING.md` — light/dark and the asset pipeline
- `docs/VENDOR_ASSETS.md` — why there is no bundler
- `docs/PRODUCT_SPEC.md` — the received requirements defining the nine sections
