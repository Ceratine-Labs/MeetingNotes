# NoteFiend

*(Repository is still named MeetingNotes; the product name lives in `APP_NAME` and is
a working name for now.)*

Paste or upload a meeting transcript; get professional, structured minutes — the same
nine canonical sections every time (see [docs/PRODUCT_SPEC.md](docs/PRODUCT_SPEC.md)).
Edit or regenerate individual sections with diff-accept, export to DOCX / PDF /
Markdown.

Multi-tenant SaaS: customers register themselves, work inside organisation
workspaces, and subscribe to tiered plans metered on generations per month. A separate
back office manages workspaces, payments, plans and the LLM configuration.

## Stack

Laravel 13 + PostgreSQL, house HMVC (`Modules/` with `module.json` auto-discovery —
see [CLAUDE.md](CLAUDE.md)), Tabler (Bootstrap 5) with a light default and deep dark
theme, vanilla JS, mpdf, PHPWord, spatie/laravel-backup, Paystack.

**No bundler and no CDN.** Every stylesheet, script and font is a file in this
repository served by the application — no Vite, no Tailwind, no `npm install` on the
deploy target. See [docs/VENDOR_ASSETS.md](docs/VENDOR_ASSETS.md).

Every minutes record is a **defined struct**: the LLM fills a validated JSON schema
(`Modules/Minutes/Support/MinutesSchema.php`), and the typed `decisions` /
`action_items` rows plus the canonical HTML render (`meetings.rendered_html`) are
derived from it by the app. The LLM never produces HTML.

## Setup

```bash
composer install
cp .env.example .env && php artisan key:generate
# set DB_* (PostgreSQL) in .env
# APP_NAME is the product name shown throughout the UI and in emails.
# Leave BACKUP_ARCHIVE_NAME alone — it is deliberately decoupled from APP_NAME so a
# rename cannot orphan existing backup archives.
createdb meetingnotes
php artisan migrate --force
php artisan seed:master          # seeds run ONCE, tracked in seed_registry

# Create your back-office account (there is no self-registration for staff)
php artisan admin:create --name="Your Name" --email="you@example.com"

php artisan serve
php artisan queue:work           # REQUIRED — generation runs on the queue
```

Then:

1. Sign in at **`/admin/login`** (back office — separate from the customer login).
2. Set your LLM API key in **LLM providers** and hit *Test connection*.
3. Register a customer account at **`/register`** to use the product itself.

### Upgrading an existing single-tenant install

The application was single-tenant before the SaaS conversion. After migrating:

```bash
php artisan saas:backfill --dry-run   # report what would change
php artisan saas:backfill             # give legacy users a workspace, attach their meetings
```

Idempotent, so it is safe in a deploy script. Until it runs, legacy meetings are
**invisible** rather than exposed — the organisation scope matches an exact id, and
`NULL` matches no workspace.

## Enabling payments

Billing is off by default; the product runs entirely on the free plan until it is
switched on.

```bash
# .env
BILLING_ENABLED=true
PAYSTACK_SECRET_KEY=sk_test_xxxxx
PAYSTACK_PUBLIC_KEY=pk_test_xxxxx
```

Then, per paid plan, **push it to Paystack** from `/admin/plans` — until a plan has a
`paystack_plan_code` it cannot be subscribed to. Point Paystack's webhook at
`https://your-domain/webhooks/paystack`.

Full detail, including the gotchas around changing a live price:
[docs/BILLING.md](docs/BILLING.md).

## Paths

| Path | Who |
|---|---|
| `/` `/pricing` `/how-it-works` `/terms` `/privacy` | public — the only indexable pages |
| `/register` `/login` `/forgot-password` | customer sign-up and sign-in |
| `/app/*` | the product, inside a workspace |
| `/admin/*` | back office, separate guard and table |
| `/webhooks/paystack` | Paystack only (HMAC-verified) |

## Seed master

`php artisan db:seed` delegates here — nothing ever seeds twice.

```bash
php artisan seed:master            # run pending seeders only
php artisan seed:master --status   # executed vs pending ledger
php artisan seed:master --force=FQCN   # explicit single re-run (audited)
```

A modified already-run seeder is warned about and skipped — write a v2 seeder class
instead. That is why you will see `CoreMenuV2Seeder`, `BillingMenuV2Seeder` and friends.

## Scheduler (backups)

Daily backups are configured in the back office under **Backups** and need the
scheduler cron on the host:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

Restore procedure: [docs/RESTORE.md](docs/RESTORE.md) — deliberately manual, no
one-click restore.

## Demo data and the fake LLM

Everything in the product can be exercised before an LLM key exists:

```bash
# A browsable demo workspace: verified user, unlimited subscription, four
# meetings in every state, action items open and done. Rerunning converges
# on the same state. Refuses to run in production.
php artisan demo:seed
#   Login: demo@notefiend.test / demo-password-123

# Same, plus switch the LLM provider setting to the in-process fake
# driver: instant, deterministic, schema-valid generations with no
# network and no cost. Also refuses production.
php artisan demo:seed --fake-llm

# Back to a real provider afterwards: Admin -> LLM providers, or reseed
# settings. The 'fake' provider never appears in the admin dropdown.
```

## Tests

```bash
# PHPUnit (in-memory SQLite via phpunit.xml; full run is safe and fast)
php artisan test
php artisan test --filter=SomeTest   # or just the test you touched

# Browser E2E (Playwright, own SQLite DB at database/e2e.sqlite, seeds
# itself with demo:seed --fake-llm; never touches your dev database)
cd e2e
npm install
npx playwright install chromium      # once per machine; CI does this itself
npm test
# Machine already has a Chromium? Skip the download:
#   CHROMIUM_PATH=/path/to/chromium npm test
```

CI (`.github/workflows/ci.yml`) runs both suites on every push and pull request.

## Documentation

| Document | Read it when |
|---|---|
| [docs/SAAS_ARCHITECTURE.md](docs/SAAS_ARCHITECTURE.md) | touching tenancy, auth, or the module graph |
| [docs/BILLING.md](docs/BILLING.md) | touching anything that handles money |
| [docs/ADMIN.md](docs/ADMIN.md) | working on the back office |
| [docs/SEARCH.md](docs/SEARCH.md) | touching workspace search or the navbar search box |
| [docs/THEMING.md](docs/THEMING.md) | changing the look, or adding a layout or asset |
| [docs/VENDOR_ASSETS.md](docs/VENDOR_ASSETS.md) | adding or upgrading a front-end library |
| [docs/PRODUCT_SPEC.md](docs/PRODUCT_SPEC.md) | changing what the generator produces |
| [docs/RESTORE.md](docs/RESTORE.md) | restoring from a backup |
| [BUILD_PLAN.md](BUILD_PLAN.md) | history of what was built, and what is deferred |

Deploy samples (nginx, supervisor queue worker): [deploy/](deploy/).
